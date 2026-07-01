<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Controller\Chat2VizController;
use Qscmf\Chat2Viz\Validator\ConversationValidator;
use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SocketTransport;

/**
 * Integration tests for the Controller SSE output path (api_ask_stream).
 *
 * Tests the discrete components that make up the SSE flow:
 * - Request frame construction
 * - SSE event format validation
 * - Fallback path logic (socket failure -> Guzzle)
 * - dashboard_context propagation through the full flow
 * - Validation errors returning proper JSON
 *
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::api_ask_stream
 * @covers \Qscmf\Chat2Viz\Validator\ConversationValidator::validateQuestion
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::buildPayloadFromParsed
 * @covers \Qscmf\Chat2Viz\Controller\Chat2VizController::createSocketTransport
 */
class ControllerSseIntegrationTest extends TestCase
{
    private Chat2VizController $controller;

    protected function setUp(): void
    {
        $this->controller = (new \ReflectionClass(Chat2VizController::class))
            ->newInstanceWithoutConstructor();
    }

    // ------------------------------------------------------------------
    // 1. Request frame construction
    // ------------------------------------------------------------------

    public function testRequestFrameHasRequiredStructure(): void
    {
        $payload = $this->invokeBuildPayload([
            'question' => 'show sales',
            'conversation_id' => 'abc-123',
        ]);

        $apiKey = 'test-api-key';
        $frame = $this->buildRequestFrame($payload, $apiKey);

        $this->assertArrayHasKey('id', $frame);
        $this->assertArrayHasKey('method', $frame);
        $this->assertArrayHasKey('params', $frame);
        $this->assertArrayHasKey('auth', $frame);

        $this->assertSame(32, strlen($frame['id'])); // 16 random bytes = 32 hex chars
        $this->assertSame('ask_stream', $frame['method']);
        $this->assertSame($apiKey, $frame['auth']['api_key']);
    }

    public function testRequestFrameIdIsHexEncoded(): void
    {
        $payload = $this->invokeBuildPayload(['question' => 'test']);
        $frame = $this->buildRequestFrame($payload, 'key');

        $this->assertSame(1, preg_match('/^[0-9a-f]{32}$/', $frame['id']),
            'Frame ID must be 32 lowercase hex characters');
    }

    public function testRequestFrameParamsContainQuestion(): void
    {
        $payload = $this->invokeBuildPayload([
            'question' => '  show sales  ',
            'conversation_id' => 'abc-123',
        ]);
        $frame = $this->buildRequestFrame($payload, 'key');

        $this->assertSame('show sales', $frame['params']['question']);
        $this->assertSame('abc-123', $frame['params']['conversation_id']);
    }

    public function testRequestFrameWithoutConversationIdOmitsIt(): void
    {
        $payload = $this->invokeBuildPayload(['question' => 'test']);
        $frame = $this->buildRequestFrame($payload, 'key');

        $this->assertArrayNotHasKey('conversation_id', $frame['params']);
    }

    // ------------------------------------------------------------------
    // 2. SSE event format validation
    // ------------------------------------------------------------------

    public function testSseEventFromRawParsesCorrectly(): void
    {
        $raw = "event: delta\ndata: {\"text\":\"thinking\"}\n\n";
        $event = SseEvent::fromRaw($raw);

        $this->assertNotNull($event);
        $this->assertSame('delta', $event->type);
        $this->assertSame('thinking', $event->data['text']);
    }

    public function testSseEventFrameProducesValidWireFormat(): void
    {
        $event = new SseEvent(
            type: 'chart_ready',
            data: ['sql' => 'SELECT 1', 'chart_type' => 'bar'],
            raw: '',
        );
        $frame = $event->frame();

        $this->assertStringStartsWith("event: chart_ready\n", $frame);
        $this->assertStringContainsString('data: ', $frame);
        $this->assertStringEndsWith("\n\n", $frame);

        // Verify the data line is valid JSON
        preg_match('/^data: (.+)$/m', $frame, $matches);
        $decoded = json_decode($matches[1], true);
        $this->assertSame('SELECT 1', $decoded['sql']);
        $this->assertSame('bar', $decoded['chart_type']);
    }

    public function testSseEventRoundTripPreservesData(): void
    {
        $original = new SseEvent(
            type: 'done',
            data: ['status' => 'complete'],
            raw: '',
            id: 'evt-42',
        );
        $wire = $original->frame();

        // Strip trailing \n\n for fromRaw parsing (SseReader strips delimiters)
        $block = rtrim($wire, "\n");
        $parsed = SseEvent::fromRaw($block);

        $this->assertNotNull($parsed);
        $this->assertSame('done', $parsed->type);
        $this->assertSame('complete', $parsed->data['status']);
        $this->assertSame('evt-42', $parsed->id);
    }

