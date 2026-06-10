<?php

namespace Qscmf\Chat2Viz\Controller;

use Gy_Library\GyController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Qscmf\SseCore\Contracts\SseEventHandler;
use Qscmf\SseCore\SseError;
use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SsePassthrough;
use Qscmf\SseCore\SseProxy;
use Qscmf\SseCore\SseReader;
use Qscmf\SseCore\SseWriter;
use Qscmf\SseCore\SocketTransport;
use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Sse\Nl2sqlEventTransformer;
use Qscmf\Chat2Viz\Traits\JsonInputTrait;

class Chat2VizController extends GyController
{
    use JsonInputTrait;
    protected string $serviceUrl;
    protected string $apiKey;
    protected Client $httpClient;

    protected function _initialize()
    {
        parent::_initialize();

        $this->serviceUrl = rtrim((string) env('CHAT2VIZ_SERVICE_URL', ''), '/');
        $this->apiKey = (string) env('CHAT2VIZ_API_KEY', '');
        $this->httpClient = new Client(['timeout' => 60]);
    }

    public function index()
    {
        $this->assign('meta_title', '智能分析');
        $this->display();
    }

    public function api_ask()
    {
        $input = $this->parseJsonInput();
        $validation = $this->validateParsedInput($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
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

    public function api_ask_stream()
    {
        $input = $this->parseJsonInput();
        $validation = $this->validateSocketInput($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $conversationId = $payload['conversation_id'] ?? bin2hex(random_bytes(16));

        // Persist user message before the stream starts
        // TODO: re-enable after creating database tables
        // $this->persistConversationMessage($conversationId, $payload, 'user');

        $wantsChat2viz = $this->wantsChat2vizFormat();

        try {
            $transport = $this->createSocketTransport();
            $requestFrame = [
                'id'     => bin2hex(random_bytes(16)),
                'method' => 'ask_stream',
                'params' => $payload,
                'auth'   => ['api_key' => $this->apiKey],
            ];
            if ($wantsChat2viz) {
                $transformer = new Nl2sqlEventTransformer();
                SseProxy::socket($transport, $requestFrame, function (array $frame) use ($transformer): ?SseEvent {
                    $type = $frame['type'] ?? 'message';
                    if ($type === 'ping' || $type === 'pong') {
                        return null;
                    }
                    $input = new SseEvent(
                        type: $type,
                        data: $frame['data'] ?? [],
                        raw: '',
                    );
                    if ($input->isComment()) {
                        return $input;
                    }
                    $outputs = $transformer->transform($input);
                    return $outputs[0] ?? null;
                });
            } else {
                SseProxy::socket($transport, $requestFrame);
            }
            // Stream completed — persist assistant message
            $this->persistConversationMessage($conversationId, $payload, 'assistant');
        } catch (\Throwable $e) {
            $this->logError('socket failed, falling back to http', $e->getMessage());

            // H10: SseProxy::socket() has already called sendHeaders().
            // If headers were sent, we cannot fall back to Guzzle SSE (it also calls sendHeaders).
            if (headers_sent()) {
                $writer = new SseWriter(autoStart: false);
                $writer->sendError('socket_fallback_failed', '分析服务连接失败');
                return;
            }
            $streamCompleted = $this->fallbackGuzzleStream($payload, $wantsChat2viz);
            if ($streamCompleted) {
                $this->persistConversationMessage($conversationId, $payload, 'assistant');
            }
        }
    }

    private function validateSocketInput(?array $input): ?array
    {
        if (!is_array($input)) {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($contentType, 'application/json') === false) {
                return ['status' => 0, 'info' => '请求格式不支持'];
            }
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            return ['status' => 0, 'info' => '请输入问题'];
        }

        if (mb_strlen($question) > 1000) {
            return ['status' => 0, 'info' => '问题长度不能超过1000字'];
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && !preg_match('/^[a-f0-9\-]{1,64}$/i', (string) $conversationId)) {
            return ['status' => 0, 'info' => '无效的会话ID'];
        }

        return null;
    }

    private function createSocketTransport(): SocketTransport
    {
        return new SocketTransport([
            'socket_path' => env('CHAT2VIZ_SOCKET_PATH', '/run/chat2viz.sock'),
            'host'        => env('CHAT2VIZ_SOCKET_HOST'),
            'port'        => (int) env('CHAT2VIZ_SOCKET_PORT', 9501),
            'timeout'     => (int) env('CHAT2VIZ_SSE_TIMEOUT', 180),
        ]);
    }

    private function fallbackGuzzleStream(array $payload, bool $wantsChat2viz = false): bool
    {
        $headers = $this->buildHeaders();
        $timeout = (int) env('CHAT2VIZ_SSE_TIMEOUT', 180);

        try {
            $response = $this->httpClient->post(
                $this->serviceUrl . '/api/v1/ask/stream',
                [
                    RequestOptions::JSON    => $payload,
                    RequestOptions::HEADERS => $headers,
                    RequestOptions::STREAM  => true,
                    RequestOptions::TIMEOUT => $timeout,
                ]
            );
        } catch (ConnectException $e) {
            $this->logError('stream connect failed', 'ConnectException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务不可用']);
            return false;
        } catch (RequestException $e) {
            $this->logError('stream request failed', 'RequestException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return false;
        } catch (GuzzleException $e) {
            $this->logError('stream guzzle error', 'GuzzleException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logError('stream non-200', sprintf('status=%d', $response->getStatusCode()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return false;
        }

        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();

        $writer = new SseWriter(autoStart: false);

        if ($wantsChat2viz) {
            $transformer = new Nl2sqlEventTransformer();
            $handler = new class($transformer, $writer) implements SseEventHandler {
                public function __construct(
                    private Nl2sqlEventTransformer $transformer,
                    private SseWriter $writer,
                ) {}

                public function onEvent(SseEvent $event): void
                {
                    if (connection_aborted()) {
                        return;
                    }
                    foreach ($this->transformer->transform($event) as $mapped) {
                        $this->writer->sendEvent($mapped);
                    }
                }

                public function onError(SseError $error): void
                {
                    $errorEvent = SseEvent::fromRaw(
                        "event: error\ndata: " . json_encode([
                            'type' => 'upstream_disconnected',
                            'info' => "分析服务连接中断",
                        ], JSON_UNESCAPED_UNICODE)
                    );
                    if ($errorEvent !== null) {
                        $this->writer->sendEvent($errorEvent);
                    }
                }

                public function onComplete(): void
                {
                    // No-op: do not emit any completion event to the browser.
                }
            };
        } else {
            $passthrough = new SsePassthrough($writer);
            $handler = new class($passthrough, $writer) implements SseEventHandler {
                public function __construct(
                    private SsePassthrough $passthrough,
                    private SseWriter $writer,
                ) {}

                public function onEvent(SseEvent $event): void
                {
                    if (connection_aborted()) {
                        return;
                    }
                    $this->passthrough->onEvent($event);
                }

                public function onError(SseError $error): void
                {
                    $errorEvent = SseEvent::fromRaw(
                        "event: error\ndata: " . json_encode([
                            'type' => 'upstream_disconnected',
                            'info' => "分析服务连接中断",
                        ], JSON_UNESCAPED_UNICODE)
                    );
                    if ($errorEvent !== null) {
                        $this->writer->sendEvent($errorEvent);
                    }
                }

                public function onComplete(): void
                {
                    // No-op: do not emit any completion event to the browser.
                }
            };
        }

        $body = $response->getBody();
        (new SseReader($body))->consume($handler);
        return true;
    }

    private function validateParsedInput(?array $input): ?array
    {
        if (!is_array($input)) {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($contentType, 'application/json') === false) {
                return ['status' => 0, 'info' => '请求格式不支持'];
            }
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            return ['status' => 0, 'info' => '请输入问题'];
        }

        if (mb_strlen($question) > 1000) {
            return ['status' => 0, 'info' => '问题长度不能超过1000字'];
        }

        if ($this->serviceUrl === '') {
            return ['status' => 0, 'info' => '分析服务未配置'];
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && !preg_match('/^[a-f0-9\-]{1,64}$/i', (string) $conversationId)) {
            return ['status' => 0, 'info' => '无效的会话ID'];
        }

        return null;
    }

    private function buildPayloadFromParsed(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        $conversationId = $input['conversation_id'] ?? null;

        $payload = ['question' => $question];
        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        // Forward dashboard context for dashboard-aware conversations
        if (!empty($input['dashboard_context'])) {
            $ctx = $input['dashboard_context'];
            // PHP json_decode(true) turns empty JSON {} into [] which json_encode
            // then serialises as a JSON array [].  The Python NL2SQL service
            // requires widgets to be a dict/object, so force stdClass for empty arrays.
            if (isset($ctx['widgets']) && is_array($ctx['widgets']) && empty($ctx['widgets'])) {
                $ctx['widgets'] = new \stdClass();
            }
            $payload['dashboard_context'] = $ctx;
        }

        return $payload;
    }

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
        if (!empty($raw['success'])) {
            return [
                'status' => 1,
                'data' => [
                    'answer'          => $raw['answer'] ?? '',
                    'sql'             => $raw['sql'] ?? '',
                    'g2_spec'         => $raw['g2_spec'] ?? null,
                    'conversation_id' => $raw['conversation_id'] ?? null,
                ],
            ];
        }

        return [
            'status' => 0,
            'info'   => $raw['error'] ?? '分析服务请求失败',
        ];
    }

    private function persistConversationMessage(string $conversationId, array $payload, string $role): void
    {
        try {
            $dashboardUid = $payload['dashboard_context']['dashboard_uid'] ?? '';
            $content = $role === 'user' ? ($payload['question'] ?? '') : '';

            $repo = AdapterFactory::createConversationRepository();
            $repo->createMessage($conversationId, $dashboardUid, $role, $content);
        } catch (\Throwable $e) {
            $this->logError('persist message failed', $e->getMessage());
        }
    }

    private function wantsChat2vizFormat(): bool
    {
        return isset($_SERVER['HTTP_X_EVENT_FORMAT'])
            && $_SERVER['HTTP_X_EVENT_FORMAT'] === 'chat2viz';
    }

    private function logError(string $tag, string $detail): void
    {
        \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
    }

    /**
     * Get conversation history for a dashboard.
     * Proxies to the Python service, falls back to MySQL on failure.
     * URL: GET /extends/Chat2Viz/api_conversation_history?uid={uid}
     */
    public function api_conversation_history()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }

