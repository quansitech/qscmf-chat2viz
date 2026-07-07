<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\EventRouter;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

/**
 * declarative-frontend-adapter: the D9 markdown-block SQL extraction fallback
 * was removed (SQL now lives inside DASHBOARD_REPLACE.widgets). These tests
 * now guard the inverse invariant: handleAnswer MUST accumulate answer text
 * and MUST NOT extract/accumulate SQL from ```sql``` blocks, regardless of
 * content.
 *
 * @covers \Qscmf\Chat2Viz\Service\EventRouter::routeEvent
 */
class RouteEventSqlExtractionTest extends TestCase
{
    /** @var array<string, string>  Recorded accumulateAnswer calls: cid => text */
    public array $accumulatedAnswer = [];

    protected function setUp(): void
    {
        $this->accumulatedAnswer = [];
    }

    /**
     * Build a StreamAccumulator test double that records accumulateAnswer calls.
     * Bypasses the constructor (no real Redis connection).
     */
    private function makeProxy(): StreamAccumulator
    {
        $acc = (new \ReflectionClass(StreamAccumulator::class))
            ->newInstanceWithoutConstructor();
        $accRef = new \ReflectionClass($acc);
        $prop = $accRef->getProperty('redisAvailable');
        $prop->setAccessible(true);
        $prop->setValue($acc, true);

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

    private function callRouter(StreamAccumulator $acc, SseEvent $event): void
    {
        $router = $this->makeRouter();
        $router->routeEvent($acc, 'cid-1', $event);
    }

    // 1. Answer text with a ```sql``` block is accumulated verbatim (SQL NOT
    //    extracted into a standalone field — it lives in DASHBOARD_REPLACE now).
    public function testAnswerWithSqlBlockAccumulatesTextWithoutExtraction(): void
    {
        $acc = $this->makeProxy();
        $text = "好的，我帮你生成图表。\n\n```sql\nSELECT category, COUNT(*) FROM film GROUP BY category\n```\n\n这是结果说明。";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        // Answer text accumulated exactly as-is (the code block is preserved).
        $this->assertSame($text, $this->accumulatedAnswer['cid-1']);
    }

    // 2. Multiple SQL blocks accumulate answer text without any extraction.
    public function testMultipleSqlBlocksAccumulateTextOnly(): void
    {
        $acc = $this->makeProxy();
        $text = "```sql\nSELECT 1\n```\n中间解释\n```sql\nSELECT 2\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertSame($text, $this->accumulatedAnswer['cid-1']);
    }

    // 3. Empty answer text — no-op, nothing recorded.
    public function testEmptyAnswerIsNoOp(): void
    {
        $acc = $this->makeProxy();
        $this->callRouter($acc, $this->makeAnswerEvent(''));

        $this->assertEmpty($this->accumulatedAnswer);
    }

    // 4. Case-insensitive ```SQL``` fence — still just answer text, no extraction.
    public function testCaseInsensitiveFenceNotExtracted(): void
    {
        $acc = $this->makeProxy();
        $text = "```SQL\nSELECT id, title FROM film\n```";
        $this->callRouter($acc, $this->makeAnswerEvent($text));

        $this->assertSame($text, $this->accumulatedAnswer['cid-1']);
    }
}
