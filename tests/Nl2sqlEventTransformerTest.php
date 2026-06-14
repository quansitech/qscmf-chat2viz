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

    // 5. chart_ready mapping removed: now falls through to default passthrough
    //    (warning + forward), with NO synthetic id injection.
    public function testChartReadyPassthroughDoesNotInjectId(): void
    {
        $event = $this->makeEvent('chart_ready', ['chart_type' => 'bar']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('chart_ready', $result[0]->type);
        $this->assertSame('bar', $result[0]->data['chart_type']);
        // Passthrough does NOT synthesize an id (the mapChartReady path was removed)
        $this->assertArrayNotHasKey('id', $result[0]->data);
    }

    // 6. chart_ready passthrough: existing fields preserved unchanged
    public function testChartReadyWithExistingId(): void
    {
        $event = $this->makeEvent('chart_ready', ['id' => 'my-custom-id', 'chart_type' => 'line']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('chart_ready', $result[0]->type);
        $this->assertSame('my-custom-id', $result[0]->data['id']);
        $this->assertSame('line', $result[0]->data['chart_type']);
    }

    public function testChartReadyPassthroughPreservesEmptyId(): void
    {
        $event = $this->makeEvent('chart_ready', ['id' => '']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        // Passthrough preserves the empty id verbatim (no auto-generation)
        $this->assertSame('', $result[0]->data['id']);
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

    // 18. unknown event type → log warning + passthrough (default fallback, not dropped)
    public function testUnknownEventTypeDropped(): void
    {
        $warnings = [];
        $transformer = new Nl2sqlEventTransformer(self::PHP_CID, static function (string $level, string $message) use (&$warnings): void {
            $warnings[] = [$level, $message];
        });
        $event = $this->makeEvent('some_unknown_type', ['foo' => 'bar']);
        $result = $transformer->transform($event);

        // Default branch must NOT silently drop — it logs a warning and passes through
        $this->assertCount(1, $result);
        $this->assertSame('some_unknown_type', $result[0]->type);
        $this->assertSame(['foo' => 'bar'], $result[0]->data);
        // Warning recorded at the right level, mentioning the event type name
        $this->assertCount(1, $warnings);
        $this->assertSame('warning', $warnings[0][0]);
        $this->assertStringContainsString('some_unknown_type', $warnings[0][1]);
    }

    // 1.1 DASHBOARD_INIT 1:1 passthrough (type + data unchanged)
    public function testDashboardInitPassthrough(): void
    {
        $data = [
            'layout' => ['cols' => 24],
            'widgets' => [
                ['widget_id' => 'w1', 'title' => 'A', 'type' => 'bar'],
                ['widget_id' => 'w2', 'title' => 'B', 'type' => 'line'],
            ],
        ];
        $event = $this->makeEvent('DASHBOARD_INIT', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('DASHBOARD_INIT', $result[0]->type);
        $this->assertSame($data, $result[0]->data);
    }

    // 1.2 WIDGET_DATA_UPDATE 1:1 passthrough (no recomputation of truncated/total)
    public function testWidgetDataUpdatePassthroughPreservesTruncatedTotal(): void
    {
        $data = [
            'widget_id' => 'w1',
            'sql' => 'SELECT * FROM t',
            'data' => [['a' => 1], ['a' => 2]],
            'truncated' => true,
            'total' => 2500,
        ];
        $event = $this->makeEvent('WIDGET_DATA_UPDATE', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('WIDGET_DATA_UPDATE', $result[0]->type);
        // Values preserved exactly — not recomputed from the 2-row data array
        $this->assertTrue($result[0]->data['truncated']);
        $this->assertSame(2500, $result[0]->data['total']);
        $this->assertSame('w1', $result[0]->data['widget_id']);
    }

    // 1.3 WIDGET_ERROR 1:1 passthrough (widget_id/error_msg unchanged)
    public function testWidgetErrorPassthrough(): void
    {
        $data = ['widget_id' => 'w3', 'error_msg' => 'query timed out'];
        $event = $this->makeEvent('WIDGET_ERROR', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('WIDGET_ERROR', $result[0]->type);
        $this->assertSame($data, $result[0]->data);
    }

    // 1.4 default passthrough does not alter the explicit skip list
    public function testExplicitSkipEventsStillReturnEmptyArray(): void
    {
        foreach (['content_block_start', 'content_block_stop', 'message_delta'] as $type) {
            $event = $this->makeEvent($type, ['x' => 1]);
            $result = $this->transformer->transform($event);
            $this->assertCount(0, $result, "$type must still be skipped, not passed through");
        }
    }

    // 1.4 default passthrough does not shadow the explicit dashboard_patch / data_ready cases
    public function testExplicitCasesNotShadowedByDefault(): void
    {
        // dashboard_patch has its own 1:1 case
        $patch = $this->makeEvent('dashboard_patch', ['patches' => []]);
        $this->assertSame('dashboard_patch', $this->transformer->transform($patch)[0]->type);

        // data_ready has its own rename case (→ data_preview), not passed through as-is
        $dr = $this->makeEvent('data_ready', ['rows' => []]);
        $r = $this->transformer->transform($dr);
        $this->assertCount(1, $r);
        $this->assertSame('data_preview', $r[0]->type);
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

    // 20. statelessness: 100 consecutive calls produce independent results.
    // Uses the unified WIDGET_DATA_UPDATE passthrough (the real delivery path)
    // to confirm the transformer accumulates no state across calls.
    public function testStatelessnessAcrossCalls(): void
    {
        $count = 0;
        for ($i = 0; $i < 100; $i++) {
            $event = $this->makeEvent('WIDGET_DATA_UPDATE', [
                'widget_id' => 'w' . $i,
                'g2_spec'   => ['type' => 'interval'],
            ]);
            $result = $this->transformer->transform($event);
            $this->assertCount(1, $result);
            $this->assertSame('WIDGET_DATA_UPDATE', $result[0]->type);
            $this->assertSame('w' . $i, $result[0]->data['widget_id']);
            $count++;
        }

        // All 100 calls produced results (no state accumulation)
        $this->assertSame(100, $count);

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
