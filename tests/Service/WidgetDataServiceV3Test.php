<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\WidgetDataService;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;

/**
 * WidgetDataService v3 contract tests (react-dsl-v3-renderer task 7.7).
 *
 * Covers:
 *  - extractQuery: resolves widgets[id].query_id → queries[query_id]
 *  - resolveBindParams: builds the :name → value bind map from slicer_values + defaults
 *  - executeBoundQuery: native path uses prepared-statement binding (not string concat)
 *  - applyMaskedColumns: alias-aware masking (SELECT phone AS p)
 *  - querySlicerOptions: resolves slicer options_query_id → rows
 *  - queryPublicWidgetData form-A→form-B precedence: dsl wins over uid;
 *    both absent → DashboardException (HTTP 400)
 */
final class WidgetDataServiceV3Test extends TestCase
{
    /**
     * Build a service with a stub repository that captures the SQL + params
     * passed to executeBoundQuery (the prepared-statement path).
     *
     * @param callable(string,array):array $boundExecutor returns rows for (sql, params)
     * @param null|callable(string):?array $schemaResolver returns published schema for uid
     */
    private function makeService(callable $boundExecutor, ?callable $schemaResolver = null): WidgetDataService
    {
        $schemaResolver = $schemaResolver ?? static fn(string $uid): ?array => null;

        $repo = new class($boundExecutor, $schemaResolver) implements DashboardRepositoryInterface {
            private $boundExecutor;
            private $schemaResolver;
            public function __construct(callable $boundExecutor, callable $schemaResolver)
            {
                $this->boundExecutor = $boundExecutor;
                $this->schemaResolver = $schemaResolver;
            }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return ['uid' => $uid, 'published_version_id' => 'v1']; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return ($this->schemaResolver)($uid); }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
            public function executeBoundQuery(string $sql, array $params = []): array {
                return ($this->boundExecutor)($sql, $params);
            }
        };

        // Native mode so the service self-executes (no python forwarding).
        putenv('CHAT2VIZ_QUERY_EXECUTOR=native');

