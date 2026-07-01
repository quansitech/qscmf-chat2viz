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

    // 8. tool_start → tool_start (GAP-4 / declarative-frontend-adapter: the
    //    legacy rename to action_call is removed — tool_start passes through
    //    verbatim so the frontend consumes the contract §5 base event name).
    public function testToolStartPassthrough(): void
    {
        $event = $this->makeEvent('tool_start', ['tool_name' => 'execute_sql', 'tool_args' => ['query' => 'SELECT 1']]);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('tool_start', $result[0]->type);
        // payload forwarded verbatim (no tool_name→action_type rename)
        $this->assertSame('execute_sql', $result[0]->data['tool_name']);
        $this->assertSame(['query' => 'SELECT 1'], $result[0]->data['tool_args']);
    }

    public function testToolStartWithMissingFields(): void
    {
        $event = $this->makeEvent('tool_start', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('tool_start', $result[0]->type);
    }

    // 9. tool_result → tool_result (GAP-4: legacy rename to action_call_result
    //    is removed — passes through verbatim, contract §5).
    public function testToolResultPassthrough(): void
    {
        $event = $this->makeEvent('tool_result', ['summary' => 'Query returned 5 rows']);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('tool_result', $result[0]->type);
        $this->assertSame('Query returned 5 rows', $result[0]->data['summary']);
    }

    public function testToolResultWithMissingSummary(): void
    {
        $event = $this->makeEvent('tool_result', []);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('tool_result', $result[0]->type);
    }

    // 10. sql_ready / data_ready are DEAD-2/DEAD-3: contract §4 marks
    //     sql_generated/data_preview deprecated and commit_widget never triggers
    //     sql_ready/data_ready. They must NOT have explicit rename mappings —
    //     any stray emission falls through to default passthrough (warning).
    public function testSqlReadyHasNoExplicitRename(): void
    {
        $event = $this->makeEvent('sql_ready', ['sql' => 'SELECT 1']);
        $result = $this->transformer->transform($event);

        // Falls to default passthrough (type preserved as-is), NOT renamed to
        // sql_generated. A warning is logged but the frame is forwarded.
        $this->assertCount(1, $result);
        $this->assertSame('sql_ready', $result[0]->type);
        $this->assertNotSame('sql_generated', $result[0]->type);
    }

    public function testDataReadyHasNoExplicitRename(): void
    {
        $data = ['columns' => ['id', 'name'], 'rows' => [[1, 'Alice'], [2, 'Bob']]];
        $event = $this->makeEvent('data_ready', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('data_ready', $result[0]->type);
        $this->assertNotSame('data_preview', $result[0]->type);
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
        $transformer = new Nl2sqlEventTransformer(self::PHP_CID, null, static function (string $level, string $message) use (&$warnings): void {
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

    // 1.1 DASHBOARD_REPLACE 1:1 passthrough (declarative-frontend-adapter:
    //     the whole-tree event replaces DASHBOARD_INIT + WIDGET_DATA_UPDATE +
    //     the deprecated dashboard_patch/WIDGET_UPDATE/WIDGET_REMOVE/
    //     dashboard_rollback. Type + data forwarded verbatim, no rename,
    //     no field add/remove, no parse, no recomputation of truncated/total.)
    public function testDashboardReplacePassthrough(): void
    {
        $data = [
            'layout' => [
                ['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ['i' => 'w2', 'x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
            ],
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'title' => 'A', 'status' => 'success',
                          'sql' => 'SELECT 1', 'data' => [['x' => 1]], 'truncated' => true, 'total' => 2500],
                'w2' => ['widget_id' => 'w2', 'title' => 'B', 'status' => 'success', 'data' => null],
                'w3' => ['widget_id' => 'w3', 'title' => 'C', 'status' => 'error', 'error_msg' => '表不存在'],
            ],
            'answer' => '已为您生成销售分析仪表盘',
        ];
        $event = $this->makeEvent('DASHBOARD_REPLACE', $data);
        $result = $this->transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('DASHBOARD_REPLACE', $result[0]->type);
        $this->assertSame($data, $result[0]->data);
        // slim (data:null) + error widgets forwarded verbatim — passthrough is
        // NOT the cache-reuse boundary, and MUST NOT drop status=error widgets.
        $this->assertNull($result[0]->data['widgets']['w2']['data']);
        $this->assertSame('error', $result[0]->data['widgets']['w3']['status']);
        // truncated/total preserved exactly — never recomputed
        $this->assertTrue($result[0]->data['widgets']['w1']['truncated']);
        $this->assertSame(2500, $result[0]->data['widgets']['w1']['total']);
    }

    // 1.1a Deprecated events have NO explicit case — they fall through to the
    //     default passthrough (warning + forward as-is), NOT a silent drop and
    //     NOT a legacy rename. Regression guard for the removal of their cases.
    public function testDeprecatedEventsFallToDefaultPassthrough(): void
    {
        foreach (['DASHBOARD_INIT', 'WIDGET_DATA_UPDATE', 'dashboard_patch',
                  'WIDGET_UPDATE', 'WIDGET_REMOVE', 'dashboard_rollback'] as $type) {
            $event = $this->makeEvent($type, ['widget_id' => 'w1']);
            $result = $this->transformer->transform($event);
            $this->assertCount(1, $result, "$type must still forward via default passthrough");
            $this->assertSame($type, $result[0]->type, "$type must not be renamed by a legacy case");
        }
    }

    // 1.3 WIDGET_ERROR 1:1 passthrough (widget_id/error_msg unchanged) — WIDGET_ERROR
    //     is retained for mid-stream local degradation (contract §3).
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

    // 1.4a action_call/action_call_result are gone as explicit cases (GAP-4);
    //      they fall to default passthrough. tool_start/tool_result ARE the
    //      contract §5 names now and have explicit passthrough cases.
    public function testActionCallEventsFallToDefaultPassthrough(): void
    {
        foreach (['action_call', 'action_call_result'] as $type) {
            $event = $this->makeEvent($type, ['x' => 1]);
            $result = $this->transformer->transform($event);
            $this->assertCount(1, $result, "$type must forward via default passthrough");
            $this->assertSame($type, $result[0]->type);
        }
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
    // Uses the DASHBOARD_REPLACE passthrough (the real delivery path) to
    // confirm the transformer accumulates no state across calls.
    public function testStatelessnessAcrossCalls(): void
    {
        $count = 0;
        for ($i = 0; $i < 100; $i++) {
            $event = $this->makeEvent('DASHBOARD_REPLACE', [
                'layout' => [['i' => 'w' . $i, 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
                'widgets' => ['w' . $i => ['widget_id' => 'w' . $i, 'status' => 'success', 'data' => null]],
            ]);
            $result = $this->transformer->transform($event);
            $this->assertCount(1, $result);
            $this->assertSame('DASHBOARD_REPLACE', $result[0]->type);
            $this->assertArrayHasKey('w' . $i, $result[0]->data['widgets']);
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

    // ─── conversation-one-to-one-and-first-msg-init: conversation_id frame uid ─
    // The conversation_id frame MUST carry `uid` on the first round (backend
    // just initialized the dashboard) and omit it on later rounds.

    // First round (dashboardUid passed) → frame carries BOTH conversation_id and uid
    public function testMessageStartFirstRoundCarriesUid(): void
    {
        $uid = 'uid-xyz-123';
        $transformer = new Nl2sqlEventTransformer(self::PHP_CID, $uid);
        $event = $this->makeEvent('message_start', ['conversation_id' => 'python-id']);

        $result = $transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('conversation_id', $result[0]->type);
        $this->assertSame(self::PHP_CID, $result[0]->data['conversation_id']);
        $this->assertSame($uid, $result[0]->data['uid']);
    }

    // Later round (dashboardUid null) → frame carries ONLY conversation_id, no uid
    public function testMessageStartLaterRoundOmitsUid(): void
    {
        $transformer = new Nl2sqlEventTransformer(self::PHP_CID, null);
        $event = $this->makeEvent('message_start', ['conversation_id' => 'python-id']);

        $result = $transformer->transform($event);

        $this->assertCount(1, $result);
        $this->assertSame('conversation_id', $result[0]->type);
        $this->assertSame(self::PHP_CID, $result[0]->data['conversation_id']);
        $this->assertArrayNotHasKey('uid', $result[0]->data);
    }

    // Empty dashboardUid is treated like null (later round) — no uid emitted
    public function testMessageStartEmptyDashboardUidOmitsUid(): void
    {
        $transformer = new Nl2sqlEventTransformer(self::PHP_CID, '');
        $event = $this->makeEvent('message_start', []);

        $result = $transformer->transform($event);

        $this->assertArrayNotHasKey('uid', $result[0]->data);
    }
}
