<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * Repository interface for conversation message records (qs_chat2viz_conversation_messages).
 *
 * Handles individual message persistence within a conversation, including
 * the preallocated assistant message pattern used during SSE streaming.
 */
interface MessageRepositoryInterface
{
    /**
     * Persist a single message in a conversation.
     *
     * @param string   $conversationId Max 64 chars
     * @param string   $role           One of: user, assistant, system
     * @param string   $content        Message body
     * @param array|null $metadata     Optional JSON-encodable metadata (sql, g2_spec, etc.)
     * @return array The created row as a plain array
     *
     * @throws DashboardException On invalid role or persistence failure
     */
    public function createMessage(
        string $conversationId,
        string $role,
        string $content,
        ?array $metadata = null
    ): array;

    /**
     * Load messages for a conversation, oldest first.
     *
     * @param string $conversationId
     * @param int    $limit   Max 200, default 50
     * @param int    $offset  Number of messages to skip (for pagination)
     * @return array<int, array> List of message rows
     */
    public function getMessages(string $conversationId, int $limit = 50, int $offset = 0): array;

    /**
     * Get distinct conversation IDs for a dashboard, most recent first.
     *
     * @return array<int, array{conversation_id: string, last_active: string}>
     */
    public function getRecentConversationIds(string $dashboardUid, int $limit = 20): array;

    /**
     * Preallocate an assistant message row for streaming.
     *
     * Creates a message with role='assistant', content='', message_status='streaming'.
     * The returned ID is used to update content as SSE chunks arrive.
     *
     * @param string $conversationId Max 64 chars
     * @return int The auto-increment message ID
     *
     * @throws DashboardException On persistence failure
     */
    public function createPreallocatedAssistantMessage(
        string $conversationId
    ): int;

    /**
     * Update a preallocated or existing message with accumulated streaming data.
     *
     * All parameters except $messageId are optional — only provided fields are updated.
     *
     * @param int         $messageId        Primary key of the message
     * @param string|null $content          Full accumulated content (replaces previous)
     * @param array|null  $metadata         JSON-encodable metadata (sql, g2_spec, tool info)
     * @param string|null $reasoningContent Accumulated reasoning/thinking content
     * @param array|null  $toolCalls        Tool call records
     * @param string|null $messageStatus    One of: streaming, complete, interrupted, failed
     *
     * @throws DashboardException On update failure
     */
    public function updateMessageWithMetadata(
        int $messageId,
        ?string $content = null,
        ?array $metadata = null,
        ?string $reasoningContent = null,
        ?array $toolCalls = null,
        ?string $messageStatus = null
    ): void;

    /**
     * Load the full conversation history for a conversation ID.
     *
     * Returns all messages ordered by created_at ASC, with all fields
     * including metadata, reasoning_content, tool_calls, message_status.
     *
     * @param string $conversationId
     * @return array<int, array> List of message rows
     */
    public function findConversationHistory(string $conversationId): array;

    /**
     * Count messages for a given conversation.
     *
     * Used by conversation list API to derive message counts (not stored).
     *
     * @param string $conversationId
     * @return int
     */
    public function countByConversationId(string $conversationId): int;

    /**
     * Batch-count messages for multiple conversations in a single query.
     *
     * Returns a map of conversation_id => count.
     *
     * @param array<string> $conversationIds
     * @return array<string, int>
     */
    public function countByConversationIds(array $conversationIds): array;
}
