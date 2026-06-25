<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\ConversationService;
use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;

/**
 * fix-stream-message-persistence: Service-layer coverage for the streaming-
 * orphan cleanup (tasks 4.1 / 4.2).
 *
 * ConversationService::finalizeOrphanedStreamingMessages cleans the tail orphan
 * (streaming + empty content) left by a prior interrupted request before a new
 * assistant row is preallocated. ensureNotStrandedStreaming is the shutdown
 * guard that atomically flips the preallocated row to 'interrupted' if it is
 * still 'streaming' at process exit.
 *
 * @covers \Qscmf\Chat2Viz\Service\ConversationService
 */
class ConversationServiceTest extends TestCase
{
    /** No-op ConversationRepository stub (unused by the methods under test). */
    private function noopConvRepo(): ConversationRepositoryInterface
    {
        return new class implements ConversationRepositoryInterface {
            public function createConversation(string $dashboard_uid, string $title = ''): array { return ['id' => 1, 'dashboard_uid' => $dashboard_uid]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboard_uid): ?array { return null; }
            public function findByDashboardUid(string $dashboard_uid): array { return []; }
        };
    }

    /**
     * Build a recording MessageRepository stub + the wired ConversationService.
     * Returns [service, msgRepo] so tests can assert on recorded calls.
     */
    private function makeService(
        int $orphanAffected = 0,
        int $statusAffected = 0
    ): array {
        $msgRepo = new class($orphanAffected, $statusAffected) implements MessageRepositoryInterface {
            private $orphanRows;
            private $statusRows;
            public $orphanCalls = [];
            public $statusCalls = [];
            public function __construct(int $o, int $s) { $this->orphanRows = $o; $this->statusRows = $s; }
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array { return []; }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int {
                $this->orphanCalls[] = $conversationId;
                return $this->orphanRows;
            }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int {
                $this->statusCalls[] = [$messageId, $fromStatus, $toStatus];
                return $this->statusRows;
            }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };
        return [new ConversationService($this->noopConvRepo(), $msgRepo, null), $msgRepo];
    }

    // ─── 4.1 finalizeOrphanedStreamingMessages ────────────────────────────────

    public function test_finalizeOrphanedStreamingMessages_clearsLastEmptyStreaming(): void
    {
        // Repository contract: the tail orphan (streaming + empty content) is
        // flipped to 'interrupted'. The service forwards conversation_id and
        // returns the affected-row count.
        [$service, $msgRepo] = $this->makeService(orphanAffected: 1);

        $result = $service->finalizeOrphanedStreamingMessages('conv-99');

        $this->assertSame(1, $result);
        $this->assertSame(['conv-99'], $msgRepo->orphanCalls);
    }

    public function test_finalizeOrphanedStreamingMessages_zeroWhenNoOrphan(): void
    {
        // No orphan → 0 affected rows (no complete message touched).
        [$service] = $this->makeService(orphanAffected: 0);

        $this->assertSame(0, $service->finalizeOrphanedStreamingMessages('conv-none'));
    }

    public function test_finalizeOrphanedStreamingMessages_swallowsExceptions(): void
    {
        // A repository failure must not bubble up (returns 0, logs).
        $msgRepo = new class implements MessageRepositoryInterface {
            public function createMessage(string $conversationId, string $role, string $content, ?array $metadata = null): array { return []; }
            public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array { return []; }
            public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array { return []; }
            public function createPreallocatedAssistantMessage(string $conversationId): int { return 0; }
            public function updateMessageWithMetadata(int $messageId, ?string $content = null, ?array $metadata = null, ?string $reasoningContent = null, ?array $toolCalls = null, ?string $messageStatus = null): void {}
            public function findConversationHistory(string $conversationId): array { return []; }
            public function countByConversationId(string $conversationId): int { return 0; }
            public function countByConversationIds(array $conversationIds): array { return []; }
            public function markLastStreamingOrphanInterrupted(string $conversationId): int { throw new \RuntimeException('db down'); }
            public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int { return 0; }
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { return 0; }
        };
        $logged = [];
        $service = new ConversationService(
            $this->noopConvRepo(), $msgRepo, null,
            static function (string $tag, string $detail) use (&$logged): void { $logged[] = $tag; }
        );

        $this->assertSame(0, $service->finalizeOrphanedStreamingMessages('conv-x'));
        $this->assertContains('finalize orphaned streaming failed', $logged);
    }

    // ─── 4.2 ensureNotStrandedStreaming (shutdown guard, idempotent) ───────────

    public function test_ensureNotStrandedStreaming_flipsStreamingToInterrupted(): void
    {
        // message_id set + still 'streaming' → atomic update to 'interrupted'.
        [$service, $msgRepo] = $this->makeService(statusAffected: 1);

        $service->ensureNotStrandedStreaming('conv-1', 555);

        $this->assertSame([[555, 'streaming', 'interrupted']], $msgRepo->statusCalls);
    }

    public function test_ensureNotStrandedStreaming_idempotent_whenAlreadyComplete(): void
    {
        // If finalizeStream already set 'complete', the atomic WHERE matches 0
        // rows — the call is a no-op (still invokes updateStatusAtomic, returns
        // 0, no error). This is the idempotency guarantee.
        [$service, $msgRepo] = $this->makeService(statusAffected: 0);

        $service->ensureNotStrandedStreaming('conv-1', 555);

        // Still called (the guard runs unconditionally), but affected=0.
        $this->assertCount(1, $msgRepo->statusCalls);
    }

    public function test_ensureNotStrandedStreaming_nullMessageIdIsNoOp(): void
    {
        // message_id null (no preallocated row) → guard does nothing, repo not called.
        [$service, $msgRepo] = $this->makeService();

        $service->ensureNotStrandedStreaming('conv-1', null);

        $this->assertSame([], $msgRepo->statusCalls);
    }
}
