<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Validator\DashboardValidator;

class DashboardService
{
    private DashboardRepositoryInterface $repo;

    public function __construct(DashboardRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Create a new dashboard.
     *
     * @param array $input Raw input from request
     * @param int|null $user_id Current user ID
     * @return array Created dashboard as plain array
     */
    public function create(array $input, ?int $user_id): array
    {
        DashboardValidator::validateCreate($input);

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = '未命名仪表盘';
        }

        $current_schema = $input['current_schema'] ?? ['widgets' => [], 'layout' => [], 'variables' => []];

        return $this->repo->create([
            'title'          => $title,
            'current_schema' => $current_schema,
            'created_by'     => $user_id,
        ]);
    }

    /**
     * Update a dashboard.
     *
     * @param string $uid Dashboard UID
     * @param array $input Raw update data
     * @param int|null $user_id Current user ID for ownership check
     * @return array Updated dashboard as plain array
     * @throws DashboardException When ownership check fails or validation fails
     */
    public function update(string $uid, array $input, ?int $user_id): array
    {
        $existing = $this->repo->findByUid($uid);
        if ($existing === null) {
            throw new DashboardNotFoundException($uid);
        }
        if (!$this->checkOwnership($existing, $user_id)) {
            throw new DashboardException('无权操作');
        }

        DashboardValidator::validateUpdate($input);

        $update_data = [];
        if (isset($input['title'])) {
            $update_data['title'] = trim((string) $input['title']);
        }
        if (isset($input['current_schema'])) {
            $update_data['current_schema'] = $input['current_schema'];
        }
        if (isset($input['dashboard_status'])) {
            $update_data['dashboard_status'] = $input['dashboard_status'];
        }

        if (empty($update_data)) {
            throw new DashboardException('没有可更新的字段');
        }

        return $this->repo->update($uid, $update_data);
    }

    /**
     * Archive (soft-delete) a dashboard.
     *
     * @param string $uid Dashboard UID
     * @param int|null $user_id Current user ID for ownership check
     * @return bool True if archived successfully
     * @throws DashboardException When ownership check fails
     */
    public function archive(string $uid, ?int $user_id): bool
    {
        $existing = $this->repo->findByUid($uid);
        if ($existing === null) {
            return false;
        }
        if (!$this->checkOwnership($existing, $user_id)) {
            throw new DashboardException('无权操作');
        }

        return $this->repo->archive($uid);
    }

    /**
     * Publish a dashboard (create version snapshot).
     *
     * @param string $uid Dashboard UID
     * @param int|null $user_id Current user ID for ownership check
     * @param string $title Optional publish-dialog title (§5); '' leaves stored title
     * @return array Created version record
     * @throws DashboardException When ownership check fails
     */
    public function publish(string $uid, ?int $user_id, string $title = ''): array
    {
        $existing = $this->repo->findByUid($uid);
        if ($existing === null) {
            throw new DashboardNotFoundException($uid);
        }
        if (!$this->checkOwnership($existing, $user_id)) {
            throw new DashboardException('无权操作');
        }

        return $this->repo->publish($uid, $user_id, $title);
    }

    /**
     * Get version history for a dashboard.
     *
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function getVersions(string $uid, int $page = 1, int $per_page = 20): array
    {
        return $this->repo->getVersions($uid, $page, $per_page);
    }

    /**
     * Check ownership of a dashboard.
     *
     * Controller should check the return value and send error response when false.
     * Currently 4 call sites: api_update, api_archive, api_publish, api_draft_widget_data.
     *
     * @param array $dashboard Dashboard row as plain array
     * @param int|null $current_user_id Current logged-in user ID
     * @return bool True if the current user owns the dashboard
     */
    public function checkOwnership(array $dashboard, ?int $current_user_id): bool
    {
        $owner_id = $dashboard['created_by'] ?? null;

        if ($current_user_id === null || $owner_id === null) {
            return false;
        }

        return (int) $owner_id === $current_user_id;
    }
}
