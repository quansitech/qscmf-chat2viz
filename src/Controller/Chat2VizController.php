<?php

namespace Qscmf\Chat2Viz\Controller;

use Gy_Library\GyController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Service\ConversationService;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Service\StreamService;
use Qscmf\Chat2Viz\Sse\MockStreamEmitter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\Chat2Viz\Traits\JsonInputTrait;
use Qscmf\Chat2Viz\Traits\UuidTrait;
use Qscmf\Chat2Viz\Validator\ConversationValidator;

class Chat2VizController extends GyController
{
    use JsonInputTrait;
    use UuidTrait;

    protected string $serviceUrl;
    protected string $apiKey;
    protected Client $httpClient;
    private ?MockStreamEmitter $mockEmitter = null;
    private string $currentDashboardUid = '';

    private ?ConversationService $conversationService = null;
    private ?StreamService $streamService = null;

    protected function _initialize()
    {
        parent::_initialize();

        $this->serviceUrl = rtrim((string) env('CHAT2VIZ_SERVICE_URL', ''), '/');
        $this->apiKey = (string) env('CHAT2VIZ_API_KEY', '');
        $this->httpClient = new Client(['timeout' => 60]);
    }

    /**
     * Lazy-initialize conversation service with adapter-driven repositories.
     */
    private function getConversationService(): ConversationService
    {
        if ($this->conversationService === null) {
            $this->conversationService = new ConversationService(
                AdapterFactory::createConversationRepository(),
                AdapterFactory::createMessageRepository(),
                fn(string $tag, string $detail) => $this->logError($tag, $detail)
            );
        }
        return $this->conversationService;
    }

    /**
     * Lazy-initialize stream service with current config and event router.
     */
    private function getStreamService(): StreamService
    {
        if ($this->streamService === null) {
            $eventRouter = new EventRouter(
                AdapterFactory::createRepository(),
                $this->currentDashboardUid,
                fn(string $tag, string $detail) => $this->logError($tag, $detail)
            );
            $this->streamService = new StreamService(
                $this->serviceUrl,
                $this->apiKey,
                fn(string $tag, string $detail) => $this->logError($tag, $detail),
                $eventRouter,
                $this->getConversationService()
            );
        }
        return $this->streamService;
    }

    public function index()
    {
        $this->assign('meta_title', '智能分析');
        $this->display();
    }

