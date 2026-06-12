<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

/**
 * Unit tests for the D9 SQL-extraction fallback in
 * {@see EventRouter::routeEvent()}.
 *
 * When the Python agent only writes the SQL inside a markdown ```sql```
 * block (no sql_generated event, no sql field on chart_ready), the
 * EventRouter must still recover the SQL from the accumulated answer
 * text so that downstream widget persistence can include a `sql` field.
 *
 * @covers \Qscmf\Chat2Viz\Service\EventRouter::routeEvent
 */
class RouteEventSqlExtractionTest extends TestCase
{
    /** @var array<string, string>  Recorded accumulateSql calls: cid => sql */
    public array $accumulatedSql = [];

    /** @var array<string, string>  Recorded accumulateAnswer calls: cid => text */
    public array $accumulatedAnswer = [];

    /** @var array<string, array<string, string>>  Fake Redis state per cid */
    public array $fakeRedis = [];

    protected function setUp(): void
    {
        $this->accumulatedSql = [];
        $this->accumulatedAnswer = [];
        $this->fakeRedis = [];
    }

    /**
     * Build a StreamAccumulator test double that records calls and reads
     * from the in-memory $this->fakeRedis state. We bypass the constructor
     * (which would attempt a real Redis connection).
     */
    private function makeAccumulator(): StreamAccumulator
    {
        $acc = (new \ReflectionClass(StreamAccumulator::class))
            ->newInstanceWithoutConstructor();
        $accRef = new \ReflectionClass($acc);

        // Force the redisAvailable flag so isRedisAvailable() returns true.
        $prop = $accRef->getProperty('redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($acc, true);

        return $acc;
    }

    /**
     * Wrap the real accumulator in a proxy that records calls and reads
     * from a fake Redis hash stored in $this->fakeRedis.
     */
    private function makeProxy(): StreamAccumulator
    {
        $acc = $this->makeAccumulator();

        $recorder = $this;
        return new class($acc, $recorder) extends StreamAccumulator {
            public function __construct(
                private StreamAccumulator $inner,
                private RouteEventSqlExtractionTest $test
            ) {
            }

            public function isRedisAvailable(): bool
            {
                return true;
            }

            public function accumulateAnswer(string $conversationId, string $text): void
            {
                $this->test->accumulatedAnswer[$conversationId] =
                    ($this->test->accumulatedAnswer[$conversationId] ?? '') . $text;
                $this->test->fakeRedis[$conversationId]['content'] =
                    ($this->test->fakeRedis[$conversationId]['content'] ?? '') . $text;
            }

            public function accumulateSql(string $conversationId, string $sql): void
            {
                $this->test->accumulatedSql[$conversationId] = $sql;
                $this->test->fakeRedis[$conversationId]['sql'] = $sql;
            }

            public function peekField(string $conversationId, string $field): string
            {
                return (string) ($this->test->fakeRedis[$conversationId][$field] ?? '');
            }
        };
    }

    private function makeAnswerEvent(string $text): SseEvent
    {
        return new SseEvent(type: 'answer', data: ['text' => $text], raw: '');
    }

    private function makeRouter(): EventRouter
    {
        $repo = new class implements \Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface {
            public function list(int $page, int $perPage, array $filters = []): array { return []; }
            public function findByUid(string $uid): ?array { return null; }
            public function create(array $data): array { return []; }
            public function update(string $uid, array $data): array { return []; }
            public function archive(string $uid): bool { return true; }
            public function publish(string $uid, ?int $publishedBy = null): array { return []; }
            public function getPublishedSchema(string $uid): ?array { return null; }
            public function getVersions(string $uid, int $page = 1, int $perPage = 20): array { return []; }
            public function updateWidgetSql(string $uid, string $widgetId, string $sql): void {}
            public function executeRawQuery(string $sql): array { return []; }
        };

        return new EventRouter($repo, 'test-dash-uid');
    }

    private function callRouter(StreamAccumulator $acc, SseEvent $event): void
    {
        $router = $this->makeRouter();
        $router->routeEvent($acc, 'cid-1', $event);
    }

    // 1. Plain text with a single ```sql``` block — SQL must be extracted.
    public function testExtractsSqlFromSingleMarkdownBlock(): void
    {
        $acc = $this->makeProxy();
        $text = "好的，我帮你生成图表。\n\n```sql\nSELECT category, COUNT(*) FROM film GROUP BY category\n```\n\n这是结果说明。";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertArrayHasKey('cid-1', $this->accumulatedSql);
        $this->assertSame(
            'SELECT category, COUNT(*) FROM film GROUP BY category',
            $this->accumulatedSql['cid-1']
        );
    }

    // 2. Multiple SQL blocks — the LAST one wins (matches the most-recent intent).
    public function testExtractsLastSqlWhenMultipleBlocks(): void
    {
        $acc = $this->makeProxy();
        $text = "```sql\nSELECT 1\n```\n中间解释\n```sql\nSELECT category, COUNT(*) FROM film GROUP BY category\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertSame(
            'SELECT category, COUNT(*) FROM film GROUP BY category',
            $this->accumulatedSql['cid-1']
        );
    }

    // 3. Answer with NO SQL block — nothing should be accumulated.
    public function testNoSqlBlockLeavesAccumulatorUntouched(): void
    {
        $acc = $this->makeProxy();
        $this->callRouter($acc, $this->makeAnswerEvent('这是一个普通回答，没有 SQL。'));

        $this->assertArrayNotHasKey('cid-1', $this->accumulatedSql);
    }

    // 4. SQL already set (e.g. from a prior sql_generated event) — must NOT be overwritten.
    public function testDoesNotOverwriteExistingSql(): void
    {
        $acc = $this->makeProxy();
        $this->fakeRedis['cid-1']['sql'] = 'SELECT pre_existing FROM somewhere';

        $text = "我重新生成了一下\n```sql\nSELECT NEW FROM elsewhere\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        // accumulateSql must NOT be called — the pre-existing value is preserved.
        $this->assertArrayNotHasKey('cid-1', $this->accumulatedSql);
    }

    // 5. Case-insensitive ```SQL``` fence — must still extract.
    public function testExtractsCaseInsensitiveSqlFence(): void
    {
        $acc = $this->makeProxy();
        $text = "```SQL\nSELECT id, title FROM film\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertSame('SELECT id, title FROM film', $this->accumulatedSql['cid-1']);
    }

    // 6. Multi-line SQL inside the fence — must extract full body.
    public function testExtractsMultiLineSql(): void
    {
        $acc = $this->makeProxy();
        $text = "```sql\nSELECT\n  category,\n  COUNT(*) AS n\nFROM film\nGROUP BY category\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertSame(
            "SELECT\n  category,\n  COUNT(*) AS n\nFROM film\nGROUP BY category",
            $this->accumulatedSql['cid-1']
        );
    }

    // 7. Empty answer text — must not crash and not record anything.
    public function testEmptyAnswerIsNoOp(): void
    {
        $acc = $this->makeProxy();
        $this->callRouter($acc, $this->makeAnswerEvent(''));

        $this->assertEmpty($this->accumulatedAnswer);
        $this->assertEmpty($this->accumulatedSql);
    }
}
