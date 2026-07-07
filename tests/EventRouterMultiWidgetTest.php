<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

/**
 * declarative-frontend-adapter: EventRouter whole-tree accumulation tests.
 *
 * Covers:
 * - DASHBOARD_REPLACE overlays the whole {layout, widgets} tree per widget_id
 *   (verbatim, no per-field reducer; data:null slim + error widgets forwarded).
 * - WIDGET_ERROR merges into the widgets map produced by the latest
 *   DASHBOARD_REPLACE (mid-stream local degradation, contract §3).
 *
 * @covers \Qscmf\Chat2Viz\Service\EventRouter::routeEvent
 * @covers \Qscmf\Chat2Viz\Sse\StreamAccumulator::accumulateDashboardReplace
 * @covers \Qscmf\Chat2Viz\Sse\StreamAccumulator::accumulateWidgetData
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

            public function accumulateDashboardReplace(string $conversationId, array $layout, array $widgets): void
            {
                // Whole-tree overlay: replace the prior widgets map entirely.
                $this->test->fakeRedis[$conversationId]['widgets'] = json_encode(
                    $widgets,
                    JSON_UNESCAPED_UNICODE
                );
                $this->test->fakeRedis[$conversationId]['layout'] = json_encode(
                    $layout,
                    JSON_UNESCAPED_UNICODE
                );
            }

            public function accumulateWidgetData(string $conversationId, string $widgetId, array $payload): void
            {
                $widgets = $this->readWidgets($conversationId);
                if (!isset($widgets[$widgetId]) || !is_array($widgets[$widgetId])) {
                    $widgets[$widgetId] = [];
                }
                // dict-merge: WIDGET_ERROR merges error_msg/status into the
                // existing widget record produced by the latest DASHBOARD_REPLACE.
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
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
            public function executeBoundQuery(string $sql, array $params = []): array { return []; }
        };

        return new EventRouter($repo, 'test-dash-uid');
    }

    private function makeEvent(string $type, array $data = []): SseEvent
    {
        return new SseEvent(type: $type, data: $data, raw: '');
    }

    // DASHBOARD_REPLACE overlays the whole tree — all widgets forwarded verbatim.
    public function testDashboardReplaceOverlaysWholeTree(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('DASHBOARD_REPLACE', [
            'layout' => [
                ['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ['i' => 'w2', 'x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
            ],
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'title' => 'A', 'status' => 'success',
                          'sql' => 'SELECT 1', 'data' => [['x' => 1]]],
                'w2' => ['widget_id' => 'w2', 'title' => 'B', 'status' => 'success', 'data' => null],
            ],
        ]));

        $widgets = $acc->readWidgets('cid');
        $this->assertSame('SELECT 1', $widgets['w1']['sql']);
        // data:null slim widget forwarded verbatim (not synthesized)
        $this->assertNull($widgets['w2']['data']);
    }

    // Whole-tree semantics: a second DASHBOARD_REPLACE fully replaces the tree
    // (widgets absent from the new tree are gone).
    public function testSecondReplaceFullyReplacesPriorTree(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('DASHBOARD_REPLACE', [
            'layout' => [],
            'widgets' => ['w1' => ['widget_id' => 'w1'], 'w2' => ['widget_id' => 'w2'], 'w3' => ['widget_id' => 'w3']],
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('DASHBOARD_REPLACE', [
            'layout' => [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
            'widgets' => ['w1' => ['widget_id' => 'w1', 'status' => 'success']],
        ]));

        $widgets = $acc->readWidgets('cid');
        // Only w1 remains — w2/w3 cleared by the whole-tree replace.
        $this->assertSame(['w1'], array_keys($widgets));
    }

    // WIDGET_ERROR merges into the widgets map from the latest DASHBOARD_REPLACE.
    public function testWidgetErrorMergesIntoWidgetsMap(): void
    {
        $acc = $this->makeProxy();
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', $this->makeEvent('DASHBOARD_REPLACE', [
            'layout' => [],
            'widgets' => [
                'w1' => ['widget_id' => 'w1', 'status' => 'success', 'sql' => 'SELECT 1'],
                'w3' => ['widget_id' => 'w3', 'status' => 'success'],
            ],
        ]));
        $router->routeEvent($acc, 'cid', $this->makeEvent('WIDGET_ERROR', [
            'widget_id' => 'w3', 'error_msg' => 'query timed out',
        ]));

        $widgets = $acc->readWidgets('cid');
        // w1 untouched by w3's error
        $this->assertSame('SELECT 1', $widgets['w1']['sql']);
        // w3's error merged into its record
        $this->assertSame('error', $widgets['w3']['status']);
        $this->assertSame('query timed out', $widgets['w3']['error_msg']);
    }
}
