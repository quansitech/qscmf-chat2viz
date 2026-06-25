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
use Qscmf\Chat2Viz\Validator\FeedbackValidator;

class Chat2VizController extends GyController
{
    use JsonInputTrait;
    use UuidTrait;

    protected string $serviceUrl;
    protected string $apiKey;
    protected Client $httpClient;
    private ?MockStreamEmitter $mockEmitter = null;
    private string $currentDashboardUid = '';
    /**
     * conversation-one-to-one-and-first-msg-init: when a first message triggers
     * the three-table init, the freshly created dashboard uid is stored here so
     * it can be threaded into the Nl2sqlEventTransformer (Task 5) and emitted on
     * the conversation_id SSE frame. Empty on non-first-message turns.
     */
    private string $firstMessageDashboardUid = '';

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
                AdapterFactory::createRepository(),
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
        // fix-stream-message-persistence: keep the script alive after the client
        // disconnects (gateway timeout) so finalizeStream can still run. Covers the
        // common "connection dropped but PHP still executing" soft-interrupt path;
        // hard kills (SIGKILL/OOM) are masked by history-display filtering + optional cron.
        ignore_user_abort(true);

        $input = $this->parseJsonInput();
        $validation = ConversationValidator::validateQuestion($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $this->currentDashboardUid = trim((string) ($payload['dashboard_context']['dashboard_uid'] ?? ''));

        // inject-conversation-history Decision 7: enforce dashboard ownership
        // BEFORE ensureConversation / history assembly. Chat2VizController lives
        // in the `extends` module (no framework auth), and dashboard_uid comes
        // from client JSON — without this guard a logged-in user could pass
        // another user's dashboard_uid and read their conversation history via
        // the injected prior_messages. Mirrors DashboardController's ownership
        // checks but implemented inline (Chat2VizController does not extend
        // BaseDashboardController, so it cannot call checkOwnershipAndReject).
        if ($this->currentDashboardUid !== '') {
            $dashboard = \Qscmf\Chat2Viz\Adapter\AdapterFactory::createRepository()
                ->findByUid($this->currentDashboardUid);
            if ($dashboard === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            $currentUserId = (int) session(C('USER_AUTH_KEY'));
            if ((int) ($dashboard['created_by'] ?? 0) !== $currentUserId) {
                $this->ajaxReturn(['status' => 0, 'info' => '无权操作']);
                return;
            }
        }

        $convService = $this->getConversationService();

        // conversation-one-to-one-and-first-msg-init: detect the first message
        // on a brand-new dashboard (no conversation_id and no dashboard_uid yet
        // — the frontend has not created the dashboard). Transactionally create
        // dashboard + conversation + user message, then continue the stream.
        $hasConversationId = isset($payload['conversation_id'])
            && is_string($payload['conversation_id'])
            && $payload['conversation_id'] !== '';

        if (!$hasConversationId && $this->currentDashboardUid === '') {
            $question = trim((string) ($payload['question'] ?? ''));
            try {
                $userId = (int) session(C("USER_AUTH_KEY")); $init = $convService->initializeOnFirstMessage($question, $userId > 0 ? $userId : null);
            } catch (\Throwable $e) {
                $this->logError('first message init failed', $e->getMessage());
                $this->ajaxReturn(['status' => 0, 'info' => '初始化会话失败']);
                return;
            }

            $conversationId = $init['conversation_id'];
            $this->currentDashboardUid = $init['uid'];
            // Stored so the Nl2sqlEventTransformer (Task 5) can append uid to the
            // conversation_id SSE frame, letting the frontend switch to edit mode.
            $this->firstMessageDashboardUid = $init['uid'];

            $payload['conversation_id'] = $conversationId;
            // First message: no prior history (the three tables were just created).
            $payload['prior_messages'] = [];

            // The user message was persisted inside initializeOnFirstMessage's
            // transaction; only preallocate the assistant slot below.
            $convService->finalizeOrphanedStreamingMessages($conversationId);
            $assistantMessageId = $convService->preallocateAssistantMessage($conversationId);
        } else {
            // Resolve or reuse the 1:1 conversation for an existing dashboard.
            $conversationId = (string) ($payload['conversation_id'] ?? '');

            $conversationId = $convService->ensureConversation($conversationId, $this->currentDashboardUid);

            if ($conversationId === '') {
                // ensureConversation could not resolve (no uid, non-numeric cid,
                // or persistence failure). Cannot proceed without a BIGINT id.
                $this->ajaxReturn(['status' => 0, 'info' => '会话初始化失败']);
                return;
            }

            // inject-conversation-history Decision 4: unify conversation_id to the
            // DB id so Python never self-generates a divergent UUID (which would
            // break history continuity).
            $payload['conversation_id'] = $conversationId;

            // inject-conversation-history: assemble prior_messages from DB history.
            // MUST run BEFORE persistMessage(this turn) so the current question is
            // not duplicated (Python appends HumanMessage(question) on its side).
            // Cap turns at 20 to guard against misconfigured CHAT2VIZ_HISTORY_TURNS.
            $historyLimit = min((int) env('CHAT2VIZ_HISTORY_TURNS', 4), 20) * 2;
            $payload['prior_messages'] = $convService->getRecentMessagesForContext($conversationId, $historyLimit);

            // fix-stream-message-persistence: clean the tail orphan left by a prior
            // interrupted request BEFORE preallocating a new assistant row, so the
            // previous 'streaming'+empty row never lingers as a terminal state.
            $convService->finalizeOrphanedStreamingMessages($conversationId);

            $convService->persistMessage(
                $conversationId,
                'user',
                $payload['question'] ?? ''
            );
            $assistantMessageId = $convService->preallocateAssistantMessage($conversationId);
        }

        // fix-stream-message-persistence Decision 3: register a shutdown guard so
        // that if PHP exits before finalizeStream runs (soft interrupt), the
        // preallocated row is atomically flipped 'streaming'→'interrupted'.
        // Idempotent — finalizeStream's 'complete' write wins; this UPDATE's
        // WHERE message_status='streaming' then matches 0 rows.
        $shutdownConvService = $convService;
        register_shutdown_function(function () use ($shutdownConvService, $conversationId, $assistantMessageId): void {
            $shutdownConvService->ensureNotStrandedStreaming($conversationId, $assistantMessageId);
        });

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
            $assistantMessageId,
            // conversation-one-to-one-and-first-msg-init: non-empty only on the
            // first message; the Nl2sqlEventTransformer appends it to the
            // conversation_id SSE frame so the frontend can switch to edit mode.
            $this->firstMessageDashboardUid !== '' ? $this->firstMessageDashboardUid : null
        );
    }

