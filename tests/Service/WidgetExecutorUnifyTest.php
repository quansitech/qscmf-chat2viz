<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Service\WidgetDataService;

/**
 * unify-widget-executor: widget data executor unification tests.
 *
 * Verifies the unified executeWidgetQuery dispatch + normalizeResult
 * post-processing across all three paths (view / draft / admin preview)
 * under both python and native modes.
 *
 * @covers \Qscmf\Chat2Viz\Service\WidgetDataService
 */
final class WidgetExecutorUnifyTest extends TestCase
{
    /**
     * Build a service instrumented to observe which execution path runs.
     *
     * @param array  $instrumentation ['boundCalled', 'rawCalled', 'socketCalled', 'socketFrames']
     * @param callable(string,array):array $boundExecutor returns rows for executeBoundQuery
     * @param callable(string):?array $draftResolver returns current_schema for findByUid
     * @param callable(string):?array $publishedResolver returns published schema for getPublishedSchema
     * @param callable(array):\Generator $socketHandler handles a transact() frame, yields response frames
     */
    private function makeInstrumentedService(
        array &$instrumentation,
        callable $boundExecutor,
        ?callable $draftResolver = null,
        ?callable $publishedResolver = null,
        ?callable $socketHandler = null
    ): WidgetDataService {
        $instrumentation = [
            'boundCalled' => 0,
            'rawCalled' => 0,
            'socketCalled' => 0,
            'socketFrames' => [],
        ];
        $draftResolver = $draftResolver ?? static fn(string $uid): ?array => null;
        $publishedResolver = $publishedResolver ?? static fn(string $uid): ?array => null;

        $repo = new class($boundExecutor, $draftResolver, $publishedResolver) implements DashboardRepositoryInterface {
            private $boundExecutor;
            private $draftResolver;
            private $publishedResolver;
            public $boundCalled = 0;
            public $rawCalled = 0;
            public function __construct(callable $b, callable $d, callable $p)
            {
                $this->boundExecutor = $b;
                $this->draftResolver = $d;
                $this->publishedResolver = $p;
            }
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array
            {
                $schema = ($this->draftResolver)($uid);
                return ['uid' => $uid, 'current_schema' => $schema, 'published_version_id' => 'v1'];
            }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function delete(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return ($this->publishedResolver)($uid); }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
            public function executeBoundQuery(string $sql, array $params = []): array
            {
                $this->boundCalled++;
                return ($this->boundExecutor)($sql, $params);
            }
        };

        // Socket stub: only used under python mode. Records the frame it
        // received and yields the handler's response frames.
        $socket = null;
        if ($socketHandler !== null) {
            $socket = new class($socketHandler, $instrumentation) {
                private $handler;
                private $instr;
                public function __construct(callable $h, array &$i)
                {
                    $this->handler = $h;
                    $this->instr = &$i;
                }
                public function transact(array $frame): \Generator
                {
                    $this->instr['socketCalled']++;
                    $this->instr['socketFrames'][] = $frame;
                    yield from ($this->handler)($frame);
                }
            };
        }

        // Bridge the repo's boundCalled count back into the instrumentation
        // array via a closure capture.
        $boundRef = $repo;
        $logger = static function (string $tag, string $detail): void {};

        $service = new WidgetDataService($repo, $logger, $socket);

        // Wrap so tearDown can read counts. We re-bind via a property accessor
        // by reading after the call through the $repo object's public counters.
        $instrumentation['__repo'] = $repo;
        return $service;
    }

    /**
     * Read repo call counters back into the instrumentation array.
     */
    private function syncCounts(array &$instrumentation): void
    {
        $repo = $instrumentation['__repo'] ?? null;
        if ($repo !== null) {
            $instrumentation['boundCalled'] = $repo->boundCalled;
        }
    }

