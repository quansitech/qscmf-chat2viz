<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests\Service;

use PHPUnit\Framework\TestCase;
use Qscmf\Chat2Viz\Service\ConversationService;
use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;

/**
 * fix-redis-degrade-and-retry-dedup: retry-dedup coverage for
 * ConversationService::deleteLastTurn (tasks 5.3 / 5.4).
 *
 * deleteLastTurn backs the frontend retry flow: before resending a question,
 * the frontend calls api_delete_last_turn, which deletes the last user turn
 * (and everything after it) so the resend does not leave duplicate user rows.
 *
 * @covers \Qscmf\Chat2Viz\Service\ConversationService
 */
class RetryDedupConversationServiceTest extends TestCase
{
    /**
     * Build a ConversationService whose MessageRepository stub records the
     * conversation_id passed to deleteLastTurnFromLastUser and returns a
     * configurable affected-rows count.
     */
    private function makeService(int $affectedRows, array & $receivedCid = null): ConversationService
    {
        $receivedCid = null;
        $msgRepo = new class($affectedRows, $receivedCid) implements MessageRepositoryInterface {
            private $rows;
            private $received;
            public function __construct(int $rows, ?string & $received) { $this->rows = $rows; $this->received = &$received; }
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
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { $this->received = $conversationId; return $this->rows; }
        };
        // convRepo is unused by deleteLastTurn — a no-op stub suffices.
        $convRepo = new class implements ConversationRepositoryInterface {
            public function createConversation(string $dashboard_uid, string $title = ''): array { return ['id' => 1, 'dashboard_uid' => $dashboard_uid]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboard_uid): ?array { return null; }
            public function findByDashboardUid(string $dashboard_uid): array { return []; }
        };
        return new ConversationService($convRepo, $msgRepo, null);
    }

    // ─── 5.3 deleteLastTurn removes from the last user message ────────────────

    public function test_deleteLastTurn_removesFromLastUser(): void
    {
        // Repository contract: given [u1,a1,u2,a2,u3,a3], deleteLastTurnFromLastUser
        // deletes id >= the last user (u3), i.e. u3 + a3. The service is a thin
        // wrapper that forwards conversation_id and returns the affected count.
        $service = $this->makeService(2, $receivedCid);

        $result = $service->deleteLastTurn('12345');

        $this->assertSame(2, $result);
        // The conversation_id is forwarded verbatim to the repository.
        $this->assertSame('12345', $receivedCid);
    }

    // ─── 5.4 deleteLastTurn returns 0 when there is no user message ───────────

    public function test_deleteLastTurn_noUserReturnsZero(): void
    {
        // Repository returns 0 when no role=user row exists for the conversation.
        $service = $this->makeService(0);

        $this->assertSame(0, $service->deleteLastTurn('no-user-here'));
    }

    public function test_deleteLastTurn_swallowsExceptions_returnsZero(): void
    {
        // Robustness: a repository failure must not bubble up (the retry flow
        // aborts on falsy status, but the service itself never throws).
        $msgRepo = new class implements MessageRepositoryInterface {
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
            public function getRecentCompleteMessages(string $conversationId, int $limit): array { return []; }
            public function deleteLastTurnFromLastUser(string $conversationId): int { throw new \RuntimeException('db down'); }
        };
        $convRepo = new class implements ConversationRepositoryInterface {
            public function createConversation(string $dashboard_uid, string $title = ''): array { return ['id' => 1, 'dashboard_uid' => $dashboard_uid]; }
            public function findById(int $id): ?array { return null; }
            public function findActiveByDashboardUid(string $dashboard_uid): ?array { return null; }
            public function findByDashboardUid(string $dashboard_uid): array { return []; }
        };
        $logged = [];
        $service = new ConversationService($convRepo, $msgRepo, null, static function (string $tag, string $detail) use (&$logged): void {
            $logged[] = [$tag, $detail];
        });

        $this->assertSame(0, $service->deleteLastTurn('conv-x'));
        // The exception was logged (swallowed), not rethrown.
        $this->assertNotEmpty($logged);
        $this->assertSame('delete last turn failed', $logged[0][0]);
    }
}
