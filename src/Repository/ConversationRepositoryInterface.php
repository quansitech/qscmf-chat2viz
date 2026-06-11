<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * Repository interface for managing conversation records (qs_chat2viz_conversations).
 *
 * A "conversation" groups messages into a logical session tied to a dashboard.
 * This interface handles conversation lifecycle: create, find, archive.
 */
interface ConversationRepositoryInterface
{
    /**
     * Create a new conversation for a dashboard.
     *
     * @param string $dashboardUid UUID v4 of the dashboard
     * @param string $title        Human-readable title (defaults to empty)
     * @return array The created conversation row as a plain array
     *
     * @throws DashboardException On persistence failure
     */
    public function createConversation(string $dashboardUid, string $title = ''): array;

    /**
     * Find a conversation by its primary key.
     *
     * @param int $id Conversation primary key
     * @return array|null Conversation row as plain array, or null if not found
     */
    public function findById(int $id): ?array;

    /**
     * Find the single active conversation for a dashboard.
     *
     * Returns the most recently created conversation where status = 1 (active).
     *
     * @param string $dashboardUid UUID v4 of the dashboard
     * @return array|null Conversation row, or null if no active conversation exists
     */
    public function findActiveByDashboardUid(string $dashboardUid): ?array;

    /**
     * List all conversations for a dashboard, newest first.
     *
     * @param string $dashboardUid UUID v4 of the dashboard
     * @return array<int, array> List of conversation rows
     */
    public function findByDashboardUid(string $dashboardUid): array;

    /**
     * Archive (soft-delete) a conversation by setting status = 0.
     *
     * @param int $id Conversation primary key
     * @return bool True if archived, false if not found
     */
    public function archive(int $id): bool;
}