    private function sampleV3Dsl(): array
    {
        return [
            'version' => '3.0.0',
            'layout' => ['regions' => ['content'], 'slots' => []],
            'queries' => [
                'q1' => [
                    'query_id' => 'q1',
                    'raw_sql' => 'SELECT region, amount, phone FROM sales WHERE region = :region',
                    'params' => [
                        ['name' => 'region', 'type' => 'string', 'required' => false, 'default' => null],
                    ],
                    'masked_columns' => ['phone'],
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
            'slicers' => [],
            'interactions' => [],
        ];
    }

    private function legacyV2Schema(): array
    {
        // Legacy v2 shape: flat widgets[] with inline sql, no queries{} map.
        return [
            'widgets' => [
                [
                    'id' => 'w1',
                    'type' => 'g2_chart',
                    'title' => 'sales',
                    'sql' => 'SELECT region, amount FROM sales LIMIT 10',
                    'g2_spec' => [],
                ],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: python mode draft preview forwards to Python (not PHP direct)
    // ─────────────────────────────────────────────────────────────────

    public function test_python_mode_draft_preview_forwards_to_python(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [['region' => 'FAIL']],
            fn(string $uid): ?array => $this->sampleV3Dsl(),  // current_schema returns v3 dsl
            null,
            static function (array $frame): \Generator {
                // Python returns plain rows (no masking applied).
                yield ['rows' => [['region' => '华东', 'amount' => 100, 'phone' => '13812345678']]];
            }
        );

        $result = $service->queryDraftWidgetData('uid-draft', 'w1');
        $this->syncCounts($instr);

        $this->assertSame(1, $instr['socketCalled'], 'python mode draft MUST call socket transact');
        $this->assertSame(0, $instr['boundCalled'], 'python mode draft MUST NOT call executeBoundQuery');
        $this->assertCount(1, $result->rows);
        $this->assertSame('华东', $result->rows[0]['region']);
    }

    public function test_python_mode_admin_preview_forwards_to_python(): void
    {
        // admin preview shares queryDraftWidgetData — same behavior expected.
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [['region' => 'FAIL']],
            fn(string $uid): ?array => $this->sampleV3Dsl(),
            null,
            static function (array $frame): \Generator {
                yield ['rows' => [['region' => '华北', 'amount' => 200, 'phone' => '13900000000']]];
            }
        );

        $result = $service->queryDraftWidgetData('uid-preview', 'w1');
        $this->syncCounts($instr);

        $this->assertSame(1, $instr['socketCalled']);
        $this->assertSame(0, $instr['boundCalled']);
        $this->assertSame('华北', $result->rows[0]['region']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: native mode all three paths execute locally
    // ─────────────────────────────────────────────────────────────────

    public function test_native_mode_view_executes_locally(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=native');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']]
        );

        $result = $service->queryPublicWidgetData('uid-v', 'w1', '127.0.0.1', $this->sampleV3Dsl(), []);
        $this->syncCounts($instr);

        $this->assertSame(1, $instr['boundCalled']);
        $this->assertSame(0, $instr['socketCalled']);
        $this->assertSame('华东', $result->rows[0]['region']);
    }

    public function test_native_mode_draft_executes_locally(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=native');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']],
            fn(string $uid): ?array => $this->sampleV3Dsl()
        );

        $result = $service->queryDraftWidgetData('uid-d', 'w1');
        $this->syncCounts($instr);

        $this->assertSame(1, $instr['boundCalled']);
        $this->assertSame(0, $instr['socketCalled']);
        $this->assertSame('华东', $result->rows[0]['region']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: python path applies masked_columns (security fix)
    // ─────────────────────────────────────────────────────────────────

    public function test_python_path_applies_masked_columns(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            fn(string $uid): ?array => $this->sampleV3Dsl(),
            null,
            static function (array $frame): \Generator {
                // Python returns PLAINTEXT phone — PHP MUST mask it.
                yield ['rows' => [['region' => '华东', 'phone' => '13812345678']]];
            }
        );

        $result = $service->queryDraftWidgetData('uid-m', 'w1');
        $this->assertSame('138****5678', $result->rows[0]['phone'], 'python path MUST mask phone via normalizeResult');
        $this->assertSame('华东', $result->rows[0]['region']);
    }

