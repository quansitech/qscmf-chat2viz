<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Model\ConversationMessage;
use Illuminate\Support\Facades\DB;

class EloquentConversationRepository implements ConversationRepositoryInterface
{
    private const VALID_ROLES = ['user', 'assistant', 'system'];

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

        $message = ConversationMessage::create([
            'conversation_id' => $conversationId,
            'dashboard_uid'   => $dashboardUid,
            'role'            => $role,
            'content'         => $content,
            'metadata'        => $metadata,
            'created_at'      => date('Y-m-d H:i:s'),
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
        return ConversationMessage::where('dashboard_uid', $dashboardUid)
            ->select('conversation_id', DB::raw('MAX(created_at) AS last_active'))
            ->groupBy('conversation_id')
            ->orderByDesc('last_active')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
