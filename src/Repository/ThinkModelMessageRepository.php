<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * ThinkModel (ThinkPHP 3.x) implementation of MessageRepositoryInterface.
 *
 * Uses the M() helper for all database operations.
 * Table: qs_chat2viz_conversation_messages (prefix 'qs_' added by ThinkPHP convention).
 */
class ThinkModelMessageRepository implements MessageRepositoryInterface
{
    private const TABLE = 'chat2viz_conversation_messages';

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

        $insertData = [
            'conversation_id' => $conversationId,
            'role'            => $role,
            'content'         => $content,
            'metadata'        => $metadata !== null
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE)
                : null,
        ];

        $id = M(self::TABLE)->add($insertData);

        if (!$id) {
            throw new DashboardException('Failed to create conversation message');
        }

        $row = M(self::TABLE)->find($id);

        if (!is_array($row) || empty($row)) {
            throw new DashboardException('Failed to retrieve created message');
        }

        return $row;
    }

    public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array
    {
        $limit = min(max(1, $limit), 200);
        $offset = max(0, $offset);

        $rows = M(self::TABLE)
            ->where(['conversation_id' => $conversationId])
            ->order('created_at ASC')
            ->limit($offset, $limit)
            ->select();

        return is_array($rows) ? $rows : [];
    }

    public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array
    {
        $rows = M('chat2viz_conversations')
            ->field('id AS conversation_id, updated_at AS last_active')
            ->where(['dashboard_uid' => $dashboardUid, 'status' => \Gy_Library\DBCont::NORMAL_STATUS])
            ->order('updated_at DESC')
            ->limit($limit)
            ->select();

        return is_array($rows) ? $rows : [];
    }

    public function createPreallocatedAssistantMessage(
        string $conversationId
    ): int {
        $insertData = [
            'conversation_id'  => $conversationId,
            'role'             => 'assistant',
            'content'          => '',
            'metadata'         => null,
            'reasoning_content' => null,
            'tool_calls'       => null,
            'message_status'   => 'streaming',
        ];

        $id = M(self::TABLE)->add($insertData);

        if (!$id) {
            throw new DashboardException('Failed to create preallocated assistant message');
        }

        return (int)$id;
    }

    public function updateMessageWithMetadata(
        int $messageId,
        ?string $content = null,
        ?array $metadata = null,
        ?string $reasoningContent = null,
        ?array $toolCalls = null,
        ?string $messageStatus = null
    ): void {
        $updateData = [];

        if ($content !== null) {
            $updateData['content'] = $content;
        }

        if ($metadata !== null) {
            $updateData['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }

        if ($reasoningContent !== null) {
            $updateData['reasoning_content'] = $reasoningContent;
        }

        if ($toolCalls !== null) {
            $updateData['tool_calls'] = json_encode($toolCalls, JSON_UNESCAPED_UNICODE);
        }

        if ($messageStatus !== null) {
            if (!in_array($messageStatus, self::VALID_STATUSES, true)) {
                throw new DashboardException('Invalid message status: ' . $messageStatus);
            }
            $updateData['message_status'] = $messageStatus;
        }

        if (empty($updateData)) {
            return;
        }

        $affected = M(self::TABLE)
            ->where(['id' => $messageId])
            ->save($updateData);

        if ($affected === false) {
            throw new DashboardException('Failed to update message with metadata');
        }
    }

    public function findConversationHistory(string $conversationId): array
    {
        $rows = M(self::TABLE)
            ->where(['conversation_id' => $conversationId])
            ->order('created_at ASC')
            ->select();

        return is_array($rows) ? $rows : [];
    }

    public function countByConversationId(string $conversationId): int
    {
        $count = M(self::TABLE)
            ->where(['conversation_id' => $conversationId])
            ->count();

        return (int) $count;
    }

    public function countByConversationIds(array $conversationIds): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        $rows = M(self::TABLE)
            ->field('conversation_id, COUNT(*) AS cnt')
            ->where(['conversation_id' => ['in', $conversationIds]])
            ->group('conversation_id')
            ->select();

        $result = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $result[(string) $row['conversation_id']] = (int) $row['cnt'];
            }
        }

        return $result;
    }

    public function markLastStreamingOrphanInterrupted(string $conversationId): int
    {
        // Find the most-recent orphan (streaming + empty content), then flip it.
        $orphan = M(self::TABLE)
            ->where([
                'conversation_id' => $conversationId,
                'role'            => 'assistant',
                'message_status'  => 'streaming',
                'content'         => ['exp', "= '' OR content IS NULL"],
            ])
            ->order('id DESC')
            ->find();

        if (!$orphan || empty($orphan)) {
            return 0;
        }

        $affected = M(self::TABLE)
            ->where([
                'id'              => $orphan['id'],
                'message_status'  => 'streaming',  // re-guard: only flip if still streaming
            ])
            ->save(['message_status' => 'interrupted']);

        return $affected === false ? 0 : (int) $affected;
    }

    public function updateStatusAtomic(int $messageId, string $fromStatus, string $toStatus): int
    {
        if (!in_array($fromStatus, self::VALID_STATUSES, true) || !in_array($toStatus, self::VALID_STATUSES, true)) {
            throw new DashboardException('Invalid message status transition: ' . $fromStatus . '→' . $toStatus);
        }

        $affected = M(self::TABLE)
            ->where([
                'id'             => $messageId,
                'message_status' => $fromStatus,
            ])
            ->save(['message_status' => $toStatus]);

        return $affected === false ? 0 : (int) $affected;
    }

    public function getRecentCompleteMessages(string $conversationId, int $limit): array
    {
        $limit = max(1, $limit);
        $rows = M(self::TABLE)
            ->field('role, content')
            ->where([
                'conversation_id' => $conversationId,
                'role'            => ['in', ['user', 'assistant']],
                'message_status'  => ['in', ['complete', 'interrupted']],
            ])
            ->where('content IS NOT NULL AND content <> ""')
            ->order('id DESC')
            ->limit($limit)
            ->select();

        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['role' => $r['role'], 'content' => $r['content']];
        }
        return $out;
    }

    public function deleteLastTurnFromLastUser(string $conversationId): int
    {
        // Transaction guards against TOCTOU with a concurrent persistMessage.
        M()->startTrans();
        try {
            $lastUser = M(self::TABLE)
                ->where([
                    'conversation_id' => $conversationId,
                    'role'            => 'user',
                ])
                ->order('id DESC')
                ->find();

            if (!$lastUser || empty($lastUser)) {
                M()->commit();
                return 0;
            }

            $deleted = M(self::TABLE)
                ->where([
                    'conversation_id' => $conversationId,
                    'id'              => ['egt', $lastUser['id']],
                ])
                ->delete();

            M()->commit();
            return $deleted === false ? 0 : (int) $deleted;
        } catch (\Throwable $e) {
            M()->rollback();
            throw $e;
        }
    }
}
