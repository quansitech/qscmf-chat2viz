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
        $row = M(self::TABLE)
            ->where([
                'dashboard_uid' => $dashboardUid,
                'status'        => 1,
            ])
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

    public function archive(int $id): bool
    {
        $row = $this->findById($id);
        if ($row === null) {
            return false;
        }

        $affected = M(self::TABLE)
            ->where(['id' => $id])
            ->save(['status' => 0]);

        return $affected !== false;
    }
}
