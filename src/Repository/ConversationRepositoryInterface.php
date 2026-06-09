<?php

namespace Qscmf\Chat2Viz\Repository;

interface ConversationRepositoryInterface
{
    /**
     * Persist a single message in a conversation.
     *
     * @param string $conversationId Max 64 chars
     * @param string $dashboardUid   Max 64 chars
     * @param string $role           One of: user, assistant, system
     * @param string $content        Message body
     * @param array|null $metadata   Optional JSON-encodable metadata (sql, g2_spec, etc.)
     * @return array The created row as a plain array
     */
    public function createMessage(
        string $conversationId,
        string $dashboardUid,
        string $role,
        string $content,
        ?array $metadata = null
    ): array;

    /**
     * Load messages for a conversation, oldest first.
     *
     * @return array<int, array> List of message rows
     */
    public function getMessages(string $conversationId, int $limit = 50): array;

    /**
     * Get distinct conversation IDs for a dashboard, most recent first.
     *
     * @return array<int, array{conversation_id: string, last_active: string}>
     */
    public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array;
}
