<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;

class ConversationService
{
    private ConversationRepositoryInterface $convRepo;
    private MessageRepositoryInterface $msgRepo;
    private ?DashboardRepositoryInterface $dashboardRepo = null;

    /** @var callable */
    private $logger;

    public function __construct(
        ConversationRepositoryInterface $convRepo,
        MessageRepositoryInterface $msgRepo,
        ?DashboardRepositoryInterface $dashboardRepo = null,
        ?callable $logger = null
    ) {
        $this->convRepo = $convRepo;
        $this->msgRepo = $msgRepo;
        $this->dashboardRepo = $dashboardRepo;
        $this->logger = $logger ?? static function (string $tag, string $detail): void {
            \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
        };
    }

    private function getDashboardRepository(): DashboardRepositoryInterface
    {
        if ($this->dashboardRepo === null) {
            $this->dashboardRepo = AdapterFactory::createRepository();
        }
        return $this->dashboardRepo;
    }

    public function createConversation(string $dashboard_uid, string $title = ''): array
    {
        return $this->convRepo->createConversation($dashboard_uid, $title);
    }

    public function findActiveByDashboardUid(string $dashboard_uid): ?array
    {
        return $this->convRepo->findActiveByDashboardUid($dashboard_uid);
    }

    public function findByDashboardUid(string $dashboard_uid): array
    {
        return $this->convRepo->findByDashboardUid($dashboard_uid);
    }

    public function persistMessage(
        string $conversation_id,
        string $role,
        string $content
    ): void {
        try {
            $this->msgRepo->createMessage($conversation_id, $role, $content);
        } catch (\Throwable $e) {
            ($this->logger)('persist message failed', $e->getMessage());
        }
    }

    public function preallocateAssistantMessage(
        string $conversation_id
    ): ?int {
        try {
            return $this->msgRepo->createPreallocatedAssistantMessage(
                $conversation_id
            );
        } catch (\Throwable $e) {
            ($this->logger)('preallocate assistant message failed', $e->getMessage());
            return null;
        }
    }

    /**
     * fix-stream-message-persistence: clean the tail orphan (streaming + empty
     * content) left by a prior interrupted request before preallocating a new
     * assistant row. Marks it 'interrupted' (preserves audit trail; no delete).
     *
     * @return int Affected rows (0 if no orphan)
     */
    public function finalizeOrphanedStreamingMessages(string $conversation_id): int
    {
        try {
            return $this->msgRepo->markLastStreamingOrphanInterrupted($conversation_id);
        } catch (\Throwable $e) {
            ($this->logger)('finalize orphaned streaming failed', $e->getMessage());
            return 0;
        }
    }

    /**
     * fix-stream-message-persistence Decision 3: shutdown guard registered via
     * register_shutdown_function. Atomically flips the preallocated message from
     * 'streaming' to 'interrupted' ONLY if it is still 'streaming' at process
     * exit — idempotent w.r.t. finalizeStream (which sets 'complete'/'failed'
     * first; the atomic WHERE clause then matches 0 rows).
     *
     * Covers soft interrupts (script end / fatal error / client disconnect with
     * ignore_user_abort). Hard kills (SIGKILL/OOM) are NOT covered here — they
     * are masked by history-display filtering (api_conversation_history) and an
     * optional cron sweep.
     */
    public function ensureNotStrandedStreaming(string $conversation_id, ?int $message_id): void
    {
        if ($message_id === null) {
            return;
        }
        try {
            $this->msgRepo->updateStatusAtomic($message_id, 'streaming', 'interrupted');
        } catch (\Throwable $e) {
            ($this->logger)('ensure not stranded streaming failed', $e->getMessage());
        }
    }

    /**
     * inject-conversation-history: assemble the most-recent N turns (user +
     * assistant text only) for LLM context injection. Returns newest-first from
     * the repo, reversed to chronological order, each element {role, content}.
     * Swallows exceptions → returns [] (history assembly must never block the
     * main ask flow; Python falls back to its in-memory store).
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getRecentMessagesForContext(string $conversation_id, int $limit): array
    {
        try {
            $rows = $this->msgRepo->getRecentCompleteMessages($conversation_id, max(1, $limit));
            // repo returns DESC (newest first); reverse to chronological ASC
            return array_reverse($rows);
        } catch (\Throwable $e) {
            ($this->logger)('get recent messages for context failed', $e->getMessage());
            return [];
        }
    }

    /**
     * fix-redis-degrade-and-retry-dedup: delete the last turn (most-recent user
     * message and everything after it). Used by the retry flow to prevent
     * duplicate user rows from accumulating. Swallows exceptions → returns 0.
     */
    public function deleteLastTurn(string $conversation_id): int
    {
        try {
            return $this->msgRepo->deleteLastTurnFromLastUser($conversation_id);
        } catch (\Throwable $e) {
            ($this->logger)('delete last turn failed', $e->getMessage());
            return 0;
        }
    }