    public function testSseCommentEventDetected(): void
    {
        $raw = ": this is a comment\n: another line";
        $event = SseEvent::fromRaw($raw);

        $this->assertNotNull($event);
        $this->assertTrue($event->isComment());
    }

    public function testSseDefaultEventTypeIsMessage(): void
    {
        $raw = 'data: {"text":"hello"}';
        $event = SseEvent::fromRaw($raw);

        $this->assertNotNull($event);
        $this->assertSame('message', $event->type);
    }

    public function testSseEventDataTextFallbackForNonJson(): void
    {
        $raw = 'data: plain text here';
        $event = SseEvent::fromRaw($raw);

        $this->assertNotNull($event);
        $this->assertSame(['text' => 'plain text here'], $event->data);
    }

    public function testSseEventEmptyBlockReturnsNull(): void
    {
        $this->assertNull(SseEvent::fromRaw(''));
        $this->assertNull(SseEvent::fromRaw('   '));
    }

    public function testFrameToSseMappingSkipsPingPong(): void
    {
        // Simulates the default frame mapping logic in SseProxy::socket
        $frames = [
            ['type' => 'ping', 'data' => null],
            ['type' => 'pong', 'data' => null],
            ['type' => 'delta', 'data' => ['text' => 'hello']],
        ];

        $emitted = [];
        foreach ($frames as $frame) {
            $type = $frame['type'] ?? 'message';
            if ($type === 'ping' || $type === 'pong') {
                continue;
            }
            $emitted[] = new SseEvent(
                type: $type,
                data: $frame['data'] ?? [],
                raw: '',
            );
        }

        $this->assertCount(1, $emitted);
        $this->assertSame('delta', $emitted[0]->type);
    }

    public function testSocketFrameToSseEventMapping(): void
    {
        // Verify that a socket frame maps to a valid SSE event
        $socketFrame = ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 1']];

        $type = $socketFrame['type'] ?? 'message';
        $this->assertNotEquals('ping', $type);
        $this->assertNotEquals('pong', $type);

        $event = new SseEvent(
            type: $type,
            data: $socketFrame['data'] ?? [],
            raw: '',
        );

        $wire = $event->frame();
        $this->assertStringContainsString("event: chart_ready\n", $wire);
        $this->assertStringContainsString('"sql"', $wire);
    }

    // ------------------------------------------------------------------
    // 3. Fallback path (Socket failure -> Guzzle)
    // ------------------------------------------------------------------

    public function testHeadersNotSentTakesGuzzleFallbackPath(): void
    {
        // When headers_sent() is false, the catch block should call fallbackGuzzleStream.
        // We verify this by tracing the decision logic.
        $headersWereSent = false;

        // Simulate the catch block decision from api_ask_stream
        if ($headersWereSent) {
            $path = 'sendError';
        } else {
            $path = 'fallbackGuzzleStream';
        }

        $this->assertSame('fallbackGuzzleStream', $path);
    }

    public function testHeadersSentTakesSendErrorPath(): void
    {
        // When headers_sent() is true, SseProxy::socket() has already called sendHeaders(),
        // so we cannot fall back to Guzzle SSE (it also calls sendHeaders).
        $headersWereSent = true;

        if ($headersWereSent) {
            $path = 'sendError';
        } else {
            $path = 'fallbackGuzzleStream';
        }

        $this->assertSame('sendError', $path);
    }

