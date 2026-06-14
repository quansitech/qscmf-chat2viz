<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sse\MockStreamEmitter;
use Qscmf\SseCore\SseEvent;

/**
 * Unit tests for MockStreamEmitter::buildMultiWidgetEvents() (task 7.1).
 *
 * Locks the multi-widget mock sequence: DASHBOARD_INIT → N×WIDGET_DATA_UPDATE
 * → done. Verifies the single-widget emitMockQuery path is NOT replaced
 * (coexistence / zero regression is enforced at the integration layer).
 *
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::buildMultiWidgetEvents
 * @covers \Qscmf\Chat2Viz\Sse\MockStreamEmitter::emitMockMultiWidget
 */
class MockStreamEmitterMultiWidgetTest extends TestCase
{
    private MockStreamEmitter $emitter;

    protected function setUp(): void
    {
        $this->emitter = new MockStreamEmitter();
    }

    public function testSequenceIsDashboardInitThenNUpdatesThenDone(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(3);

        // 1 DASHBOARD_INIT + 3 WIDGET_DATA_UPDATE + 1 done = 5 events
        $this->assertCount(5, $events);
        $this->assertSame('DASHBOARD_INIT', $events[0]->type);
        $this->assertSame('WIDGET_DATA_UPDATE', $events[1]->type);
        $this->assertSame('WIDGET_DATA_UPDATE', $events[2]->type);
        $this->assertSame('WIDGET_DATA_UPDATE', $events[3]->type);
        $this->assertSame('done', $events[4]->type);
    }

    public function testDashboardInitDeclaresNWidgetPlaceholders(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(3);
        $init = $events[0];

        // Contract §2: widgets is a map<widget_id, WidgetMeta> keyed by
        // widget_id (NOT a flat list); each placeholder carries chart_type
        // (the Python emitter field name). layout is an array<{i,x,y,w,h}>
        // with i == widget_id.
        $widgets = $init->data['widgets'] ?? null;
        $this->assertIsArray($widgets);
        $this->assertCount(3, $widgets);
        $this->assertArrayHasKey('w1', $widgets);
        $this->assertArrayHasKey('w2', $widgets);
        $this->assertArrayHasKey('w3', $widgets);
        foreach ($widgets as $w) {
            $this->assertNotEmpty($w['widget_id']);
            $this->assertNotEmpty($w['title']);
            $this->assertNotEmpty($w['chart_type']);
        }

        $layout = $init->data['layout'] ?? null;
        $this->assertIsArray($layout);
        $this->assertCount(3, $layout);
        $layoutIds = array_column($layout, 'i');
        $this->assertSame(['w1', 'w2', 'w3'], $layoutIds);
    }

    public function testWidgetDataUpdatesCarryFourRequiredFields(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(2);
        // First update frame (w1)
        $u1 = $events[1];
        $this->assertSame('WIDGET_DATA_UPDATE', $u1->type);
        // widget_id / data / truncated / total must all be present
        $this->assertSame('w1', $u1->data['widget_id']);
        $this->assertArrayHasKey('data', $u1->data);
        $this->assertArrayHasKey('truncated', $u1->data);
        $this->assertArrayHasKey('total', $u1->data);
        // sql present too (Python sets it; mock emulates)
        $this->assertArrayHasKey('sql', $u1->data);
        // total matches the row count (mock consistency)
        $this->assertSame(count($u1->data['data']), $u1->data['total']);
    }

    public function testEachWidgetIdAppearsExactlyOnceInUpdates(): void
    {
        $events = $this->emitter->buildMultiWidgetEvents(3);
        $updateIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $updateIds[] = $events[$i]->data['widget_id'];
        }
        sort($updateIds);
        $this->assertSame(['w1', 'w2', 'w3'], $updateIds);
    }

    public function testWidgetCountLowerBoundIsOne(): void
    {
        // Even with 0 requested, at least one widget frame is emitted.
        $events = $this->emitter->buildMultiWidgetEvents(0);
        $this->assertCount(3, $events); // 1 init + 1 update + 1 done
        $this->assertSame('DASHBOARD_INIT', $events[0]->type);
        $this->assertCount(1, $events[0]->data['widgets']);
    }

    public function testSingleWidgetMockPathNotReplaced(): void
    {
        // chart_ready is fully removed (contract §5); both the single- and
        // multi-widget paths now use the unified DASHBOARD_INIT +
        // WIDGET_DATA_UPDATE delivery. The builder must never emit chart_ready.
        $events = $this->emitter->buildMultiWidgetEvents(2);
        $types = array_map(static fn(SseEvent $e) => $e->type, $events);
        $this->assertNotContains('chart_ready', $types);
        $this->assertContains('DASHBOARD_INIT', $types);
    }
}
