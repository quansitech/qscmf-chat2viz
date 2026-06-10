<?php

namespace Qscmf\Chat2Viz\Repository;

interface DashboardRepositoryInterface
{
    /**
     * List dashboards with pagination and optional filters.
     *
     * @param array $filters Whitelist keys: status, created_by
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function list(int $page, int $perPage, array $filters = []): array;

    /**
     * Find a dashboard by its UID.
     *
     * @return array|null Dashboard row as plain array, or null if not found
     */
    public function findByUid(string $uid): ?array;

    /**
     * Create a new dashboard.
     *
     * @param array $data Must contain at least 'title' and 'current_schema'
     * @return array The created dashboard as plain array
     */
    public function create(array $data): array;

    /**
     * Update an existing dashboard.
     *
     * @return array The updated dashboard as plain array
     */
    public function update(string $uid, array $data): array;

    /**
     * Archive (soft-delete) a dashboard.
     */
    public function archive(string $uid): bool;

    /**
     * Publish a dashboard: snapshot current_schema (strip g2_spec.data) as a version.
     *
     * @param string $uid Dashboard UID
     * @param int|null $publishedBy User ID of the publisher (falls back to created_by)
     * @return array The created version record as plain array
     */
    public function publish(string $uid, ?int $publishedBy = null): array;

    /**
     * Get the published schema for a dashboard.
     *
     * @return array|null The schema array, or null if not published
     */
    public function getPublishedSchema(string $uid): ?array;

    /**
     * List version history for a dashboard.
     *
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function getVersions(string $uid, int $page = 1, int $perPage = 20): array;
}