    public function test_python_path_masking_idempotent_when_python_already_masked(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            fn(string $uid): ?array => $this->sampleV3Dsl(),
            null,
            static function (array $frame): \Generator {
                // Python ALREADY masked phone — PHP re-masking must not corrupt.
                yield ['rows' => [['region' => '华东', 'phone' => '138****5678']]];
            }
        );

        $result = $service->queryDraftWidgetData('uid-idem', 'w1');
        // Re-masking an already-masked value stays masked (no exception, no corruption).
        $this->assertStringContainsString('****', (string) $result->rows[0]['phone']);
    }

    public function test_python_path_masking_applies_to_view_page_too(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            null,
            null,
            static function (array $frame): \Generator {
                yield ['rows' => [['region' => '华东', 'phone' => '13812345678']]];
            }
        );

        $result = $service->queryPublicWidgetData('uid-view', 'w1', '127.0.0.1', $this->sampleV3Dsl(), []);
        $this->assertSame('138****5678', $result->rows[0]['phone']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: view page caches (APCu), draft/admin preview do not
    // ─────────────────────────────────────────────────────────────────

    public function test_view_page_uses_apcu_cache_on_second_call(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }
        apcu_clear_cache();
        putenv('CHAT2VIZ_QUERY_EXECUTOR=native');

        $callCount = 0;
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static function (string $sql, array $p) use (&$callCount): array {
                $callCount++;
                return [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']];
            }
        );

        $dsl = $this->sampleV3Dsl();
        $service->queryPublicWidgetData('uid-c', 'w1', '127.0.0.1', $dsl, []);
        $this->syncCounts($instr);
        $firstBoundCalls = $instr['boundCalled'];

        $service->queryPublicWidgetData('uid-c', 'w1', '127.0.0.1', $dsl, []);
        $this->syncCounts($instr);

        // Second call SHOULD hit cache: boundQuery not invoked again.
        $this->assertSame(1, $callCount, 'second view call should hit APCu cache, not re-execute');
    }

    public function test_draft_preview_does_not_cache(): void
    {
        if (!function_exists('apcu_fetch')) {
            $this->markTestSkipped('APCu not available');
        }
        apcu_clear_cache();
        putenv('CHAT2VIZ_QUERY_EXECUTOR=native');

        $callCount = 0;
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static function (string $sql, array $p) use (&$callCount): array {
                $callCount++;
                return [['region' => '华东', 'amount' => 1, 'phone' => '13812345678']];
            },
            fn(string $uid): ?array => $this->sampleV3Dsl()
        );

        $service->queryDraftWidgetData('uid-nc', 'w1');
        $service->queryDraftWidgetData('uid-nc', 'w1');

        // Draft preview MUST execute both times (no cache).
        $this->assertSame(2, $callCount, 'draft preview must not use APCu cache');
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: python mode socket unavailable → 503 (no silent fallback)
    // ─────────────────────────────────────────────────────────────────

    public function test_python_mode_draft_socket_unavailable_throws_503(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        // socketHandler = null → no socket injected → service should 503.
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            fn(string $uid): ?array => $this->sampleV3Dsl()
            // no socketHandler
        );

        try {
            $service->queryDraftWidgetData('uid-503', 'w1');
            $this->fail('expected DashboardException 503');
        } catch (DashboardException $e) {
            $this->assertSame(503, $e->getCode());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: legacy v2 draft stays PHP direct even under python mode
    // ─────────────────────────────────────────────────────────────────

    public function test_legacy_v2_draft_stays_php_direct_under_python_mode(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $rawCalled = 0;
        $socketCalled = 0;

        // Repo stub capturing rawQuery calls (v2 path uses executeRawQuery).
        $repo = new class($rawCalled) implements DashboardRepositoryInterface {
            public $rawCalled;
            public function __construct(int &$r) { $this->rawCalled = &$r; }
            public function list(int $p, int $pp, array $f = []): array { return []; }
            public function findByUid(string $uid): ?array
            {
                return ['uid' => $uid, 'current_schema' => [
                    'widgets' => [['id' => 'w1', 'type' => 'g2_chart', 'sql' => 'SELECT 1 LIMIT 5', 'g2_spec' => []]],
                ]];
            }
            public function create(array $d): array { return []; }
            public function update(string $u, array $d): array { return []; }
            public function archive(string $u): bool { return true; }
            public function delete(string $u): bool { return true; }
            public function publish(string $u, ?int $b = null, string $t = ''): array { return []; }
            public function getPublishedSchema(string $u): ?array { return null; }
            public function getVersions(string $u, int $p = 1, int $pp = 20): array { return []; }
            public function updateWidgetSql(string $u, string $w, string $s): void {}
            public function executeRawQuery(string $sql): array
            {
                $this->rawCalled++;
                return [['1' => 1]];
            }
            public function executeBoundQuery(string $sql, array $params = []): array { return []; }
        };

        $socket = new class($socketCalled) {
            public $called;
            public function __construct(int &$c) { $this->called = &$c; }
            public function transact(array $f): \Generator { $this->called++; yield ['rows' => []]; }
        };

        $service = new WidgetDataService($repo, null, $socket);
        $result = $service->queryDraftWidgetData('uid-v2', 'w1');

        $this->assertSame(1, $rawCalled, 'v2 draft MUST use PHP executeRawQuery');
        $this->assertSame(0, $socketCalled, 'v2 draft MUST NOT forward to python');
        $this->assertCount(1, $result->rows);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1.x: defensive SqlValidator before forwarding
    // ─────────────────────────────────────────────────────────────────

    public function test_python_forwarding_rejects_non_select_dsl(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $maliciousDsl = $this->sampleV3Dsl();
        $maliciousDsl['queries']['q1']['raw_sql'] = 'DELETE FROM sales WHERE region = :region';

        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            null,
            null,
            static function (array $frame): \Generator {
                yield ['rows' => []];
            }
        );

        // The defensive SqlValidator pass at the bridge rejects the DELETE
        // query before forwarding. It currently throws InvalidArgumentException;
        // the spec only requires rejection (not a specific exception class).
        $rejected = false;
        try {
            $service->queryPublicWidgetData('uid-attack', 'w1', '127.0.0.1', $maliciousDsl, []);
        } catch (\Throwable $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'non-SELECT dsl MUST be rejected before forwarding to python');
        // And the socket was never reached.
        $this->assertSame(0, $instr['socketCalled']);
    }

    public function test_python_forwarding_carries_dsl_in_frame(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR=python');
        $instr = [];
        $service = $this->makeInstrumentedService(
            $instr,
            static fn(string $sql, array $p): array => [],
            null,
            null,
            static function (array $frame): \Generator {
                yield ['rows' => [['region' => '华东']]];
            }
        );

        $service->queryPublicWidgetData('uid-frame', 'w1', '127.0.0.1', $this->sampleV3Dsl(), ['region' => '华东']);

        $this->assertCount(1, $instr['socketFrames']);
        $frame = $instr['socketFrames'][0];
        $this->assertSame('get_widget_data', $frame['method']);
        $this->assertSame('w1', $frame['params']['widget_id']);
        $this->assertSame(['region' => '华东'], $frame['params']['slicer_values']);
        $this->assertArrayHasKey('dsl', $frame['params']);
        $this->assertSame('3.0.0', $frame['params']['dsl']['version']);
    }

    protected function tearDown(): void
    {
        putenv('CHAT2VIZ_QUERY_EXECUTOR');  // clear
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        parent::tearDown();
    }
}