    /**
     * conversation-one-to-one-and-first-msg-init: 1:1 upsert by dashboard_uid.
     *
     * Invariant: ALWAYS returns a BIGINT string (the conversations.id), never a
     * hex client id. The messages.conversation_id column is BIGINT (FK →
     * conversations.id); a hex id triggers SQL 1366 and silently drops persistence.
     *
     * - If $conversation_id is already a pure integer, it is a previously unified
     *   BIGINT id (returned by the backend on an earlier turn) — reuse it as-is.
     * - Otherwise resolve by $dashboard_uid: reuse the existing conversation for
     *   that dashboard, or create one and return its id.
     * - If $dashboard_uid is empty AND $conversation_id is non-numeric, there is
     *   nothing to resolve here (first message on a brand-new dashboard) — return
     *   '' so the caller performs the first-message three-table init.
     */
    public function ensureConversation(string $conversation_id, string $dashboard_uid): string
    {
        // Case A — already a unified BIGINT id from a prior turn. Reuse directly.
        if (ctype_digit($conversation_id) && $conversation_id !== '') {
            return $conversation_id;
        }

        // First message on a new dashboard: caller handles init.
        if ($dashboard_uid === '') {
            return '';
        }

        // Case B — 1:1 upsert by dashboard_uid (UNIQUE constraint guarantees at
        // most one conversation per dashboard, so no archive is needed).
        try {
            $existing = $this->convRepo->findActiveByDashboardUid($dashboard_uid);
            if ($existing !== null) {
                return (string) $existing['id'];
            }

            $conv = $this->convRepo->createConversation($dashboard_uid, '');
            return (string) $conv['id'];
        } catch (\Throwable $e) {
            ($this->logger)('ensure conversation failed', $e->getMessage());
            return '';
        }
    }

    /**
     * conversation-one-to-one-and-first-msg-init: synchronously initialize the
     * three tables (dashboard + conversation + user message) for the first
     * message on a brand-new dashboard, inside a single transaction.
     *
     * Called by api_ask_stream when the request carries NO conversation_id (new
     * dashboard, first turn). The resulting BIGINT conversation_id replaces the
     * legacy hex client id for all persistence going forward, and the new
     * dashboard uid is echoed back to the frontend via the conversation_id SSE
     * frame so the URL can switch to edit mode.
     *
     * @return array{uid: string, conversation_id: string}
     *
     * @throws \Throwable When the transaction fails (caller surfaces a 5xx;
     *                    no orphan rows are left because the whole tx rolls back).
     */
    public function initializeOnFirstMessage(string $question, ?int $createdBy = null): array
    {
        $dashboardRepo = $this->getDashboardRepository();

        $dashboard = null;
        $conversationId = '';

        if (class_exists('Think\\Model')) {
            // ThinkPHP 3.x (v13/v14): startTrans/commit/rollback on an M() handle.
            $transModel = \M('chat2viz_dashboards');
            $transModel->startTrans();
            try {
                $dashboard = $this->createDraftDashboard($dashboardRepo, $createdBy);
                $conversationId = $this->createConversationForDashboard($dashboard['uid']);
                $this->msgRepo->createMessage($conversationId, 'user', $question);

                $transModel->commit();
            } catch (\Throwable $e) {
                $transModel->rollback();
                throw $e;
            }
        } else {
            // Eloquent (v15): DB::transaction wraps the closure atomically.
            // Variables captured by reference so the committed values escape.
            \Illuminate\Support\Facades\DB::transaction(function () use (
                $dashboardRepo, $question, &$dashboard, &$conversationId
            ): void {
                $dashboard = $this->createDraftDashboard($dashboardRepo, $createdBy);
                $conversationId = $this->createConversationForDashboard($dashboard['uid']);
                $this->msgRepo->createMessage($conversationId, 'user', $question);
            });
        }

        return [
            'uid'             => (string) $dashboard['uid'],
            'conversation_id' => (string) $conversationId,
        ];
    }

    /**
     * Create a draft dashboard (empty schema) for the first-message init.
     * created_by defaults to null — the controller passes the session user id
     * via the dashboard repo when available, but Chat2VizController (extends
     * module) has no framework auth, so null is the safe default.
     */
    private function createDraftDashboard(DashboardRepositoryInterface $dashboardRepo, ?int $createdBy = null): array
    {
        return $dashboardRepo->create([
            'title'          => '',
            'current_schema' => ['widgets' => [], 'layout' => [], 'variables' => []],
            'created_by'     => $createdBy,
        ]);
    }

    private function createConversationForDashboard(string $dashboardUid): string
    {
        $conv = $this->convRepo->createConversation($dashboardUid, '');
        return (string) $conv['id'];
    }

    public function finalizeStream(
        StreamAccumulator $accumulator,
        string $conversation_id,
        ?int $message_id,
        string $status
    ): void {
        try {
            $accumulated = $accumulator->flush($conversation_id);
            $accumulator->cleanup($conversation_id);
        } catch (\Throwable $e) {
            ($this->logger)('accumulator flush/cleanup failed', $e->getMessage());
            $accumulated = [
                'content'           => '',
                'metadata'          => [],
                'reasoning_content' => '',
                'tool_calls'        => [],
            ];
        }

        if ($message_id === null) {
            if ($accumulated['content'] !== '' && $status === 'complete') {
                $this->persistMessage($conversation_id, 'assistant', $accumulated['content']);
            }
            return;
        }

        try {
            $this->msgRepo->updateMessageWithMetadata(
                $message_id,
                $accumulated['content'],
                !empty($accumulated['metadata']) ? $accumulated['metadata'] : null,
                $accumulated['reasoning_content'] !== '' ? $accumulated['reasoning_content'] : null,
                !empty($accumulated['tool_calls']) ? $accumulated['tool_calls'] : null,
                $status
            );
        } catch (\Throwable $e) {
            ($this->logger)('finalize stream DB update failed', $e->getMessage());

            try {
                $this->msgRepo->updateMessageWithMetadata(
                    $message_id, null, null, null, null, $status
                );
            } catch (\Throwable $e2) {
                ($this->logger)('finalize stream status-only update failed', $e2->getMessage());
            }
        }
    }
}
