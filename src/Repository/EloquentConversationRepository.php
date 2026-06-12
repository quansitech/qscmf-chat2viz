<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Model\Conversation;

/**
 * Eloquent (Laravel) implementation of ConversationRepositoryInterface.
 *
 * Used when Think\Model is not available (v15+).
 */
class EloquentConversationRepository implements ConversationRepositoryInterface
{
    public function createConversation(string $dashboardUid, string $title = ''): array
    {
        $conversation = Conversation::create([
            'dashboard_uid' => $dashboardUid,
            'title'         => $title,
            'status'        => 1,
        ]);

        return $conversation->toArray();
    }

    public function findById(int $id): ?array
    {
        $conversation = Conversation::find($id);
        if ($conversation === null) {
            return null;
        }

        return $conversation->toArray();
    }

    public function findActiveByDashboardUid(string $dashboardUid): ?array
    {
        $conversation = Conversation::where('dashboard_uid', $dashboardUid)
            ->where('status', 1)
            ->orderByDesc('created_at')
            ->first();

        if ($conversation === null) {
            return null;
        }

        return $conversation->toArray();
    }

    public function findByDashboardUid(string $dashboardUid): array
    {
        return Conversation::where('dashboard_uid', $dashboardUid)
            ->orderByDesc('created_at')
            ->get()
            ->toArray();
    }

    public function archive(int $id): bool
    {
        $conversation = Conversation::find($id);
        if ($conversation === null) {
            return false;
        }

        return $conversation->update(['status' => \Gy_Library\DBCont::FORBIDDEN_STATUS]);
    }
}
