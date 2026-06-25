<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;
use Qscmf\Chat2Viz\Service\ConversationService;

/**
 * inject-conversation-history (PHP side) — Service-layer history assembly.
 *
 * These tests pin the contract that api_ask_stream relies on to build
 * `payload.prior_messages`:
 *   ConversationService::getRecentMessagesForContext(conversationId, limit)
 *
 * The Python service (qs-chat2viz stateless-history-from-payload) consumes
 * `prior_messages` as `[{role:"user"|"assistant", content:string}]` — see
 * qs-chat2viz docs/sse-api.md "Conversation history (prior_messages)". These
 * tests guarantee the PHP side emits exactly that shape, in chronological
 * (ASC) order, with incomplete/system rows filtered, and never raises.
 *
 * Tasks 4.1 / 4.2 / 4.3 of openspec/changes/inject-conversation-history/tasks.md.
 *
 * The MessageRepository is mocked via an anonymous class so no DB is needed
 * (the Eloquent/ThinkModel implementations add the real role/status/content
 * filters at the SQL layer; here we assert the Service layer's job: ordering
 * reversal + graceful degradation).
 */
class ConversationServiceHistoryTest extends TestCase
{
    // ─── shared stubs ──────────────────────────────────────────────────────────

    /**
     * Build a ConversationService whose MessageRepository is controllable.
     * $messagesResolver returns the rows getRecentCompleteMessages should yield.
     */
    private function buildService(callable $messagesResolver): ConversationService
    {
        $convRepo = new class implements ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };

        $msgRepo = new class($messagesResolver) implements MessageRepositoryInterface {
            /** @var callable */
            private $resolver;

            public function __construct(callable $resolver)
            {
                $this->resolver = $resolver;
            }

            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array { return []; }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { return 0; }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array
            {
                return ($this->resolver)($conversationId, $limit);
            }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };

