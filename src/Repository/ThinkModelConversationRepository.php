<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * ThinkModel (ThinkPHP 3.x) implementation of ConversationRepositoryInterface.
 *
 * Uses the M() helper for all database operations with parameterized queries.
 * Table: qs_chat2viz_conversations (prefix 'qs_' added by ThinkPHP convention).
 */
class ThinkModelConversationRepository implements ConversationRepositoryInterface
{
    private const TABLE = 'chat2viz_conversations';

    public function createConversation(string $dashboardUid, string $title = ''): array
    {
        $insertData = [
            'dashboard_uid' => $dashboardUid,
            'title'         => $title,
            'status'        => 1,
        ];

        $id = M(self::TABLE)->add($insertData);

        if (!$id) {
            throw new DashboardException('Failed to create conversation');
        }

        return $this->findById((int)$id) ?? ['id' => $id] + $insertData;
    }

    public function findById(int $id): ?array
    {
        $row = M(self::TABLE)->find($id);

        if (!is_array($row) || empty($row)) {
            return null;
        }

        return $row;
    }

    public function findActiveByDashboardUid(string $dashboardUid): ?array
    {
        // conversation-one-to-one: no status filter — UNIQUE(dashboard_uid)
        // guarantees at most one conversation per dashboard.
        $row = M(self::TABLE)
            ->where(['dashboard_uid' => $dashboardUid])
            ->order('created_at DESC')
            ->find();

        if (!is_array($row) || empty($row)) {
            return null;
        }

        return $row;
    }

    public function findByDashboardUid(string $dashboardUid): array
    {
        $rows = M(self::TABLE)
            ->where(['dashboard_uid' => $dashboardUid])
            ->order('created_at DESC')
            ->select();

        return is_array($rows) ? $rows : [];
    }
}