        return new WidgetDataService($repo);
    }

    public function sampleDsl(): array
    {
        return [
            'version' => '3.0.0',
            'layout' => ['regions' => ['content'], 'slots' => []],
            'queries' => [
                'q1' => [
                    'query_id' => 'q1',
                    'raw_sql' => 'SELECT region, amount FROM sales WHERE region = :region',
                    'params' => [
                        ['name' => 'region', 'type' => 'string', 'required' => false, 'default' => null],
                    ],
                    'masked_columns' => ['phone'],
                ],
                'q_opt_s1' => [
                    'query_id' => 'q_opt_s1',
                    'raw_sql' => 'SELECT DISTINCT region FROM sales ORDER BY region',
                    'params' => [],
                ],
            ],
            'widgets' => [
                'w1' => [
                    'widget_id' => 'w1',
                    'plugin_type' => 'g2_chart',
                    'plugin_spec' => [],
                    'query_id' => 'q1',
                    'region' => 'content',
                ],
            ],
            'slicers' => [
                [
                    'slicer_id' => 's1',
                    'field' => 'region',
                    'options_query_id' => 'q_opt_s1',
                    'target_query_params' => ['q1' => 'region'],
                ],
            ],
            'interactions' => [],
        ];
    }

    public function test_extractQuery_resolves_widget_query_id(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $query = $service->extractQuery($this->sampleDsl(), 'w1');
        $this->assertNotNull($query);
        $this->assertSame('q1', $query['query_id']);
        $this->assertStringContainsString(':region', $query['raw_sql']);
    }

    public function test_extractQuery_returns_null_for_missing_widget(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $this->assertNull($service->extractQuery($this->sampleDsl(), 'w_missing'));
    }

    public function test_resolveBindParams_builds_named_param_map(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $query = $service->extractQuery($this->sampleDsl(), 'w1');
        $bind = $service->resolveBindParams($query, ['region' => "华东"]);
        // Keys include the leading ':' (PDO named-placeholder convention).
        $this->assertSame([':region' => "华东"], $bind);
    }

    public function test_resolveBindParams_uses_default_when_value_absent(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $dsl = $this->sampleDsl();
        $dsl['queries']['q1']['params'][0]['default'] = '华东';
        $bind = $service->resolveBindParams($dsl['queries']['q1'], []);
        $this->assertSame([':region' => '华东'], $bind);
    }

    public function test_resolveBindParams_null_when_no_default(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $query = $service->extractQuery($this->sampleDsl(), 'w1');
        $bind = $service->resolveBindParams($query, []);
        $this->assertSame([':region' => null], $bind);
    }

    public function test_queryPublicWidgetData_passes_bound_params_not_interpolated(): void
    {
        // The native path must call executeBoundQuery with the raw placeholder
        // SQL + the bind map — NOT a pre-interpolated string.
        $captured = null;
        $service = $this->makeService(function (string $sql, array $params) use (&$captured): array {
            $captured = ['sql' => $sql, 'params' => $params];
            return [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']];
        });

        $dsl = $this->sampleDsl();
        $service->queryPublicWidgetData('uid-x', 'w1', '127.0.0.1', $dsl, ['region' => "O'Brien"]);

        // SQL retains the :region placeholder (not interpolated).
        $this->assertStringContainsString(':region', $captured['sql']);
        $this->assertStringNotContainsString("O'Brien", $captured['sql']);
        // The dangerous value is in the bind params (safe — PDO-bound).
        $this->assertSame([':region' => "O'Brien"], $captured['params']);
    }

    public function test_applyMaskedColumns_masks_listed_columns(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $query = $service->extractQuery($this->sampleDsl(), 'w1');
        $rows = [
            ['region' => '华东', 'amount' => 1000, 'phone' => '13812345678'],
            ['region' => '华北', 'amount' => 2000, 'phone' => '13987654321'],
        ];
        $masked = $service->applyMaskedColumns($rows, $query);
        $this->assertSame('138****5678', $masked[0]['phone']);
        $this->assertSame('华东', $masked[0]['region']);
    }

    public function test_applyMaskedColumns_masks_via_alias(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        // Query with alias: SELECT phone AS p — masking must find the alias.
        $query = [
            'raw_sql' => 'SELECT phone AS p, name FROM contacts',
            'masked_columns' => ['phone'],
        ];
        $rows = [['p' => '13812345678', 'name' => '张三']];
        $masked = $service->applyMaskedColumns($rows, $query);
        // The alias 'p' result key is masked even though masked_columns says 'phone'.
        $this->assertSame('138****5678', $masked[0]['p']);
        $this->assertSame('张三', $masked[0]['name']);
    }

    public function test_applyMaskedColumns_noop_when_no_masked_columns(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $rows = [['a' => 1]];
        $this->assertSame($rows, $service->applyMaskedColumns($rows, ['raw_sql' => 'SELECT 1']));
    }

    public function test_querySlicerOptions_resolves_options_query(): void
    {
        $capturedSql = null;
        $service = $this->makeService(function (string $sql, array $params) use (&$capturedSql): array {
            $capturedSql = $sql;
            return [['region' => '华东'], ['region' => '华北']];
        });
        $rows = $service->querySlicerOptions('s1', $this->sampleDsl());
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('SELECT DISTINCT region FROM sales ORDER BY region', $capturedSql ?? '');
    }

    public function test_querySlicerOptions_returns_empty_when_no_options_query_id(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $dsl = $this->sampleDsl();
        $dsl['slicers'][0]['options_query_id'] = null;
        $this->assertSame([], $service->querySlicerOptions('s1', $dsl));
    }

    public function test_queryPublicWidgetData_both_absent_throws_400(): void
    {
        $service = $this->makeService(static fn(string $sql, array $p): array => []);
        $this->expectException(\Qscmf\Chat2Viz\Exception\DashboardException::class);
        try {
            $service->queryPublicWidgetData('', 'w1', '127.0.0.1', null, []);
        } catch (\Qscmf\Chat2Viz\Exception\DashboardException $e) {
            $this->assertSame(400, $e->getCode());
            throw $e;
        }
    }

    public function test_queryPublicWidgetData_dsl_wins_over_uid(): void
    {
        $capturedSql = null;
        $service = $this->makeService(
            function (string $sql, array $params) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']];
            },
            static function (string $uid): ?array {
                // A DIFFERENT schema that would produce a different sql if used.
                return [
                    'version' => '3.0.0',
                    'queries' => ['qX' => ['query_id' => 'qX', 'raw_sql' => 'SELECT FROM_SCHEMA', 'params' => []]],
                    'widgets' => ['w1' => ['widget_id' => 'w1', 'plugin_type' => 'g2_chart', 'plugin_spec' => [], 'query_id' => 'qX', 'region' => 'content']],
                ];
            }
        );

        $dsl = $this->sampleDsl();
        $result = $service->queryPublicWidgetData('some-uid', 'w1', '127.0.0.1', $dsl, []);
        // The dsl's q1 SQL was used, NOT the schema's qX.
        $this->assertStringContainsString('SELECT region, amount FROM sales', $capturedSql ?? '');
        $this->assertStringNotContainsString('FROM_SCHEMA', $capturedSql ?? '');
        // masked_columns applied.
        $this->assertSame('138****5678', $result->rows[0]['phone']);
    }

    protected function tearDown(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR');
        parent::tearDown();
    }
}
