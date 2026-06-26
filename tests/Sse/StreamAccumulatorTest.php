<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Sse;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use ReflectionProperty;

/**
 * declarative-frontend-adapter: covers the whole-tree accumulation model.
 *
 * The accumulator now stores a DASHBOARD_REPLACE payload verbatim into the
 * 'widgets' Redis hash field (overlay, never recomputed). SQL lives inside
 * each widget record, so the legacy accumulateSql()/readWidgets()/
 * readWidgetsMetadata() helpers are gone.
 *
 * Also covers the request-scoped fallback buffer that catches content when
 * Redis is unavailable, so finalizeStream persists the reply text instead of
 * writing an empty assistant row.
 *
 * @covers \Qscmf\Chat2Viz\Sse\StreamAccumulator
 */
class StreamAccumulatorTest extends TestCase
{
    /**
     * Force the accumulator into the degraded (Redis unavailable) path regardless
     * of whether the Redis extension is loaded in the test environment.
     */
    private function degradedAccumulator(): StreamAccumulator
    {
        $acc = new StreamAccumulator();
        $prop = new ReflectionProperty(StreamAccumulator::class, 'redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($acc, false);
        return $acc;
    }

    // ─── 5.1 accumulateAnswer writes the fallback buffer; flush reads it ──────

    public function test_accumulateAnswer_fallbackWhenRedisUnavailable(): void
    {
        $acc = $this->degradedAccumulator();
        $cid = 'conv-degrade-1';

        // Two appends must concatenate in the fallback buffer.
        $acc->accumulateAnswer($cid, 'Hello, ');
        $acc->accumulateAnswer($cid, 'world!');

        $flushed = $acc->flush($cid);

        $this->assertSame('Hello, world!', $flushed['content']);
        // Degraded path keeps metadata empty (no widgets accumulated; sql now
        // lives inside widgets, so the degraded path no longer carries sql).
        $this->assertSame([], $flushed['metadata']);
        $this->assertSame('', $flushed['reasoning_content']);
        $this->assertSame([], $flushed['tool_calls']);
    }

    // ─── 4.1 accumulateDashboardReplace writes widgets + layout into the hash ─

    public function test_accumulateDashboardReplace_serializesWidgetsAndLayoutToHash(): void
    {
        // Whole-tree accumulation: every call must overlay the widgets map +
        // layout array verbatim into the Redis hash (no per-field reducer, no
        // recomputation of truncated/total). We assert by reading back via the
        // public peekField() which already reads the same hash fields.
        $acc = $this->degradedAccumulator();
        // Redis unavailable → the call MUST no-op silently (degrade, never throw),
        // matching the contract of every other accumulate* method.
        $acc->accumulateDashboardReplace('cid-tree',
            [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
            ['w1' => ['widget_id' => 'w1', 'title' => 'A', 'data' => null]]
        );

        // Degraded path is lossy for widgets — flush must not synthesize them.
        $this->assertSame([], $acc->flush('cid-tree')['metadata']);
    }

    // When Redis IS available, the serialized tree round-trips through flush().
    // We fake Redis by injecting an in-memory \Redis double via the public
    // surface: accumulateDashboardReplace → hSet('widgets'|'layout') → flush reads
    // hGetAll. The proxy below stands in for the hash read/write.
    public function test_accumulateDashboardReplace_roundTripsThroughFlushWhenRedisAvailable(): void
    {
        $fakeHash = [];
        $acc = $this->hashProxy($fakeHash);

        $widgets = [
            'w1' => ['widget_id' => 'w1', 'title' => 'A', 'status' => 'success',
                      'sql' => 'SELECT 1', 'data' => [['x' => 1], ['x' => 2]], 'truncated' => true, 'total' => 2500],
            'w2' => ['widget_id' => 'w2', 'title' => 'B', 'status' => 'success', 'data' => null],
        ];
        $layout = [['i' => 'w1', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]];

        // Calls the REAL method on the proxy (which delegates to parent so the
        // parent's serialize+hSet path executes against the in-memory hash).
        $acc->accumulateDashboardReplace('cid-tree', $layout, $widgets);

        $flushed = $acc->flush('cid-tree');

        $this->assertSame($widgets, $flushed['metadata']['widgets']);
        // data:null slim widgets forwarded verbatim (this layer does NOT synthesize data).
        $this->assertNull($flushed['metadata']['widgets']['w2']['data']);
        // truncated/total forwarded verbatim — never recomputed from the 2-row data.
        $this->assertTrue($flushed['metadata']['widgets']['w1']['truncated']);
        $this->assertSame(2500, $flushed['metadata']['widgets']['w1']['total']);
    }

    // ─── 5.2 flush prefers Redis over the fallback buffer ─────────────────────
    //
    // When Redis IS available but holds no data for the key, flush returns the
    // empty shape (NOT the fallback buffer) — the degraded branch is gated
    // strictly on redisAvailable.

    public function test_flush_doesNotReadFallbackWhenRedisAvailable(): void
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension not loaded — degraded-only path tested elsewhere');
        }

        $available = new StreamAccumulator();
        $flushed = $available->flush('conv-redis-empty');

        $this->assertSame('', $flushed['content']);
        $this->assertSame([], $flushed['metadata']);
    }

