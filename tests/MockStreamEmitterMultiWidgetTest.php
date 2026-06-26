<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sse\MockStreamEmitter;
use Qscmf\SseCore\SseEvent;

/**
 * declarative-frontend-adapter: locks the whole-tree mock sequence.
 *
 * Sequence (collapsed from the deprecated DASHBOARD_INIT → N×WIDGET_DATA_UPDATE):
 *   tool_start → DASHBOARD_REPLACE → tool_result → done
 * The single DASHBOARD_REPLACE frame carries the complete tree (layout +
 * widgets map + answer). Verifies the mock emulates the Python emitter under
 * the whole-tree protocol (contract §2).
 *
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::buildMultiWidgetEvents
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::buildDashboardReplace
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::emitMockMultiWidget
 */
class MockStreamEmitterMultiWidgetTest extends TestCase
{
    private MockStreamEmitter $emitter;

    protected function setUp(): void
    {
        $this->emitter = new MockStreamEmitter();
    }

    public function testSequenceIsToolStartThenReplaceThenToolResultThenDone(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(3);

        // tool_start + DASHBOARD_REPLACE + tool_result + done = 4 events
        $this->assertCount(4, $events);
        $this->assertSame('tool_start', $events[0]->type);
        $this->assertSame('DASHBOARD_REPLACE', $events[1]->type);
        $this->assertSame('tool_result', $events[2]->type);
        $this->assertSame('done', $events[3]->type);
    }

    public function testDashboardReplaceCarriesCompleteTreeForAllWidgets(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(3);
        $replace = $events[1];

        // Contract §2: widgets is a map<widget_id, Widget> keyed by widget_id;
        // layout is an array<{i,x,y,w,h}> with i == widget_id.
        $widgets = $replace->data['widgets'] ?? null;
        $this->assertIsArray($widgets);
        $this->assertCount(3, $widgets);
        $this->assertSame(['w1', 'w2', 'w3'], array_keys($widgets));
        foreach ($widgets as $id => $w) {
            $this->assertSame($id, $w['widget_id']);
            $this->assertNotEmpty($w['title']);
            $this->assertSame('success', $w['status']);
            // Each widget carries the full record: sql + g2_spec + data +
            // truncated/total (single-frame whole-tree delivery).
            $this->assertArrayHasKey('sql', $w);
            $this->assertArrayHasKey('g2_spec', $w);
            $this->assertArrayHasKey('data', $w);
            $this->assertArrayHasKey('truncated', $w);
            $this->assertArrayHasKey('total', $w);
            // total matches the row count (mock consistency with Python's
            // sole-computation-authority role).
            $this->assertSame(count($w['data']), $w['total']);
        }

        $layout = $replace->data['layout'] ?? null;
        $this->assertIsArray($layout);
        $this->assertCount(3, $layout);
        $this->assertSame(['w1', 'w2', 'w3'], array_column($layout, 'i'));

        // answer is present (contract §2 — DASHBOARD_REPLACE carries the LLM reply).
        $this->assertArrayHasKey('answer', $replace->data);
    }

    public function testWidgetCountLowerBoundIsOne(): void
    {
        // Even with 0 requested, at least one widget is in the tree.
        $events = $this->emitter->buildMultiWidgetEvents(0);
        // tool_start + DASHBOARD_REPLACE + tool_result + done = 4 events
        $this->assertCount(4, $events);
        $this->assertSame('DASHBOARD_REPLACE', $events[1]->type);
        $this->assertCount(1, $events[1]->data['widgets']);
    }

    public function testNoDeprecatedEventsEmitted(): void
    {
        // Whole-tree protocol: the mock MUST NOT emit the deprecated two-phase
        // events or the incremental-edit events.
        $events = $this->emitter->buildMultiWidgetEvents(2);
        $types = array_map(static fn(SseEvent $e) => $e->type, $events);
        foreach (['DASHBOARD_INIT', 'WIDGET_DATA_UPDATE', 'dashboard_patch',
                  'WIDGET_UPDATE', 'WIDGET_REMOVE', 'dashboard_rollback',
                  'sql_generated', 'data_preview', 'action_call', 'action_call_result',
                  'chart_ready'] as $deprecated) {
            $this->assertNotContains($deprecated, $types, "mock must not emit deprecated $deprecated");
        }
        $this->assertContains('DASHBOARD_REPLACE', $types);
    }

    public function testBuildDashboardReplaceFrameShape(): void
    {
        $frame = $this->emitter->buildDashboardReplace(
            [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
            ['w1' => ['widget_id' => 'w1', 'status' => 'success', 'data' => null]],
            '回复文本'
        );

        $this->assertSame('DASHBOARD_REPLACE', $frame->type);
        $this->assertSame([['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]], $frame->data['layout']);
        $this->assertArrayHasKey('w1', $frame->data['widgets']);
        $this->assertSame('回复文本', $frame->data['answer']);
    }
}
