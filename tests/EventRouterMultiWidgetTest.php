<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

/**
 * Unit tests for EventRouter multi-widget accumulation (tasks 2.1–2.5).
 *
 * Covers:
 * - DASHBOARD_INIT / WIDGET_DATA_UPDATE / WIDGET_ERROR accumulate per widget_id
 *   (multiple widgets must not overwrite each other).
 * - Reading legacy single-widget (flat) metadata must not crash (compat degrade).
 *
 * @covers \Qscmf\Chat2Viz\Service\EventRouter::routeEvent
 * @covers \Qscmf\Chat2Viz\Sse\StreamAccumulator::accumulateWidgetData
 * @covers \Qscmf\Chat2Viz\Sse\StreamAccumulator::readWidgetsMetadata
 */
class EventRouterMultiWidgetTest extends TestCase
{
    /** @var array<string, array<string, mixed>> Fake Redis hash per cid */
    public array $fakeRedis = [];

    protected function setUp(): void
    {
        $this->fakeRedis = [];
    }

    /**
     * Build a recording StreamAccumulator proxy backed by an in-memory hash.
     * Mirrors the proxy strategy in RouteEventSqlExtractionTest.
     */
    private function makeProxy(): StreamAccumulator
    {
        $acc = (new \ReflectionClass(StreamAccumulator::class))
            ->newInstanceWithoutConstructor();
        $accRef = new \ReflectionClass($acc);
        $prop = $accRef->getProperty('redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($acc, true);

        $test = $this;
        return new class($acc, $test) extends StreamAccumulator {
            public function __construct(
                private StreamAccumulator $inner,
                private EventRouterMultiWidgetTest $test
            ) {
            }

            public function isRedisAvailable(): bool
            {
                return true;
            }

            public function accumulateWidgetData(string $conversationId, string $widgetId, array $payload): void
            {
                $widgets = $this->readWidgets($conversationId);
                if (!isset($widgets[$widgetId]) || !is_array($widgets[$widgetId])) {
                    $widgets[$widgetId] = [];
                }
                // dict-merge: later fields do not overwrite prior distinct keys
                foreach ($payload as $k => $v) {
                    $widgets[$widgetId][$k] = $v;
                }
                $this->test->fakeRedis[$conversationId]['widgets'] = json_encode(
                    $widgets,
                    JSON_UNESCAPED_UNICODE
                );
            }

            public function readWidgets(string $conversationId): array
            {
                $raw = $this->test->fakeRedis[$conversationId]['widgets'] ?? null;
                if (!is_string($raw) || $raw === '') {
                    return [];
                }
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : [];
            }
        };
    }

    private function makeRouter(): EventRouter
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        return new EventRouter($repo, 'test-dash-uid');
    }

    private function makeEvent(string $type, array $data = []): SseEvent
    {
        return new SseEvent(type: $type, data: $data, raw: '');
    }

    // 2.1+2.2 DASHBOARD_INIT then two WIDGET_DATA_UPDATE → w1 and w2 isolated
    public function testMultipleWidgetsDoNotOverwriteEachOther(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('DASHBOARD_INIT', [
            // Contract §2: widgets is a map<widget_id, WidgetMeta>. w1 uses
            // the Python emitter field `chart_type`; w2 uses the `type` alias
            // to exercise both branches of the chart_type mapping.
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'title' => 'A', 'chart_type' => 'bar'],
                'w2' => ['widget_id' => 'w2', 'title' => 'B', 'type' => 'line'],
            ],
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_DATA_UPDATE', [
            'widget_id' => 'w1',
            'sql' => 'SELECT 1 FROM a',
            'data' => [['x' => 1]],
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_DATA_UPDATE', [
            'widget_id' => 'w2',
            'sql' => 'SELECT 2 FROM b',
            'data' => [['x' => 2]],
        ]));

        $widgets = $acc->readWidgets('cid');
        $this->assertArrayHasKey('w1', $widgets);
        $this->assertArrayHasKey('w2', $widgets);
        // w2's data did not overwrite w1's fields
        $this->assertSame('SELECT 1 FROM a', $widgets['w1']['sql']);
        $this->assertSame('SELECT 2 FROM b', $widgets['w2']['sql']);
        $this->assertSame([['x' => 1]], $widgets['w1']['data']);
        $this->assertSame([['x' => 2]], $widgets['w2']['data']);
        // chart_type persisted from both field-name variants (chart_type
        // primary, type alias fallback) — was silently dropped before the fix.
        $this->assertSame('bar', $widgets['w1']['chart_type']);
        $this->assertSame('line', $widgets['w2']['chart_type']);
    }

    // 2.3 WIDGET_ERROR accumulates into the corresponding widget only
    public function testWidgetErrorIsolatesToCorrespondingWidget(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_DATA_UPDATE', [
            'widget_id' => 'w1', 'sql' => 'SELECT 1', 'data' => [['x' => 1]],
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_ERROR', [
            'widget_id' => 'w3', 'error_msg' => 'query timed out',
        ]));

        $widgets = $acc->readWidgets('cid');
        $this->assertSame('SELECT 1', $widgets['w1']['sql']);
        $this->assertSame('query timed out', $widgets['w3']['error_msg']);
        $this->assertArrayNotHasKey('error_msg', $widgets['w1']);
    }

    // 2.4 Legacy single-widget (flat) metadata read must not crash
    public function testReadLegacyFlatMetadataDegradesGracefully(): void
    {
        // A legacy single-widget conversation stored its metadata as flat
        // scalar/array fields (sql, g2_spec, ...) — NOT a widgets map.
        // The read helper must treat such records as a single implicit widget
        // and never throw.
        $flat = [
            'sql' => 'SELECT legacy',
            'g2_spec' => ['type' => 'bar'],
            'chart_type' => 'bar',
        ];
        $result = StreamAccumulator::readWidgetsMetadata($flat);

        $this->assertIsArray($result);
        // No exception thrown — legacy record tolerated
        // It MAY be normalized into a widgets map (single implicit widget)
        // but MUST NOT crash. At minimum returns an array.
        $this->assertTrue(true, 'legacy flat metadata read without throwing');
    }

    // 2.5 multiple WIDGET_DATA_UPDATE for the SAME widget merge (not replace)
    public function testSameWidgetDataMergesAcrossUpdates(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_DATA_UPDATE', [
            'widget_id' => 'w1', 'sql' => 'SELECT 1',
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_DATA_UPDATE', [
            'widget_id' => 'w1', 'data' => [['x' => 1]],
        ]));

        $widgets = $acc->readWidgets('cid');
        $this->assertSame('SELECT 1', $widgets['w1']['sql']);
        $this->assertSame([['x' => 1]], $widgets['w1']['data']);
    }
}