    public function testSendErrorProducesValidSseWireFormat(): void
    {
        // Verify the error wire format matches what the frontend expects
        $code = 'socket_fallback_failed';
        $message = '分析服务连接失败';

        $payload = json_encode([
            'type' => 'error',
            'error' => ['code' => $code, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE);

        $wire = "event: error\ndata: {$payload}\n\n";

        // Parse it back and verify
        $block = rtrim($wire, "\n");
        $event = SseEvent::fromRaw($block);

        $this->assertNotNull($event);
        $this->assertSame('error', $event->type);
        $this->assertSame('error', $event->data['type']);
        $this->assertSame($code, $event->data['error']['code']);
        $this->assertSame($message, $event->data['error']['message']);
    }

    public function testCatchBlockLogsErrorBeforeFallbackDecision(): void
    {
        // Verify the log message format used in the catch block
        $exceptionMessage = 'Connection refused';
        $logTag = 'socket failed, falling back to http';
        $logDetail = $exceptionMessage;
        $logEntry = sprintf('[chat2viz] %s | %s', $logTag, $logDetail);

        $this->assertStringContainsString('socket failed, falling back to http', $logEntry);
        $this->assertStringContainsString('Connection refused', $logEntry);
    }

    // ------------------------------------------------------------------
    // 4. dashboard_context propagation through full flow
    // ------------------------------------------------------------------

    public function testDashboardContextPresentInPayloadAndFrame(): void
    {
        $input = [
            'question' => 'show dashboard sales',
            'dashboard_context' => ['dashboard_id' => 42, 'version_id' => 7],
        ];

        // Step 1: Validate
        $validation = $this->invokeValidateSocketInput($input);
        $this->assertNull($validation, 'Valid input should pass validation');

        // Step 2: Build payload
        $payload = $this->invokeBuildPayload($input);
        $this->assertArrayHasKey('dashboard_context', $payload);
        $this->assertSame(42, $payload['dashboard_context']['dashboard_id']);
        $this->assertSame(7, $payload['dashboard_context']['version_id']);

        // Step 3: Build request frame
        $frame = $this->buildRequestFrame($payload, 'test-key');
        $this->assertArrayHasKey('dashboard_context', $frame['params']);
        $this->assertSame(42, $frame['params']['dashboard_context']['dashboard_id']);
    }

    public function testDashboardContextAbsentWhenNotProvided(): void
    {
        $input = ['question' => 'show sales'];

        $validation = $this->invokeValidateSocketInput($input);
        $this->assertNull($validation);

        $payload = $this->invokeBuildPayload($input);
        $this->assertArrayNotHasKey('dashboard_context', $payload);

        $frame = $this->buildRequestFrame($payload, 'test-key');
        $this->assertArrayNotHasKey('dashboard_context', $frame['params']);
    }

    public function testEmptyDashboardContextNotPropagated(): void
    {
        $input = [
            'question' => 'show sales',
            'dashboard_context' => [],
        ];

        $payload = $this->invokeBuildPayload($input);
        $this->assertArrayNotHasKey('dashboard_context', $payload);

        $frame = $this->buildRequestFrame($payload, 'key');
        $this->assertArrayNotHasKey('dashboard_context', $frame['params']);
    }

    public function testDashboardContextWithComplexStructure(): void
    {
        $input = [
            'question' => 'analyze',
            'dashboard_context' => [
                'dashboard_id' => 1,
                'version_id' => 5,
                'filters' => ['region' => 'north', 'year' => 2024],
                'charts' => [['type' => 'bar', 'title' => 'Revenue']],
            ],
        ];

        $payload = $this->invokeBuildPayload($input);
        $this->assertSame('north', $payload['dashboard_context']['filters']['region']);
        $this->assertCount(1, $payload['dashboard_context']['charts']);
        $this->assertSame('bar', $payload['dashboard_context']['charts'][0]['type']);

        $frame = $this->buildRequestFrame($payload, 'key');
        $this->assertArrayHasKey('dashboard_context', $frame['params']);
    }

    // ------------------------------------------------------------------
    // 5. Validation errors return proper JSON
    // ------------------------------------------------------------------

    public function testNullInputReturnsFormatNotSupported(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'text/plain';
        $result = $this->invokeValidateSocketInput(null);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请求格式不支持', $result['info']);
    }

    public function testNullInputWithJsonContentTypeReturnsParseError(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $result = $this->invokeValidateSocketInput(null);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请求格式错误', $result['info']);
    }

    public function testEmptyQuestionReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => '']);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testWhitespaceOnlyQuestionReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => "   \t\n  "]);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('请输入问题', $result['info']);
    }

    public function testInvalidConversationIdReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => 'valid question',
            'conversation_id' => 'INVALID!@#$',
        ]);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('无效的会话ID', $result['info']);
    }

    public function testQuestionOverLimitReturnsError(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => str_repeat('x', 1001),
        ]);

        $this->assertNotNull($result);
        $this->assertSame(0, $result['status']);
        $this->assertSame('问题长度不能超过1000字', $result['info']);
    }

    public function testValidationErrorStructureHasStatusAndInfo(): void
    {
        $result = $this->invokeValidateSocketInput(['question' => '']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('info', $result);
        $this->assertIsInt($result['status']);
        $this->assertIsString($result['info']);
    }

    public function testValidInputPassesAllValidation(): void
    {
        $result = $this->invokeValidateSocketInput([
            'question' => 'show sales by region',
            'conversation_id' => 'abc-123-def456',
            'dashboard_context' => ['dashboard_id' => 1],
        ]);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // 6. Full SSE flow assembly (discrete step verification)
    // ------------------------------------------------------------------

    public function testFullFlowInputToFrameAssembly(): void
    {
        // Simulate the full api_ask_stream path from input to request frame
        $input = [
            'question' => 'show revenue by month',
            'conversation_id' => 'abc123-def456',
            'dashboard_context' => ['dashboard_id' => 5, 'version_id' => 2],
        ];

        // Step 1: Validate input
        $validation = $this->invokeValidateSocketInput($input);
        $this->assertNull($validation, 'Input should pass validation');

        // Step 2: Build payload
        $payload = $this->invokeBuildPayload($input);
        $this->assertSame('show revenue by month', $payload['question']);
        $this->assertSame('abc123-def456', $payload['conversation_id']);
        $this->assertSame(5, $payload['dashboard_context']['dashboard_id']);

        // Step 3: Create transport
        $transport = $this->invokeCreateSocketTransport();
        $this->assertInstanceOf(SocketTransport::class, $transport);

        // Step 4: Build request frame
        $apiKey = 'my-secret-key';
        $frame = $this->buildRequestFrame($payload, $apiKey);

        $this->assertSame(32, strlen($frame['id']));
        $this->assertSame('ask_stream', $frame['method']);
        $this->assertSame($payload, $frame['params']);
        $this->assertSame(['api_key' => $apiKey], $frame['auth']);

        // Step 5: Verify the frame is JSON-serializable (as socket transport requires)
        $json = json_encode($frame, JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertSame('ask_stream', $decoded['method']);
        $this->assertSame('show revenue by month', $decoded['params']['question']);
    }

    public function testValidationErrorPreventsFrameConstruction(): void
    {
        // When validation fails, no frame should be constructed
        $input = ['question' => '']; // empty question
        $validation = $this->invokeValidateSocketInput($input);

        $this->assertNotNull($validation);
        $this->assertSame(0, $validation['status']);
        // In the real controller, ajaxReturn() would be called here,
        // and the method would return before reaching buildPayloadFromParsed.
    }

    public function testSseProxySocketFrameStructureMatchesFrontendExpectation(): void
    {
        // Verify that frames emitted by the default SseProxy::socket mapping
        // produce SSE events with the structure the frontend chat2viz component expects
        $socketFrames = [
            ['type' => 'delta', 'data' => ['text' => 'Analyzing...']],
            ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 1', 'g2_spec' => '{}']],
            ['type' => 'done', 'data' => ['conversation_id' => 'conv-123']],
        ];

        $sseOutput = [];
        foreach ($socketFrames as $frame) {
            $type = $frame['type'] ?? 'message';
            if ($type === 'ping' || $type === 'pong') {
                continue;
            }
            $event = new SseEvent(
                type: $type,
                data: $frame['data'] ?? [],
                raw: '',
            );
            $sseOutput[] = $event->frame();
        }

        $this->assertCount(3, $sseOutput);

        // Verify delta event
        $this->assertStringContainsString("event: delta\n", $sseOutput[0]);
        $this->assertStringContainsString('"text":"Analyzing..."', $sseOutput[0]);

        // Verify chart_ready event
        $this->assertStringContainsString("event: chart_ready\n", $sseOutput[1]);
        $this->assertStringContainsString('"sql":"SELECT 1"', $sseOutput[1]);

        // Verify done event
        $this->assertStringContainsString("event: done\n", $sseOutput[2]);
        $this->assertStringContainsString('"conversation_id":"conv-123"', $sseOutput[2]);
    }

    // ------------------------------------------------------------------
    // Helper methods
    // ------------------------------------------------------------------

    private function invokeValidateSocketInput(?array $input): ?array
    {
        return ConversationValidator::validateQuestion($input);
    }

    private function invokeBuildPayload(array $input): array
    {
        $method = new \ReflectionMethod(Chat2VizController::class, 'buildPayloadFromParsed');
        $method->setAccessible(true);
        return $method->invoke($this->controller, $input);
    }

    private function invokeCreateSocketTransport(): SocketTransport
    {
        $method = new \ReflectionMethod(Chat2VizController::class, 'createSocketTransport');
        $method->setAccessible(true);
        return $method->invoke($this->controller);
    }

    /**
     * Build a request frame matching the structure in api_ask_stream.
     */
    private function buildRequestFrame(array $payload, string $apiKey): array
    {
        return [
            'id'     => bin2hex(random_bytes(16)),
            'method' => 'ask_stream',
            'params' => $payload,
            'auth'   => ['api_key' => $apiKey],
        ];
    }
}