        // Look up dashboard to find conversation_id
        $repo = AdapterFactory::createRepository();
        $dashboard = $repo->findByUid($uid);
        if ($dashboard === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
            return;
        }

        $conversationId = $dashboard['conversation_id'] ?? '';
        if ($conversationId === '') {
            $this->ajaxReturn(['status' => 1, 'data' => ['messages' => []]]);
            return;
        }

        // Try Python service first (has full message content)
        if ($this->serviceUrl !== '') {
            try {
                $response = $this->httpClient->get(
                    $this->serviceUrl . '/api/v1/conversation/' . urlencode($conversationId),
                    [
                        'headers' => $this->buildHeaders(),
                        'timeout' => 5,
                    ]
                );
                $data = json_decode((string) $response->getBody(), true);
                if (is_array($data) && isset($data['messages'])) {
                    $this->ajaxReturn(['status' => 1, 'data' => $data]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->logError('conversation history upstream failed', $e->getMessage());
            }
        }

        // Fallback: MySQL (assistant content may be empty)
        try {
            $convRepo = AdapterFactory::createConversationRepository();
            $messages = $convRepo->getMessages($conversationId);
            $this->ajaxReturn(['status' => 1, 'data' => ['messages' => $messages]]);
        } catch (\Throwable $e) {
            $this->logError('conversation history db failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取对话历史失败']);
        }
    }
}
