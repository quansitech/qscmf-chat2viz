<?php
declare(strict_types=1);
namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sse\Nl2sqlEventTransformer;
use Qscmf\SseCore\SseEvent;

class Nl2sqlEventTransformerTest extends TestCase
{
    private const PHP_CID = 'php-conv-abc123';

    private Nl2sqlEventTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new Nl2sqlEventTransformer(self::PHP_CID);
    }

    private function makeEvent(string $type, array $data = []): SseEvent
    {
        return new SseEvent(type: $type, data: $data, raw: '');
    }

    // 1. message_start → conversation_id comes from PHP (Python's ID is silently discarded)
    public function testMessageStartReturnsPhpConversationId(): void
    {
        $event = $this->makeEvent('message_start', ['conversation_id' => 'python-ignored-id']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('conversation_id', $result[0]->type);
        $this->assertSame(['conversation_id' => self::PHP_CID], $result[0]->data);
    }

    // 2. message_start without conversation_id in event data → still returns PHP's ID
    public function testMessageStartWithoutConversationIdReturnsPhpId(): void
    {
        $event = $this->makeEvent('message_start', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('conversation_id', $result[0]->type);
        $this->assertSame(['conversation_id' => self::PHP_CID], $result[0]->data);
    }

    public function testMessageStartWithEmptyConversationIdReturnsPhpId(): void
    {
        $event = $this->makeEvent('message_start', ['conversation_id' => '']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('conversation_id', $result[0]->type);
        $this->assertSame(['conversation_id' => self::PHP_CID], $result[0]->data);
    }

    // Edge case: empty PHP conversation_id → no event emitted
    public function testMessageStartWithEmptyPhpConversationIdReturnsNothing(): void
    {
        $transformer = new Nl2sqlEventTransformer('');
        $event = $this->makeEvent('message_start', ['conversation_id' => 'python-id']);
        $result = $transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 3. content_block_delta with text → produces answer event
    public function testContentBlockDeltaWithText(): void
    {
        $event = $this->makeEvent('content_block_delta', ['delta' => ['text' => 'Hello world']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('answer', $result[0]->type);
        $this->assertSame(['text' => 'Hello world'], $result[0]->data);
    }

    // 4. content_block_delta with empty text → empty array
    public function testContentBlockDeltaWithEmptyText(): void
    {
        $event = $this->makeEvent('content_block_delta', ['delta' => ['text' => '']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    public function testContentBlockDeltaWithMissingDelta(): void
    {
        $event = $this->makeEvent('content_block_delta', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 5. chart_ready without id → auto-generates 16-char hex id
    public function testChartReadyWithoutId(): void
    {
        $event = $this->makeEvent('chart_ready', ['chart_type' => 'bar']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('chart_ready', $result[0]->type);
        $this->assertSame('bar', $result[0]->data['chart_type']);
        $this->assertArrayHasKey('id', $result[0]->data);
        $this->assertSame(16, strlen($result[0]->data['id']));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $result[0]->data['id']);
    }

    // 6. chart_ready with existing id → preserves existing id
    public function testChartReadyWithExistingId(): void
    {
        $event = $this->makeEvent('chart_ready', ['id' => 'my-custom-id', 'chart_type' => 'line']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('chart_ready', $result[0]->type);
        $this->assertSame('my-custom-id', $result[0]->data['id']);
        $this->assertSame('line', $result[0]->data['chart_type']);
    }

    public function testChartReadyWithEmptyId(): void
    {
        $event = $this->makeEvent('chart_ready', ['id' => '']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('id', $result[0]->data);
        $this->assertSame(16, strlen($result[0]->data['id']));
    }

    // 7. message_stop → produces done event
    public function testMessageStop(): void
    {
        $event = $this->makeEvent('message_stop');
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('done', $result[0]->type);
        $this->assertSame([], $result[0]->data);
    }

    // 8. tool_start → produces action_call with action_type and params
    public function testToolStart(): void
    {
        $event = $this->makeEvent('tool_start', ['tool_name' => 'execute_sql', 'tool_args' => ['query' => 'SELECT 1']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('action_call', $result[0]->type);
        $this->assertSame('execute_sql', $result[0]->data['action_type']);
        $this->assertSame(['query' => 'SELECT 1'], $result[0]->data['params']);
    }

    public function testToolStartWithMissingFields(): void
    {
        $event = $this->makeEvent('tool_start', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('action_call', $result[0]->type);
        $this->assertSame('', $result[0]->data['action_type']);
        $this->assertSame([], $result[0]->data['params']);
    }

    // 9. tool_result → produces action_call_result with success and result
    public function testToolResult(): void
    {
        $event = $this->makeEvent('tool_result', ['summary' => 'Query returned 5 rows']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('action_call_result', $result[0]->type);
        $this->assertTrue($result[0]->data['success']);
        $this->assertSame('Query returned 5 rows', $result[0]->data['result']);
    }

    public function testToolResultWithMissingSummary(): void
    {
        $event = $this->makeEvent('tool_result', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->data['success']);
        $this->assertSame('', $result[0]->data['result']);
    }

    // 10. sql_ready → produces sql_generated event
    public function testSqlReady(): void
    {
        $event = $this->makeEvent('sql_ready', ['sql' => 'SELECT * FROM users LIMIT 10']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('sql_generated', $result[0]->type);
        $this->assertSame(['sql' => 'SELECT * FROM users LIMIT 10'], $result[0]->data);
    }

    public function testSqlReadyWithMissingSql(): void
    {
        $event = $this->makeEvent('sql_ready', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('sql_generated', $result[0]->type);
        $this->assertSame(['sql' => ''], $result[0]->data);
    }

    // 11. data_ready → produces data_preview event (passthrough)
    public function testDataReady(): void
    {
        $data = ['columns' => ['id', 'name'], 'rows' => [[1, 'Alice'], [2, 'Bob']]];
        $event = $this->makeEvent('data_ready', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('data_preview', $result[0]->type);
        $this->assertSame($data, $result[0]->data);
    }

    // 12. error with object {error:{message}} → info contains message
    public function testErrorWithObjectMessage(): void
    {
        $event = $this->makeEvent('error', ['error' => ['message' => 'Connection timeout']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('error', $result[0]->type);
        $this->assertSame(['info' => 'Connection timeout'], $result[0]->data);
    }

    // 13. error with string → info contains the string
    public function testErrorWithString(): void
    {
        $event = $this->makeEvent('error', ['error' => 'Something went wrong']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('error', $result[0]->type);
        $this->assertSame(['info' => 'Something went wrong'], $result[0]->data);
    }

    // 14. error with null/missing → info = "未知错误"
    public function testErrorWithNull(): void
    {
        $event = $this->makeEvent('error', ['error' => null]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('error', $result[0]->type);
        $this->assertSame(['info' => '未知错误'], $result[0]->data);
    }

    public function testErrorWithMissingErrorKey(): void
    {
        $event = $this->makeEvent('error', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame(['info' => '未知错误'], $result[0]->data);
    }

    public function testErrorWithObjectWithoutMessage(): void
    {
        $event = $this->makeEvent('error', ['error' => ['code' => 500]]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame(['info' => '未知错误'], $result[0]->data);
    }

    // 15. content_block_start → empty array
    public function testContentBlockStartSkipped(): void
    {
        $event = $this->makeEvent('content_block_start', ['index' => 0]);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 16. content_block_stop → empty array
    public function testContentBlockStopSkipped(): void
    {
        $event = $this->makeEvent('content_block_stop', ['index' => 0]);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 17. message_delta → empty array
    public function testMessageDeltaSkipped(): void
    {
        $event = $this->makeEvent('message_delta', ['delta' => ['stop_reason' => 'end_turn']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 18. unknown event type → empty array
    public function testUnknownEventTypeDropped(): void
    {
        $event = $this->makeEvent('some_unknown_type', ['foo' => 'bar']);
        $result = $this->transformer->transform($event);

        $this->assertCount(0, $result);
    }

    // 19. comment event (isComment) → passes through unchanged
    public function testCommentEventPassesThrough(): void
    {
        $commentEvent = new SseEvent(type: '', data: [], raw: ': heartbeat', id: null);
        $this->assertTrue($commentEvent->isComment());

        $result = $this->transformer->transform($commentEvent);

        $this->assertCount(1, $result);
        $this->assertSame($commentEvent, $result[0]);
    }

    // 20. statelessness: 100 consecutive calls produce independent results
    public function testStatelessnessAcrossCalls(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $event = $this->makeEvent('chart_ready', ['chart_type' => 'bar']);
            $result = $this->transformer->transform($event);
            $this->assertCount(1, $result);
            $this->assertSame('chart_ready', $result[0]->type);
            $ids[] = $result[0]->data['id'];
        }

        // All 100 calls produced results (no state accumulation)
        $this->assertCount(100, $ids);

        // Each generated id is unique (random_bytes produces unique values)
        $uniqueIds = array_unique($ids);
        $this->assertCount(100, $uniqueIds);

        // Verify mixing event types also works without state issues
        // PHP conversation_id is always used, regardless of what Python sends
        $event1 = $this->makeEvent('message_start', ['conversation_id' => 'conv-A']);
        $result1 = $this->transformer->transform($event1);
        $this->assertSame('conversation_id', $result1[0]->type);

        $event2 = $this->makeEvent('message_stop');
        $result2 = $this->transformer->transform($event2);
        $this->assertSame('done', $result2[0]->type);

        $event3 = $this->makeEvent('message_start', ['conversation_id' => 'conv-B']);
        $result3 = $this->transformer->transform($event3);
        $this->assertSame('conversation_id', $result3[0]->type);
        $this->assertSame(self::PHP_CID, $result3[0]->data['conversation_id']);
    }
}