    // -------------------------------------------------------
    // Conversation API
    // -------------------------------------------------------


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

            // fix-stream-message-persistence: filter out terminal 'streaming'
            // orphans (status=streaming AND empty content) so they never render
            // as a phantom "thinking..." bubble in the chat history. Real partial
            // replies (status=interrupted with content, or streaming with content
            // from an in-flight request) are preserved.
            $messages = array_values(array_filter($messages, static function ($m): bool {
                $status = $m['message_status'] ?? '';
                $content = trim((string) ($m['content'] ?? ''));
                return !($status === 'streaming' && $content === '');
            }));

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

    // -------------------------------------------------------
    // Retry API (fix-redis-degrade-and-retry-dedup: dedup user messages on retry)
    // -------------------------------------------------------

    /**
     * POST /extends/Chat2Viz/api_delete_last_turn
     *
     * Deletes the most-recent user message and everything after it (typically the
     * paired assistant reply) from a conversation. Used by the frontend retry
     * flow to prevent duplicate user rows from accumulating when regenerating
     * the last answer.
     *
     * fix-redis-degrade-and-retry-dedup: Chat2VizController lives in the extends
     * module (no framework auth) and conversation_id is client-controlled, so
     * this endpoint MUST verify dashboard ownership + conversation belonging
     * before deleting. Mirrors DashboardController's pattern but implemented
     * inline (Chat2VizController does not extend BaseDashboardController).
     */
    public function api_delete_last_turn()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $input = $this->parseJsonInput();
        $dashboardUid = trim((string) ($input['dashboard_uid'] ?? ''));
        $conversationId = trim((string) ($input['conversation_id'] ?? ''));