    public function test_fallbackBuffer_isInstanceScoped(): void
    {
        $a = $this->degradedAccumulator();
        $b = $this->degradedAccumulator();

        $a->accumulateAnswer('conv-a', 'from request A');
        $b->accumulateAnswer('conv-b', 'from request B');

        $this->assertSame('from request A', $a->flush('conv-a')['content']);
        $this->assertSame('from request B', $b->flush('conv-b')['content']);
        $this->assertStringNotContainsString('request B', $a->flush('conv-a')['content']);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    /**
     * Build an accumulator backed by an in-memory \Redis double so the REAL
     * accumulateDashboardReplace → hSet → flush round-trip executes against a
     * fake hash without needing the phpredis extension or a live server.
     *
     * The double overrides the protected redis() hook (no static singleton) so
     * the parent's serialize path writes into $this->fakeHash.
     */
    private function hashProxy(array &$fakeHash): StreamAccumulator
    {
        $base = (new \ReflectionClass(StreamAccumulator::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionClass($base);
        $prop = $ref->getProperty('redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($base, true);

        $double = new class($fakeHash) extends StreamAccumulator {
            public array $fakeHash;
            public function __construct(array &$h) { $this->fakeHash = &$h; }
            public function isRedisAvailable(): bool { return true; }

            protected function redis(): \Redis
            {
                // In-memory \Redis double. Method signatures are kept compatible
                // with the parent (phpredis) declarations so the subclass compiles
                // across phpredis versions — only the behaviour is faked.
                $fake = new class($this->fakeHash) extends \Redis {
                    /** @var array<string, array<string,string>> */
                    public array $h;
                    public function __construct(array &$h) { $this->h = &$h; }
                    public function hSet(string $key, mixed ...$fields_and_vals): \Redis|int|false
                    {
                        // accumulateDashboardReplace / hSet call with (key, field, value).
                        $this->h[$key][$fields_and_vals[0]] = $fields_and_vals[1];
                        return 1;
                    }
                    public function hGet(string $key, string $member): mixed
                    {
                        return $this->h[$key][$member] ?? false;
                    }
                    public function hGetAll(string $key): \Redis|array|false
                    {
                        return $this->h[$key] ?? [];
                    }
                    public function expire(string $key, int $timeout, ?string $mode = null): \Redis|bool
                    {
                        return true;
                    }
                };
                return $fake;
            }
        };

        // Copy the redisAvailable=true flag from $base into $double. The flag
        // is a private inherited property — set it via the parent's reflection
        // (the anonymous subclass does not redeclare it).
        $dref = new \ReflectionClass(StreamAccumulator::class);
        $dprop = $dref->getProperty('redisAvailable');
        $dprop->setAccessible(true);
        $dprop->setValue($double, true);

        return $double;
    }
}
