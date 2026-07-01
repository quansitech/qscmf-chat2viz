<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Model\ConversationMessage;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent (Laravel) implementation of MessageRepositoryInterface.
 *
 * Used when Think\Model is not available (v15+).
 */
class EloquentMessageRepository implements MessageRepositoryInterface
{
    private const VALID_ROLES = ['user', 'assistant', 'system'];

    private const VALID_STATUSES = ['streaming', 'complete', 'interrupted', 'failed'];

    public function createMessage(
        string $conversationId,
        string $role,
        string $content,
        ?array $metadata = null
    ): array {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new DashboardException('Invalid message role: ' . $role);
        }

        $message = ConversationMessage::create([
            'conversation_id' => $conversationId,
            'role'            => $role,
            'content'         => $content,
            'metadata'        => $metadata,
        ]);

        return $message->toArray();
    }

    public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array
    {
        $limit = min(max(1, $limit), 200);
        $offset = max(0, $offset);

        return ConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->skip($offset)
            ->take($limit)
            ->get()
            ->toArray();
    }

    public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array
    {
        return \Qscmf\Chat2Viz\Model\Conversation::where('dashboard_uid', $dashboardUid)
            ->where('status', \Gy_Library\DBCont::NORMAL_STATUS)
            ->selectRaw('id AS conversation_id, updated_at AS last_active')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function createPreallocatedAssistantMessage(
        string $conversationId
    ): int {
        $message = ConversationMessage::create([
            'conversation_id'   => $conversationId,
            'role'              => 'assistant',
            'content'           => '',
            'metadata'          => null,
            'reasoning_content' => null,
            'tool_calls'        => null,
            'message_status'    => 'streaming',
        ]);

        return (int)$message->id;
    }

    public function updateMessageWithMetadata(
        int $messageId,
        ?string $content = null,
        ?array $metadata = null,
        ?string $reasoningContent = null,
        ?array $toolCalls = null,
        ?string $messageStatus = null
    ): void {
        $message = ConversationMessage::find($messageId);
        if ($message === null) {
            throw new DashboardException('Message not found: ' . $messageId);
        }

        $updateData = [];

        if ($content !== null) {
            $updateData['content'] = $content;
        }

        if ($metadata !== null) {
            $updateData['metadata'] = $metadata;
        }

        if ($reasoningContent !== null) {
            $updateData['reasoning_content'] = $reasoningContent;
        }

        if ($toolCalls !== null) {
            $updateData['tool_calls'] = $toolCalls;
        }

        if ($messageStatus !== null) {
            if (!in_array($messageStatus, self::VALID_STATUSES, true)) {
                throw new DashboardException('Invalid message status: ' . $messageStatus);
            }
            $updateData['message_status'] = $messageStatus;
        }

        if (!empty($updateData)) {
            $message->fill($updateData)->save();
        }
    }

    public function findConversationHistory(string $conversationId): array
    {
        return ConversationMessage::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->toArray();
    }

    public function countByConversationId(string $conversationId): int
    {
        return (int) ConversationMessage::where('conversation_id', $conversationId)->count();
    }

    public function countByConversationIds(array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        $rows = ConversationMessage::whereIn('conversation_id', $conversationIds)
            ->selectRaw('conversation_id, COUNT(*) AS cnt')
            ->groupBy('conversation_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->conversation_id] = (int) $row->cnt;
        }

        return $result;
    }

    public function markLastStreamingOrphanInterrupted(string $conversationId): int
    {
        // Find the most-recent orphan: streaming + empty content, ORDER BY id DESC LIMIT 1.
        // MySQL cannot UPDATE ... WHERE id IN (SELECT ... FROM same table) directly, so we
        // fetch the id first then target it in a second, scoped UPDATE. Both operations are
        // cheap (indexed conversation_id + tiny result set) and the orphan is, by definition,
        // a single terminal-state row left by a prior interrupted request.
        $orphan = ConversationMessage::where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('message_status', 'streaming')
            ->where(function ($q) {
                $q->whereNull('content')->orWhere('content', '');
            })
            ->orderByDesc('id')
            ->first();

        if ($orphan === null) {
            return 0;
        }

        return (int) ConversationMessage::where('id', $orphan->id)
            ->where('message_status', 'streaming')  // re-guard: only flip if still streaming
            ->update(['message_status' => 'interrupted']);
    }

    public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int
    {
        if (!in_array($fromStatus, self::VALID_STATUSES, true) || !in_array($toStatus, self::VALID_STATUSES, true)) {
            throw new DashboardException('Invalid message status transition: ' . $fromStatus . '→' . $toStatus);
        }

        return (int) ConversationMessage::where('id', $messageId)
            ->where('message_status', $fromStatus)
            ->update(['message_status' => $toStatus]);
    }

    public function getRecentCompleteMessages(string $conversationId, int $limit): array
    {
        $limit = max(1, $limit);
        return ConversationMessage::where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->whereIn('message_status', ['complete', 'interrupted'])
            ->where(function ($q) {
                // non-empty content (trim-equivalent: exclude '' and null and whitespace-only
                // via a cheap server-side guard; DB-side trim keeps the index usable)
                $q->whereNotNull('content')->where('content', '!=', '');
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'content'])
            ->map(fn ($r) => ['role' => $r->role, 'content' => $r->content])
            ->all();
    }

    public function deleteLastTurnFromLastUser(string $conversationId): int
    {
        // Transaction guards against TOCTOU: a concurrent persistMessage could
        // insert a new user message between the find and the delete otherwise.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($conversationId): int {
            $lastUser = ConversationMessage::where('conversation_id', $conversationId)
                ->where('role', 'user')
                ->orderByDesc('id')
                ->first();

            if ($lastUser === null) {
                return 0;
            }

            return (int) ConversationMessage::where('conversation_id', $conversationId)
                ->where('id', '>=', $lastUser->id)
                ->delete();
        });
    }
}