        if ($dashboardUid === '' || !self::validateUuid($dashboardUid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效参数']);
            return;
        }

        // 1. Dashboard ownership check (Chat2VizController cannot call
        //    checkOwnershipAndReject — it does not extend BaseDashboardController).
        $dashboard = \Qscmf\Chat2Viz\Adapter\AdapterFactory::createRepository()
            ->findByUid($dashboardUid);
        if ($dashboard === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
            return;
        }
        $currentUserId = (int) session(C('USER_AUTH_KEY'));
        if ((int) ($dashboard['created_by'] ?? 0) !== $currentUserId) {
            $this->ajaxReturn(['status' => 0, 'info' => '无权操作']);
            return;
        }

        // 2. Conversation belonging: ConversationRepositoryInterface has no
        //    find(string); reverse-lookup via findActiveByDashboardUid and
        //    compare the active id. Only the active conversation can be deleted.
        $convService = $this->getConversationService();
        $active = $convService->findActiveByDashboardUid($dashboardUid);
        if ($active === null || (string) $active['id'] !== $conversationId) {
            $this->ajaxReturn(['status' => 0, 'info' => '会话不属于该仪表盘']);
            return;
        }

        // 3. Delete (transactional inside the repo).
        $deleted = $convService->deleteLastTurn($conversationId);
        $this->ajaxReturn(['status' => 1, 'data' => ['deleted' => $deleted]]);
    }

    // -------------------------------------------------------
    // Feedback API (LLM optimization: collect user feedback for skill sedimentation)
    // -------------------------------------------------------

    /**
     * POST /extends/Chat2Viz/api_feedback
     *
     * Collects user feedback (thumbs up/down + optional comment) and implicit
     * signals (regenerated, widget_deleted, sql_edited) for the AI answer.
     * Stored in qs_chat2viz_feedback_records, read by the Python skill_curator.
     *
     * Binary feedback only — NO star ratings (adversarial review consensus).
     */
    public function api_feedback()
    {
        $input = $this->parseJsonInput();
        $validation = FeedbackValidator::validate($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $messageId = trim((string) ($input['message_id'] ?? ''));
        $conversationId = trim((string) ($input['conversation_id'] ?? ''));
        $turnIndex = isset($input['turn_index']) ? (int) $input['turn_index'] : null;
        $question = trim((string) ($input['question'] ?? ''));
        $answerText = trim((string) ($input['answer_text'] ?? ''));
        $answerWidgets = $input['answer_widgets'] ?? null;
        $thumbs = $input['thumbs'] ?? null;
        $comment = trim((string) ($input['comment'] ?? ''));
        $implicitSignals = $input['implicit_signals'] ?? null;
        $industry = trim((string) ($input['industry'] ?? ''));

        // Fetch the question/answer from the message if not provided
        if ($question === '' || $answerText === '') {
            $msg = M('chat2viz_conversation_messages')->where(['id' => $messageId])->find();
            if ($msg) {
                if ($question === '') {
                    // Find the preceding user message
                    $userMsg = M('chat2viz_conversation_messages')
                        ->where([
                            'conversation_id' => $msg['conversation_id'],
                            'role' => 'user',
                            'id' => ['LT', $messageId],
                        ])
                        ->order('id DESC')
                        ->find();
                    $question = $userMsg ? trim((string) $userMsg['content']) : '';
                }
                if ($answerText === '') {
                    $answerText = trim((string) ($msg['content'] ?? ''));
                }
                if ($conversationId === '') {
                    $conversationId = trim((string) ($msg['conversation_id'] ?? ''));
                }
            }
        }

        $data = [
            'message_id' => $messageId,
            'conversation_id' => $conversationId !== '' ? $conversationId : null,
            'turn_index' => $turnIndex,
            'question' => $question,
            'answer_text' => $answerText,
            'answer_widgets' => $answerWidgets ? json_encode($answerWidgets, JSON_UNESCAPED_UNICODE) : null,
            'thumbs' => $thumbs,
            'comment' => $comment !== '' ? $comment : null,
            'implicit_signals' => $implicitSignals ? json_encode($implicitSignals, JSON_UNESCAPED_UNICODE) : null,
            'industry' => $industry !== '' ? $industry : null,
        ];

        $id = M('chat2viz_feedback_records')->add($data);
        if ($id === false) {
            $this->logError('feedback_save_failed', 'message_id=' . $messageId);
            $this->ajaxReturn(['status' => 0, 'info' => '反馈保存失败']);
            return;
        }

        $this->ajaxReturn(['status' => 1, 'info' => '感谢您的反馈', 'data' => ['id' => $id]]);
    }
}
