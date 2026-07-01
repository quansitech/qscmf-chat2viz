<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

/**
 * regression (declarative-frontend-adapter): DASHBOARD_REPLACE carries the LLM
 * answer text in its `answer` field (contract §2). EventRouter MUST accumulate
 * it as content — otherwise finalizeStream persists the assistant message with
 * an empty content column and the conversation history shows no LLM reply.
 *
 * @covers \Qscmf\Chat2Viz\Service\EventRouter::routeEvent
 */
class EventRouterAnswerTest extends TestCase
{
    private function makeAccumulator(array &$answerLog): StreamAccumulator
    {
        $acc = (new \ReflectionClass(StreamAccumulator::class))
            ->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass(StreamAccumulator::class);
        $prop = $ref->getProperty('redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($acc, true);

        return new class($acc, $answerLog) extends StreamAccumulator {
            public array $widgets = [];
            public function __construct(private StreamAccumulator $inner, private array &$log)
            {
            }
            public function isRedisAvailable(): bool { return true; }
            public function accumulateAnswer(string $conversationId, string $text): void
            {
                $this->log[$conversationId] = ($this->log[$conversationId] ?? '') . $text;
            }
            public function accumulateDashboardReplace(string $conversationId, array $layout, array $widgets): void
            {
                $this->widgets[$conversationId] = $widgets;
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
        };
        return new EventRouter($repo, 'test-dash-uid');
    }

    public function testDashboardReplaceAnswerIsAccumulatedAsContent(): void
    {
        $log = [];
        $acc = $this->makeAccumulator($log);
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', new SseEvent(
            type: 'DASHBOARD_REPLACE',
            data: [
                'layout' => [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
                'widgets' => ['w1' => ['widget_id' => 'w1', 'status' => 'success', 'data' => null]],
                'answer' => '已为您生成各评分电影数量分布的图表',
            ],
            raw: '',
        ));

        // Regression: answer text MUST be accumulated, else content saved empty.
        $this->assertSame('已为您生成各评分电影数量分布的图表', $log['cid'] ?? null);
    }

    public function testDashboardReplaceWithEmptyAnswerDoesNotAccumulate(): void
    {
        $log = [];
        $acc = $this->makeAccumulator($log);
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', new SseEvent(
            type: 'DASHBOARD_REPLACE',
            data: [
                'layout' => [],
                'widgets' => [],
                'answer' => '',
            ],
            raw: '',
        ));

        $this->assertArrayNotHasKey('cid', $log);
    }

    public function testMultipleDashboardReplaceAnswersConcatenate(): void
    {
        $log = [];
        $acc = $this->makeAccumulator($log);
        $router = $this->makeRouter();

        $router->routeEvent($acc, 'cid', new SseEvent('DASHBOARD_REPLACE', ['layout'=>[], 'widgets'=>[], 'answer'=>'第一段。'], ''));
        $router->routeEvent($acc, 'cid', new SseEvent('DASHBOARD_REPLACE', ['layout'=>[], 'widgets'=>[], 'answer'=>'第二段。'], ''));

        $this->assertSame('第一段。第二段。', $log['cid'] ?? null);
    }

    // fix-declarative-replace-regressions: handleDashboardReplace MUST backfill
    // each widget's non-empty sql into dashboard.current_schema via
    // updateWidgetSql — the whole-tree successor to the deleted backfillWidgetSql
    // (sourced from DASHBOARD_REPLACE.widgets[wid].sql). Without this, widget
    // cards lack SQL and WidgetDataFetcher won't auto-fetch data on first refresh.
    public function testDashboardReplaceBackfillsWidgetSqlIntoCurrentSchema(): void
    {
        $calls = [];
        $log = [];
        $acc = $this->makeAccumulator($log);
        $router = $this->makeRecordingRouter($calls);

        $router->routeEvent($acc, 'cid', new SseEvent(
            type: 'DASHBOARD_REPLACE',
            data: [
                'layout' => [],
                'widgets' => [
                    'w1' => ['widget_id' => 'w1', 'sql' => 'SELECT 1'],
                    'w2' => ['widget_id' => 'w2', 'sql' => ''],
                    'w3' => ['widget_id' => 'w3'],
                ],
                'answer' => 'ok',
            ],
            raw: '',
        ));

        // Only w1 has a non-empty sql → exactly one updateWidgetSql call.
        $this->assertCount(1, $calls);
        $this->assertSame(['test-dash-uid', 'w1', 'SELECT 1'], $calls[0]);
    }

    // fix-declarative-replace-regressions task 2.2: when no widget carries a
    // non-empty sql, backfillWidgetSqlFromWidgets MUST NOT call updateWidgetSql
    // at all (guard against spurious DB writes on answer-only / sql-less frames).
    public function testDashboardReplaceWithNoSqlWidgetsDoesNotCallUpdateWidgetSql(): void
    {
        $calls = [];
        $log = [];
        $acc = $this->makeAccumulator($log);
        $router = $this->makeRecordingRouter($calls);

        $router->routeEvent($acc, 'cid', new SseEvent(
            type: 'DASHBOARD_REPLACE',
            data: [
                'layout' => [],
                'widgets' => [
                    'w1' => ['widget_id' => 'w1', 'sql' => ''],
                    'w2' => ['widget_id' => 'w2'],
                    'w3' => ['widget_id' => 'w3', 'sql' => null],
                ],
                'answer' => 'ok',
            ],
            raw: '',
        ));

        // No widget has a non-empty sql → zero updateWidgetSql calls.
        $this->assertCount(0, $calls);
    }

    /**
     * Router whose repo records every updateWidgetSql(uid, widgetId, sql) call.
     */
    private function makeRecordingRouter(array &$calls): EventRouter
    {
        $repo = new class($calls) implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public array $calls;
            public function __construct(array &$c) { $this->calls = &$c; }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void
            {
                $this->calls[] = [$uid, $widgetId, $sql];
            }
            public function executeRawQuery(string $sql): array { return []; }
        };
        return new EventRouter($repo, 'test-dash-uid');
    }
}
