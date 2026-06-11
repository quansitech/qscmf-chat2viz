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
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
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
        $dashboardUid = $payload['dashboard_context']['dashboard_uid'] ?? '';

        // Resolve or generate conversation_id
        $conversationId = $payload['conversation_id'] ?? '';
        if (!preg_match('/^[a-f0-9\-]{1,64}$/i', $conversationId)) {
            $conversationId = bin2hex(random_bytes(16));
        }

        // --- Pre-stream setup: user message, conversation, pre-allocated assistant ---
        $assistantMessageId = null;
        $accumulator = new StreamAccumulator();

        try {
            // Ensure an active conversation exists for this dashboard
            $conversationId = $this->ensureConversation($conversationId, $dashboardUid);

            // Persist user message before the stream starts
            $this->persistConversationMessage($conversationId, $payload, 'user');

            // Pre-allocate assistant message row (message_status='streaming')
            $assistantMessageId = $this->preallocateAssistantMessage($conversationId, $dashboardUid);
        } catch (\Throwable $e) {
            $this->logError('pre-stream setup failed', $e->getMessage());
            // Continue streaming even if DB setup fails — just won't persist assistant
        }

        $wantsChat2viz = $this->wantsChat2vizFormat();

        // Mock mode: return a deterministic chart widget without calling the
        // real Python service.  Enabled via CHAT2VIZ_MOCK_MODE=true in .env.
        if ($this->isMockMode()) {
            $this->emitMockStream($payload, $wantsChat2viz, $conversationId);
            $this->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'complete');
            return;
        }

        try {
            $transport = $this->createSocketTransport();
            $requestFrame = [
                'id'     => bin2hex(random_bytes(16)),
                'method' => 'ask_stream',
                'params' => $payload,
                'auth'   => ['api_key' => $this->apiKey],
            ];
            if ($wantsChat2viz) {
                $transformer = new Nl2sqlEventTransformer($conversationId);
                SseProxy::socket($transport, $requestFrame, function (array $frame) use ($transformer, $accumulator, $conversationId, $assistantMessageId): ?SseEvent {
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

                    // Route each transformed event to the Redis accumulator
                    foreach ($outputs as $mapped) {
                        $this->routeEventToAccumulator($accumulator, $conversationId, $mapped);
                    }

                    return $outputs[0] ?? null;
                });
            } else {
                SseProxy::socket($transport, $requestFrame);
            }
            // Stream completed — flush Redis accumulator to DB
            $this->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'complete');
        } catch (\Throwable $e) {
            $this->logError('socket failed, falling back to http', $e->getMessage());

            // SseProxy::socket() already called sendHeaders() via SseWriter::sendHeaders(),
            // so Content-Type is already text/event-stream.  headers_sent() only tracks
            // whether body output started — it is false even after header() calls.
            // Therefore we must NOT fall through to ajaxReturn() (which sets application/json).
            if (headers_sent()) {
                // Body output already started — send inline SSE error, mark interrupted
                $this->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'interrupted');
                $writer = new SseWriter(autoStart: false);
                $writer->sendError('socket_fallback_failed', '分析服务连接失败');
                return;
            }
            $streamCompleted = $this->fallbackGuzzleStream(
                $payload, $wantsChat2viz, $conversationId, $accumulator, $assistantMessageId
            );
            if ($streamCompleted) {
                $this->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'complete');
            } else {
                $this->finalizeStream($accumulator, $conversationId, $assistantMessageId, 'interrupted');
            }
        }
    }

    private function isMockMode(): bool
    {
        $val = (string) env('CHAT2VIZ_MOCK_MODE', 'false');
        return in_array(strtolower($val), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Emit a deterministic SSE stream with intent-based mock responses.
     * Used in mock mode / E2E tests when the real Python NL2SQL service is offline.
     */
    private function emitMockStream(array $payload, bool $wantsChat2viz, string $conversationId): void
    {
        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();
        $writer = new SseWriter(autoStart: false);

        $question = $payload['question'] ?? '';

        // conversation_id
        $writer->sendEvent(new SseEvent(
            type: 'conversation_id',
            data: ['conversation_id' => $conversationId, 'is_mock' => true],
            raw: '',
        ));

        if ($wantsChat2viz) {
            $widgets = $payload['dashboard_context']['widgets'] ?? [];
            $firstWidgetId = !empty($widgets) ? ($widgets[0]['id'] ?? null) : null;
            $intent = $this->classifyMockIntent($question);

            switch ($intent) {
                case 'modify_chart':
                    $this->emitMockModifyChart($writer, $question, $firstWidgetId);
                    break;
                case 'rename':
                    $this->emitMockRename($writer, $question, $firstWidgetId);
                    break;
                case 'delete':
                    $this->emitMockDelete($writer, $question, $firstWidgetId);
                    break;
                case 'add':
                    $this->emitMockAddChart($writer, $question);
                    break;
                default:
                    $this->emitMockQuery($writer, $question);
                    break;
            }
        } else {
            // Plain SSE passthrough — send simple answer + done
            $writer->sendEvent(new SseEvent(
                type: 'message',
                data: ['text' => '[Mock] 查询已完成: ' . $question],
                raw: '',
            ));
        }
    }

    // ── Mock intent classification ──────────────────────────────────────

    private function classifyMockIntent(string $question): string
    {
        if (preg_match('/换成|换.*图|改.*图|变成.*图|修改.*图/u', $question)) {
            return 'modify_chart';
        }
        if (preg_match('/标题.*改成|改.*标题|改名|重命名/u', $question)) {
            return 'rename';
        }
        if (preg_match('/删|删除|去掉|移除|不要.*图/u', $question)) {
            return 'delete';
        }
        if (preg_match('/再加|新增|加一个|添加|再加一个/u', $question)) {
            return 'add';
        }
        return 'query';
    }

    private function extractMockChartType(string $question): string
    {
        $map = [
            '/折线|line/u' => 'line',
            '/饼|pie|占比/u' => 'pie',
            '/表格|table|列表/u' => 'table',
            '/柱|bar|柱状/u' => 'bar',
        ];
        foreach ($map as $pattern => $type) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }
        return 'line'; // default fallback for modify_chart
    }

    private function extractMockNewTitle(string $question): string
    {
        // Extract text within quotes (single or double or Chinese quotes)
        if (preg_match('/[\'"\'"](.*?)[\'"\'"]/u', $question, $m)) {
            return $m[1];
        }
        // Extract text after "改成" or "改为"
        if (preg_match('/改成|改为|修改为(.+)/u', $question, $m)) {
            return trim($m[1]);
        }
        return '新标题';
    }

    // ── Mock SSE event emitters per intent ───────────────────────────────

    private function emitMockQuery(SseWriter $writer, string $question): void
    {
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '正在分析'], raw: ''));

        $sql = 'SELECT category, COUNT(*) AS cnt FROM film GROUP BY category ORDER BY cnt DESC';
        $writer->sendEvent(new SseEvent(type: 'sql_generated', data: ['sql' => $sql], raw: ''));

        $widgetId = 'mock-' . substr(md5($question), 0, 8);
        $writer->sendEvent(new SseEvent(
            type: 'chart_ready',
            data: [
                'id'         => $widgetId,
                'title'      => $this->mockTitleFromQuestion($question),
                'chart_type' => 'bar',
                'g2_spec'    => [
                    'type'  => 'interval',
                    'encode' => ['x' => 'category', 'y' => 'cnt'],
                ],
                'data' => $this->mockFilmCategoryData(),
                'sql'    => $sql,
                'layout' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            ],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockModifyChart(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';
        $chartType = $this->extractMockChartType($question);

        $g2SpecMap = [
            'line'  => ['type' => 'line', 'encode' => ['x' => 'category', 'y' => 'cnt']],
            'pie'   => ['type' => 'pie', 'encode' => ['color' => 'category', 'y' => 'cnt']],
            'bar'   => ['type' => 'interval', 'encode' => ['x' => 'category', 'y' => 'cnt']],
            'table' => ['type' => 'table', 'columns' => ['category', 'cnt']],
        ];
        $g2Spec = $g2SpecMap[$chartType] ?? $g2SpecMap['line'];

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'change_chart_type',
                'params' => ['widget_id' => $widgetId, 'new_type' => $chartType],
            ],
            raw: '',
        ));

        // dashboard_patch — replace g2_spec and chart_type
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/g2_spec", 'value' => $g2Spec],
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/chart_type", 'value' => $chartType],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 2]],
            raw: '',
        ));

        // answer
        $typeLabel = ['line' => '折线图', 'pie' => '饼图', 'bar' => '柱状图', 'table' => '表格'][$chartType] ?? $chartType;
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "已将图表改为{$typeLabel}"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockRename(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';
        $newTitle = $this->extractMockNewTitle($question);

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'update_widget_field',
                'params' => ['widget_id' => $widgetId, 'field' => 'title', 'value' => $newTitle],
            ],
            raw: '',
        ));

        // dashboard_patch
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/title", 'value' => $newTitle],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 1]],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "标题已更新为'{$newTitle}'"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockDelete(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'remove_widget',
                'params' => ['widget_id' => $widgetId],
            ],
            raw: '',
        ));

        // dashboard_patch
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'remove', 'path' => "/widgets/{$widgetId}"],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 1]],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '已删除该图表'], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockAddChart(SseWriter $writer, string $question): void
    {
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '正在生成新图表...'], raw: ''));

        $sql = 'SELECT year, SUM(revenue) AS revenue FROM film_yearly GROUP BY year ORDER BY year';
        $writer->sendEvent(new SseEvent(type: 'sql_generated', data: ['sql' => $sql], raw: ''));

        $widgetId = 'mock-' . substr(md5($question . time()), 0, 8);
        $writer->sendEvent(new SseEvent(
            type: 'chart_ready',
            data: [
                'id'         => $widgetId,
                'title'      => $this->mockTitleFromQuestion($question),
                'chart_type' => 'line',
                'g2_spec'    => [
                    'type'  => 'line',
                    'encode' => ['x' => 'year', 'y' => 'revenue'],
                ],
                'data' => [
                    ['year' => '2020', 'revenue' => 1200],
                    ['year' => '2021', 'revenue' => 1800],
                    ['year' => '2022', 'revenue' => 2400],
                    ['year' => '2023', 'revenue' => 3100],
                    ['year' => '2024', 'revenue' => 4200],
                ],
                'sql'    => $sql,
                'layout' => ['x' => 0, 'y' => 6, 'w' => 12, 'h' => 6],
            ],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function mockFilmCategoryData(): array
    {
        return [
            ['category' => '动作', 'cnt' => 64],
            ['category' => '喜剧', 'cnt' => 51],
            ['category' => '剧情', 'cnt' => 43],
            ['category' => '恐怖', 'cnt' => 28],
            ['category' => '科幻', 'cnt' => 22],
        ];
    }

    private function mockTitleFromQuestion(string $question): string
    {
        if (mb_strlen($question) > 20) {
            return mb_substr($question, 0, 20) . '...';
        }
        return $question;
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

    private function fallbackGuzzleStream(
        array $payload,
        bool $wantsChat2viz = false,
        string $conversationId = '',
        ?StreamAccumulator $accumulator = null,
        ?int $assistantMessageId = null
    ): bool {
        $headers = $this->buildHeaders();
        $timeout = (int) env('CHAT2VIZ_SSE_TIMEOUT', 180);

        // SSE headers may already be set by SseProxy::socket() or need to be
        // sent now.  Either way, all error exits from this method must be SSE
        // events — never ajaxReturn() (which would set application/json).
        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();
        $sseWriter = new SseWriter(autoStart: false);

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
            $sseWriter->sendError('service_unavailable', '分析服务不可用');
            return false;
        } catch (RequestException $e) {
            $this->logError('stream request failed', 'RequestException');
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        } catch (GuzzleException $e) {
            $this->logError('stream guzzle error', 'GuzzleException');
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logError('stream non-200', sprintf('status=%d', $response->getStatusCode()));
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        }

        // Capture variables for closure use in anonymous handler classes
        $ctrl = $this;
        $acc = $accumulator;
        $convId = $conversationId;

        if ($wantsChat2viz) {
            $transformer = new Nl2sqlEventTransformer($conversationId);
            $handler = new class($transformer, $sseWriter, $acc, $convId, $ctrl) implements SseEventHandler {
                public function __construct(
                    private Nl2sqlEventTransformer $transformer,
                    private SseWriter $writer,
                    private ?StreamAccumulator $accumulator,
                    private string $conversationId,
                    private Chat2VizController $controller
                ) {}

                public function onEvent(SseEvent $event): void
                {
                    if (connection_aborted()) {
                        return;
                    }
                    foreach ($this->transformer->transform($event) as $mapped) {
                        if ($this->accumulator !== null) {
                            $this->controller->routeEventToAccumulator(
                                $this->accumulator,
                                $this->conversationId,
                                $mapped
                            );
                        }
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
            $passthrough = new SsePassthrough($sseWriter);
            $handler = new class($passthrough, $sseWriter) implements SseEventHandler {
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
            if (!is_array($ctx)) {
                return $payload;
            }
            // Validate dashboard_context structure: dashboard_uid must be a non-empty string if present
            if (isset($ctx['dashboard_uid']) && !is_string($ctx['dashboard_uid'])) {
                return $payload;
            }
            // widgets must be array or object if present
            if (isset($ctx['widgets']) && !is_array($ctx['widgets']) && !($ctx['widgets'] instanceof \stdClass)) {
                return $payload;
            }
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

            $repo = AdapterFactory::createMessageRepository();
            $repo->createMessage($conversationId, $dashboardUid, $role, $content);
        } catch (\Throwable $e) {
            $this->logError('persist message failed', $e->getMessage());
        }
    }

    /**
     * Ensure an active conversation exists for the given dashboard.
     *
     * If no active conversation is found, creates one and returns its ID.
     * If one exists, returns the existing conversation's ID (as a string).
     *
     * @return string The conversation ID to use
     */
    private function ensureConversation(string $conversationId, string $dashboardUid): string
    {
        // If no dashboard context, we cannot create a conversation record.
        // Return the generated ID as-is (used only for SSE conversation_id).
        if ($dashboardUid === '') {
            return $conversationId;
        }

        try {
            $convRepo = AdapterFactory::createConversationRepository();
            $active = $convRepo->findActiveByDashboardUid($dashboardUid);

            if ($active !== null) {
                // Reuse existing active conversation, but adopt its internal ID
                // for consistency. The string conversation_id from the DB row is used.
                return (string) $active['id'];
            }

            // No active conversation — create one
            $title = mb_substr($conversationId, 0, 32);
            $conv = $convRepo->createConversation($dashboardUid, $title);
            return (string) $conv['id'];
        } catch (\Throwable $e) {
            $this->logError('ensure conversation failed', $e->getMessage());
            return $conversationId;
        }
    }

    /**
     * Pre-allocate an assistant message row with message_status='streaming'.
     *
     * @return int|null The message ID, or null if creation failed
     */
    private function preallocateAssistantMessage(string $conversationId, string $dashboardUid): ?int
    {
        try {
            $repo = AdapterFactory::createMessageRepository();
            return $repo->createPreallocatedAssistantMessage($conversationId, $dashboardUid);
        } catch (\Throwable $e) {
            $this->logError('preallocate assistant message failed', $e->getMessage());
            return null;
        }
    }

    /**
     * Route a transformed SSE event to the appropriate StreamAccumulator method.
     *
     * Event type mapping (from Nl2sqlEventTransformer output):
     *   answer        -> accumulateAnswer (append text)
     *   sql_generated -> accumulateSql
     *   chart_ready   -> accumulateG2Spec + accumulateChartMeta
     *   action_call   -> accumulateActionCall
     *   action_call_result -> (no accumulation, result is informational)
     *   data_preview  -> (no accumulation)
     *   dashboard_patch -> (no accumulation)
     *   done          -> (handled by finalizeStream)
     *   error         -> (no accumulation)
     *   conversation_id -> (no accumulation)
     */
    public function routeEventToAccumulator(
        StreamAccumulator $accumulator,
        string $conversationId,
        SseEvent $event
    ): void {
        if (!$accumulator->isRedisAvailable()) {
            return;
        }

        $data = $event->data;

        switch ($event->type) {
            case 'answer':
                $text = $data['text'] ?? '';
                if ($text !== '') {
                    $accumulator->accumulateAnswer($conversationId, $text);
                }
                break;

            case 'sql_generated':
                $sql = $data['sql'] ?? '';
                if ($sql !== '') {
                    $accumulator->accumulateSql($conversationId, $sql);
                }
                break;

            case 'chart_ready':
                $g2Spec = $data['g2_spec'] ?? null;
                if (is_array($g2Spec)) {
                    $accumulator->accumulateG2Spec($conversationId, $g2Spec);
                }
                $chartType = $data['chart_type'] ?? '';
                $widgetId = $data['id'] ?? $data['widget_id'] ?? '';
                if ($chartType !== '' || $widgetId !== '') {
                    $accumulator->accumulateChartMeta($conversationId, (string) $chartType, (string) $widgetId);
                }
                break;

            case 'action_call':
                $accumulator->accumulateActionCall($conversationId, [
                    'action_type' => $data['action_type'] ?? '',
                    'params'      => $data['params'] ?? [],
                ]);
                break;

            case 'tool_call':
                $toolCall = $data;
                if (!empty($toolCall)) {
                    $accumulator->accumulateToolCall($conversationId, $toolCall);
                }
                break;

            case 'reasoning':
                $text = $data['text'] ?? '';
                if ($text !== '') {
                    $accumulator->accumulateReasoning($conversationId, $text);
                }
                break;

            default:
                // No accumulation for: conversation_id, done, error, action_call_result,
            // data_preview, dashboard_patch, comment/heartbeat events
                break;
        }
    }

    /**
     * Finalize the stream: flush Redis accumulator to DB, update message status.
     *
     * @param StreamAccumulator $accumulator
     * @param string            $conversationId
     * @param int|null          $messageId Pre-allocated assistant message ID
     * @param string            $status    One of: complete, interrupted, failed
     */
    private function finalizeStream(
        StreamAccumulator $accumulator,
        string $conversationId,
        ?int $messageId,
        string $status
    ): void {
        // Always cleanup Redis key, even if no messageId
        try {
            $accumulated = $accumulator->flush($conversationId);
            $accumulator->cleanup($conversationId);
        } catch (\Throwable $e) {
            $this->logError('accumulator flush/cleanup failed', $e->getMessage());
            $accumulated = [
                'content'           => '',
                'metadata'          => [],
                'reasoning_content' => '',
                'tool_calls'        => [],
            ];
        }

        if ($messageId === null) {
            // No pre-allocated message — nothing to update in DB.
            // Fall back to the legacy persist method if we have content.
            if ($accumulated['content'] !== '' && $status === 'complete') {
                $payload = ['dashboard_context' => ['dashboard_uid' => '']];
                $this->persistConversationMessage($conversationId, $payload, 'assistant');
            }
            return;
        }

        try {
            $repo = AdapterFactory::createMessageRepository();
            $repo->updateMessageWithMetadata(
                $messageId,
                $accumulated['content'],
                !empty($accumulated['metadata']) ? $accumulated['metadata'] : null,
                $accumulated['reasoning_content'] !== '' ? $accumulated['reasoning_content'] : null,
                !empty($accumulated['tool_calls']) ? $accumulated['tool_calls'] : null,
                $status
            );
        } catch (\Throwable $e) {
            $this->logError('finalize stream DB update failed', $e->getMessage());

            // Last resort: try to mark message as failed
            try {
                $repo = AdapterFactory::createMessageRepository();
                $repo->updateMessageWithMetadata($messageId, null, null, null, null, $status);
            } catch (\Throwable $e2) {
                $this->logError('finalize stream status-only update failed', $e2->getMessage());
            }
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
     * Create a new conversation for a dashboard.
     *
     * 1. Archive the current active conversation for this dashboard (status=0)
     * 2. Create a new conversation via ConversationRepository
     * 3. Return conversation_id
     *
     * URL: POST /extends/Chat2Viz/api_conversation_create
     */
    public function api_conversation_create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return;
        }

        $input = $this->parseJsonInput();
        if (!is_array($input)) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
            return;
        }

        $dashboardUid = trim((string) ($input['dashboard_uid'] ?? ''));
        if ($dashboardUid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }

        try {
            $convRepo = AdapterFactory::createConversationRepository();

            // Archive current active conversation if one exists
            $active = $convRepo->findActiveByDashboardUid($dashboardUid);
            if ($active !== null) {
                $convRepo->archive((int) $active['id']);
            }

            // Create new conversation
            $title = trim((string) ($input['title'] ?? ''));
            $conversation = $convRepo->createConversation($dashboardUid, $title);

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

    /**
     * Get conversation history for a dashboard.
     *
     * FULL REWRITE: finds the active conversation via conversations table
     * (not dashboards.conversation_id which is being removed).
     *
     * Loads messages with all fields: content, metadata, reasoning_content,
     * tool_calls, message_status.
     *
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

        try {
            $convRepo = AdapterFactory::createConversationRepository();

            // Find active conversation for this dashboard
            $active = $convRepo->findActiveByDashboardUid($uid);
            if ($active === null) {
                $this->ajaxReturn(['status' => 1, 'data' => [
                    'conversation_id' => null,
                    'messages'        => [],
                ]]);
                return;
            }

            $conversationId = (string) $active['id'];

            // Load full message history with all fields
            $msgRepo = AdapterFactory::createMessageRepository();
            $messages = $msgRepo->findConversationHistory($conversationId);

            $this->ajaxReturn(['status' => 1, 'data' => [
                'conversation_id' => $conversationId,
                'messages'        => $messages,
            ]]);
        } catch (\Throwable $e) {
            $this->logError('conversation history failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取对话历史失败']);
        }
    }

    /**
     * List all conversations for a dashboard.
     *
     * Returns conversations ordered by created_at DESC, each with title, status,
     * and a derived message count (LEFT JOIN messages, not stored).
     *
     * URL: GET /extends/Chat2Viz/api_conversation_list?uid={uid}
     */
    public function api_conversation_list()
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

        try {
            $convRepo = AdapterFactory::createConversationRepository();
            $conversations = $convRepo->findByDashboardUid($uid);

            // Enrich each conversation with derived message count
            $msgRepo = AdapterFactory::createMessageRepository();
            $enriched = [];
            foreach ($conversations as $conv) {
                $convId = (string) $conv['id'];
                $enriched[] = [
                    'id'            => $conv['id'],
                    'dashboard_uid' => $conv['dashboard_uid'] ?? $uid,
                    'title'         => $conv['title'] ?? '',
                    'status'        => (int) ($conv['status'] ?? 1),
                    'message_count' => $msgRepo->countByConversationId($convId),
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
}