    public function api_ask()
    {
        $input = $this->parseJsonInput();
        $validation = ConversationValidator::validateQuestion($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        if ($this->serviceUrl === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务未配置']);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $headers = $this->buildHeaders();

        try {
            $response = $this->httpClient->post($this->serviceUrl . '/api/v1/ask', [
                'json' => $payload,
                'headers' => $headers,
            ]);

            $body = (string) $response->getBody();
            $serviceRaw = json_decode($body, true);
            if (!is_array($serviceRaw)) {
                $this->ajaxReturn(['status' => 0, 'info' => '分析服务返回格式错误']);
                return;
            }

            $this->ajaxReturn($this->adapt($serviceRaw));
        } catch (ConnectException $e) {
            $this->logError('connect failed', sprintf('question_len=%d | err=%s', mb_strlen($payload['question'] ?? ''), $e->getMessage()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务不可用']);
        } catch (RequestException $e) {
            $code = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $this->logError('request failed', sprintf('http_code=%d | err=%s | body=%s', $code, $e->getMessage(), $body));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
        } catch (GuzzleException $e) {
            $this->logError('guzzle error', sprintf('err=%s', $e->getMessage()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
        }
    }

    public function api_socket_health()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        try {
            $transport = new \Qscmf\SseCore\SocketTransport([
                'socket_path' => env('CHAT2VIZ_SOCKET_PATH', '/run/chat2viz.sock'),
                'timeout' => 5,
            ]);
            $socket = $transport->connect();
            if (is_resource($socket)) {
                fclose($socket);
            }
            $this->ajaxReturn(['status' => 1, 'data' => ['status' => 'ok']]);
        } catch (\Throwable $e) {
            $this->logError('socket health check failed', $e->getMessage());
            http_response_code(503);
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务不可用，请检查后端服务状态']);
        }
    }

    public function api_ask_stream()
    {
        $input = $this->parseJsonInput();
        $validation = ConversationValidator::validateQuestion($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $this->currentDashboardUid = trim((string) ($payload['dashboard_context']['dashboard_uid'] ?? ''));

        // Resolve or create conversation
        $conversationId = $payload['conversation_id'] ?? '';
        if (!preg_match('/^[a-f0-9\-]{1,64}$/i', $conversationId)) {
            $conversationId = bin2hex(random_bytes(16));
        }

        $convService = $this->getConversationService();
        $conversationId = $convService->ensureConversation($conversationId, $this->currentDashboardUid);
        $convService->persistMessage(
            $conversationId,
            'user',
            $payload['question'] ?? ''
        );
        $assistantMessageId = $convService->preallocateAssistantMessage(
            $conversationId
        );
        $accumulator = new StreamAccumulator();

        // Release session lock before long-running stream
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $wantsChat2viz = $this->wantsChat2vizFormat();

        if ($this->getMockEmitter()->isMockMode()) {
            $this->getMockEmitter()->emitMockStream($payload, $wantsChat2viz, $conversationId);
            $convService->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'complete');
            return;
        }

        $this->getStreamService()->dispatchStream(
            $this->createSocketTransport(),
            $payload,
            $wantsChat2viz,
            $conversationId,
            $accumulator,
            $assistantMessageId
        );
    }

    // -------------------------------------------------------
    // Conversation API
    // -------------------------------------------------------

    public function api_conversation_create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $input = $this->parseJsonInput();
        $validation = ConversationValidator::validateConversationCreate($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $dashboardUid = trim((string) ($input['dashboard_uid'] ?? ''));

        try {
            $convService = $this->getConversationService();

            // Archive current active conversation if one exists
            $active = $convService->findActiveByDashboardUid($dashboardUid);
            if ($active !== null) {
                $convService->archive((int) $active['id']);
            }

            $title = trim((string) ($input['title'] ?? ''));
            $conversation = $convService->createConversation($dashboardUid, $title);

            $this->ajaxReturn([
                'status' => 1,
                'data'   => [
                    'conversation_id' => $conversation['id'],
                    'dashboard_uid'   => $dashboardUid,
                    'title'           => $conversation['title'] ?? '',
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logError('conversation create failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '创建会话失败']);
        }
    }

    public function api_conversation_history()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '' || !self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $convService = $this->getConversationService();

            $active = $convService->findActiveByDashboardUid($uid);
            if ($active === null) {
                $this->ajaxReturn(['status' => 1, 'data' => [
                    'conversation_id' => null,
                    'messages'        => [],
                ]]);
                return;
            }

            $conversationId = (string) $active['id'];
            $msgRepo = AdapterFactory::createMessageRepository();
            $messages = $msgRepo->findConversationHistory($conversationId);

            // Strip g2_spec inside message metadata to ONLY type+encode+title.
            // The frontend populates React state from these specs; any extra
            // fields (transform, children, axis, labels, style) from older LLM
            // runs crash G2 v5. We keep it minimal to match the cleaned DB schema.
            foreach ($messages as &$msg) {
                $meta = $msg['metadata'] ?? null;
                if (is_string($meta)) {
                    $decoded = json_decode($meta, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                if (is_array($meta) && isset($meta['widgets']) && is_array($meta['widgets'])) {
                    foreach ($meta['widgets'] as &$w) {
                        if (is_array($w) && isset($w['g2_spec']) && is_array($w['g2_spec'])) {
                            $spec = $w['g2_spec'];
                            // Determine chart type, fallback to interval
                            $type = isset($spec['type']) ? $spec['type'] : 'interval';
                            // Avoid 'view' type (needs children) — downgrade to interval
                            if ($type === 'view' || $type === 'composite') $type = 'interval';
                            // Extract encode, ensure x and y are strings (not arrays)
                            $encode = [];
                            if (isset($spec['encode']) && is_array($spec['encode'])) {
                                foreach (['x', 'y', 'color', 'size', 'shape'] as $ch) {
                                    if (isset($spec['encode'][$ch])) {
                                        $val = $spec['encode'][$ch];
                                        if (is_array($val)) $val = isset($val[0]) ? $val[0] : 'count';
                                        $encode[$ch] = (string)$val;
                                    }
                                }
                            }
                            // Rebuild minimal spec
                            $clean = ['type' => $type];
                            if (isset($spec['title'])) $clean['title'] = $spec['title'];
                            if (!empty($encode)) $clean['encode'] = $encode;
                            $w['g2_spec'] = $clean;
                        }
                    }
                    if (is_string($msg['metadata'] ?? null)) {
                        $msg['metadata'] = json_encode($meta, JSON_UNESCAPED_UNICODE);
                    } else {
                        $msg['metadata'] = $meta;
                    }
                }
            }
            unset($msg);

            $this->ajaxReturn(['status' => 1, 'data' => [
                'conversation_id' => $conversationId,
                'messages'        => $messages,
            ]]);
        } catch (\Throwable $e) {
            $this->logError('conversation history failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取对话历史失败']);
        }
    }

    public function api_conversation_list()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '' || !self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $convService = $this->getConversationService();
            $conversations = $convService->findByDashboardUid($uid);

            $msgRepo = AdapterFactory::createMessageRepository();
            $convIds = array_map(fn($c) => (string) $c['id'], $conversations);
            $counts = $msgRepo->countByConversationIds($convIds);

            $enriched = [];
            foreach ($conversations as $conv) {
                $convId = (string) $conv['id'];
                $enriched[] = [
                    'id'            => $conv['id'],
                    'dashboard_uid' => $conv['dashboard_uid'] ?? $uid,
                    'title'         => $conv['title'] ?? '',
                    'status'        => (int) ($conv['status'] ?? 1),
                    'message_count' => $counts[$convId] ?? 0,
                    'created_at'    => $conv['created_at'] ?? '',
                    'updated_at'    => $conv['updated_at'] ?? '',
                ];
            }

            $this->ajaxReturn(['status' => 1, 'data' => ['conversations' => $enriched]]);
        } catch (\Throwable $e) {
            $this->logError('conversation list failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取会话列表失败']);
        }
    }

    // -------------------------------------------------------
    // HTTP-level helpers (kept on controller)
    // -------------------------------------------------------

    private function buildPayloadFromParsed(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        $conversationId = $input['conversation_id'] ?? null;

        $payload = ['question' => $question];
        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        if (!empty($input['dashboard_context'])) {
            $ctx = $input['dashboard_context'];
            if (!is_array($ctx)) {
                return $payload;
            }
            if (isset($ctx['dashboard_uid']) && !is_string($ctx['dashboard_uid'])) {
                return $payload;
            }
            if (isset($ctx['widgets']) && !is_array($ctx['widgets']) && !($ctx['widgets'] instanceof \stdClass)) {
                return $payload;
            }
            if (isset($ctx['widgets']) && is_array($ctx['widgets']) && empty($ctx['widgets'])) {
                $ctx['widgets'] = new \stdClass();
            }
            $payload['dashboard_context'] = $ctx;
        }

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $headers;
    }

    private function adapt(array $raw): array
    {
        if (isset($raw['answer'])) {
            return [
                'status' => 1,
                'data'   => [
                    'answer'   => $raw['answer'],
                    'sql'      => $raw['sql'] ?? null,
                    'g2_spec'  => $raw['g2_spec'] ?? null,
                ],
            ];
        }

        return [
            'status' => 0,
            'info'   => $raw['error'] ?? '分析服务请求失败',
        ];
    }

    private function wantsChat2vizFormat(): bool
    {
        return isset($_SERVER['HTTP_X_EVENT_FORMAT'])
            && $_SERVER['HTTP_X_EVENT_FORMAT'] === 'chat2viz';
    }

    private function getMockEmitter(): MockStreamEmitter
    {
        if ($this->mockEmitter === null) {
            $this->mockEmitter = new MockStreamEmitter();
        }
        return $this->mockEmitter;
    }

    private function createSocketTransport(): \Qscmf\SseCore\SocketTransport
    {
        return new \Qscmf\SseCore\SocketTransport([
            'socket_path' => env('CHAT2VIZ_SOCKET_PATH', '/run/chat2viz.sock'),
            // Default 180s; overridable via CHAT2VIZ_SSE_TIMEOUT. Wired here
            // so createSocketTransportTest's default/custom-timeout contract holds.
            'timeout' => (int) env('CHAT2VIZ_SSE_TIMEOUT', 180),
        ]);
    }

    private function logError(string $tag, string $detail): void
    {
        \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
    }
}
