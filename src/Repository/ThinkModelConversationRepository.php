<?php

namespace Qscmf\Chat2Viz\Repository;

use Think\Exception;

class ThinkModelConversationRepository implements ConversationRepositoryInterface
{
    private const TABLE = 'chat2viz_conversation_messages';

    private const VALID_ROLES = ['user', 'assistant', 'system'];

    public function createMessage(
        string $conversationId,
        string $dashboardUid,
        string $role,
        string $content,
        ?array $metadata = null
    ): array {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new Exception('Invalid message role: ' . $role);
        }

        $insertData = [
            'conversation_id' => $conversationId,
            'dashboard_uid'   => $dashboardUid,
            'role'            => $role,
            'content'         => $content,
            'metadata'        => $metadata !== null
                ? json_encode($metadata, JSON_UNESCAPED_UNICODE)
                : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ];

        $id = M(self::TABLE)->add($insertData);

        if (!$id) {
            throw new Exception('Failed to create conversation message');
        }

        $row = M(self::TABLE)->find($id);

        if (!is_array($row) || empty($row)) {
            throw new Exception('Failed to retrieve created message');
        }

        return $row;
    }

    public function getMessages(string $conversationId, int $limit = 50): array
    {
        $rows = M(self::TABLE)
            ->where(['conversation_id' => $conversationId])
            ->order('created_at ASC')
            ->limit($limit)
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
}