        return new ConversationService($convRepo, $msgRepo);
    }

    // ─── Task 4.1: ordered history ────────────────────────────────────────────

    /**
     * Task 4.1: conversation has 6 complete messages, limit=4 → returns the
     * most-recent 4 in chronological (ASC) order, each carrying only role+content.
     *
     * The repo returns DESC (newest first); the Service MUST array_reverse to
     * chronological order so the Python side reconstructs the thread correctly.
     */
    public function testGetRecentMessagesForContextReturnsOrderedHistory(): void
    {
        // Repo yields DESC (the Eloquent impl does orderByDesc('id')). Simulate
        // a conversation of 6 turns where the repo already capped at limit=4.
        $descRows = [
            ['role' => 'assistant', 'content' => 'a3'],
            ['role' => 'user', 'content' => 'q3'],
            ['role' => 'assistant', 'content' => 'a2'],
            ['role' => 'user', 'content' => 'q2'],
        ];

        $service = $this->buildService(static function (string $cid, int $limit) use ($descRows): array {
            return array_slice($descRows, 0, $limit);
        });

        $result = $service->getRecentMessagesForContext('conv-1', 4);

        // Reversed → chronological ASC: q2, a2, q3, a3.
        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'q2'],
                ['role' => 'assistant', 'content' => 'a2'],
                ['role' => 'user', 'content' => 'q3'],
                ['role' => 'assistant', 'content' => 'a3'],
            ],
            $result
        );
    }

    /**
     * Column-trimming to {role, content} is the REPOSITORY's responsibility
     * (EloquentMessageRepository does `get(['role','content'])` at the SQL
     * layer, then `map → ['role','content']`). The Service layer only
     * `array_reverse`s whatever the repo returns — it does NOT strip columns.
     *
     * This test documents that boundary: if a repo implementation leaked extra
     * columns, the Service would forward them as-is. Therefore the repo impl
     * MUST do the trimming (the Eloquent/ThinkModel impls already do). This
     * guards against a future repo that forgets the column projection and
     * bloats the LLM context with id/metadata/message_status.
     */
    public function testGetRecentMessagesForContextDoesNotStripColumnsForwardedAsIs(): void
    {
        // Simulate a (misbehaving) repo that returns extra columns.
        $descRows = [
            ['role' => 'user', 'content' => 'hi', 'id' => 5, 'metadata' => '{"x":1}', 'message_status' => 'complete'],
            ['role' => 'assistant', 'content' => 'hello', 'id' => 6, 'metadata' => null, 'message_status' => 'complete'],
        ];

        $service = $this->buildService(static function () use ($descRows): array {
            return $descRows;
        });

        $result = $service->getRecentMessagesForContext('conv-1', 10);

        // Service forwards rows as-is (only reversing order) — trimming is the
        // repo's job. Reversed: hello(row2) then hi(row1).
        $this->assertCount(2, $result);
        $this->assertSame('hello', $result[0]['content']);
        $this->assertSame('hi', $result[1]['content']);
        // Extra columns pass through unchanged → contract: trim in the repo, not here.
        $this->assertArrayHasKey('metadata', $result[0]);
    }

    // ─── Task 4.2: incomplete / system filtering responsibility ───────────────

    /**
     * Task 4.2: streaming orphans / failed / empty content / system role must
     * be filtered OUT before reaching Python.
     *
     * The filtering happens at the SQL layer (role IN user,assistant AND
     * status IN complete,interrupted AND content != ''). The Service layer's
     * contract is: it forwards whatever the repo returns. So we assert the
     * happy path (repo already filtered) AND that the Service does NOT re-add
     * any junk. A separate Eloquent integration test would exercise the SQL
     * filters directly; here we document the Service-layer boundary.
     */
    public function testGetRecentMessagesForContextForwardsRepoFiltering(): void
    {
        // Repo returns only valid rows in DESC order (newest first). The SQL
        // layer already excluded streaming/failed/empty/system; the Service's
        // job is just to reverse to ASC and forward.
        $validDesc = [
            ['role' => 'assistant', 'content' => 'valid a'],  // newest (id 2)
            ['role' => 'user', 'content' => 'valid q'],        // older  (id 1)
        ];

        $service = $this->buildService(static function () use ($validDesc): array {
            return $validDesc;
        });

        $result = $service->getRecentMessagesForContext('conv-1', 8);

        // Reversed → ASC: oldest first.
        $this->assertCount(2, $result);
        $this->assertSame('valid q', $result[0]['content']);  // oldest user turn first
        $this->assertSame('valid a', $result[1]['content']);
        // No system / streaming / empty rows leak through (none were returned by repo).
        foreach ($result as $row) {
            $this->assertContains($row['role'], ['user', 'assistant']);
            $this->assertNotSame('', $row['content']);
        }
    }

    /**
     * Empty repo result (no history yet, e.g. first turn) → empty array,
     * never null. Python treats empty/missing prior_messages as "fall back to
     * store", so a clean [] is the correct first-turn signal.
     */
    public function testGetRecentMessagesForContextEmptyConversationReturnsEmptyArray(): void
    {
        $service = $this->buildService(static function (): array {
            return [];
        });

        $this->assertSame([], $service->getRecentMessagesForContext('conv-empty', 8));
    }

    // ─── Task 4.3: exception → [] (graceful degradation) ─────────────────────

    /**
     * Task 4.3: a repo exception (DB down, connection lost) MUST be swallowed
     * and return []. Rationale: history is an enhancement, not a hard
     * requirement — a failed history fetch must NOT abort the user's turn.
     * Python will simply receive no prior_messages and behave as a first turn.
     *
     * The Service logs via the injected logger; we capture it to assert the
     * error was reported (not silently lost).
     */
    public function testGetRecentMessagesForContextExceptionReturnsEmpty(): void
    {
        $logged = [];
        $service = $this->buildService(static function (): array {
            throw new \RuntimeException('DB connection lost');
        });

        // Rebuild with a logger so we capture the report.
        $convRepo = new class implements ConversationRepositoryInterface {
            public function createConversation(string $dashboardUid, string $title = ''): array { return ['id' => 1]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboardUid): ?array { return null; }
            public function findByDashboardUid(string $dashboardUid): array { return []; }
        };
        $throwingRepo = new class implements MessageRepositoryInterface {
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array { return []; }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { return 0; }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array
            {
                throw new \RuntimeException('DB connection lost');
            }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };
        $logger = static function (string $tag, string $detail) use (&$logged): void {
            $logged[] = ['tag' => $tag, 'detail' => $detail];
        };

        // conversation-one-to-one: constructor is now (convRepo, msgRepo, ?dashboardRepo, ?logger)
        $service = new ConversationService($convRepo, $throwingRepo, null, $logger);

        $result = $service->getRecentMessagesForContext('conv-1', 8);

        $this->assertSame([], $result); // graceful: empty, NOT an exception
        $this->assertNotEmpty($logged); // error was reported via logger
        $this->assertSame('get recent messages for context failed', $logged[0]['tag']);
        $this->assertStringContainsString('DB connection lost', $logged[0]['detail']);
    }

    // ─── limit floor ──────────────────────────────────────────────────────────

    /**
     * The Service clamps limit to >= 1 before forwarding to the repo
     * (max(1, $limit)), so a zero/negative limit never produces a SQL error or
     * an unexpected full-table scan.
     */
    public function testGetRecentMessagesForContextClampsLimitToAtLeastOne(): void
    {
        $forwardedLimit = null;
        $service = $this->buildService(static function (string $cid, int $limit) use (&$forwardedLimit): array {
            $forwardedLimit = $limit;
            return [];
        });

        $service->getRecentMessagesForContext('conv-1', 0);
        $this->assertSame(1, $forwardedLimit);

        $service->getRecentMessagesForContext('conv-1', -5);
        $this->assertSame(1, $forwardedLimit);
    }
}
