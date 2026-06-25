<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;

/**
 * Repository interface for managing conversation records (qs_chat2viz_conversations).
 *
 * conversation-one-to-one-and-first-msg-init: a conversation has a strict 1:1
 * relationship with its dashboard (enforced by a UNIQUE constraint on
 * dashboard_uid). There is at most one conversation per dashboard, so the
 * "active/archived" status machine is removed — findActiveByDashboardUid no
 * longer filters by status, and archive() is removed.
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
     * Find the single conversation for a dashboard (1:1).
     *
     * conversation-one-to-one: no status filter — there is at most one
     * conversation per dashboard (UNIQUE dashboard_uid).
     *
     * @param string $dashboardUid UUID v4 of the dashboard
     * @return array|null Conversation row, or null if none exists
     */
    public function findActiveByDashboardUid(string $dashboardUid): ?array;

    /**
     * List all conversations for a dashboard, newest first.
     *
     * @param string $dashboardUid UUID v4 of the dashboard
     * @return array<int, array> List of conversation rows (at most one under 1:1)
     */
    public function findByDashboardUid(string $dashboardUid): array;
}
