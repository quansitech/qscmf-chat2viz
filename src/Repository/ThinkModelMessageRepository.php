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
        string $dashboardUid,
        string $role,
        string $content,
        ?array $metadata = null
    ): array {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new DashboardException('Invalid message role: ' . $role);
        }

        $insertData = [
            'conversation_id' => $conversationId,
            'dashboard_uid'   => $dashboardUid,
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
        $rows = M(self::TABLE)
            ->field('conversation_id, MAX(created_at) AS last_active')
            ->where(['dashboard_uid' => $dashboardUid])
            ->group('conversation_id')
            ->order('last_active DESC')
            ->limit($limit)
            ->select();

        return is_array($rows) ? $rows : [];
    }

    public function createPreallocatedAssistantMessage(
        string $conversationId,
        string $dashboardUid
    ): int {
        $insertData = [
            'conversation_id'  => $conversationId,
            'dashboard_uid'    => $dashboardUid,
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
}
