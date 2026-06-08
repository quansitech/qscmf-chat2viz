<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Traits\UuidTrait;
use Qscmf\Chat2Viz\Traits\SchemaStripTrait;
use Think\Exception;

class ThinkModelDashboardRepository implements DashboardRepositoryInterface
{
    use UuidTrait;
    use SchemaStripTrait;
    private const TABLE_DASHBOARDS = 'chat2viz_dashboards';
    private const TABLE_VERSIONS = 'chat2viz_dashboard_versions';
    private const TABLE_MESSAGES = 'chat2viz_conversation_messages';

    private const FILTER_WHITELIST = ['status', 'created_by'];

    /**
     * @param array $filters Whitelist keys: status, created_by
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function list(int $page, int $perPage, array $filters = []): array
    {
        $model = M(self::TABLE_DASHBOARDS);
        $safeFilters = $this->filterWhitelist($filters);

        foreach ($safeFilters as $key => $value) {
            $model = $model->where([$key => $value]);
        }

        $total = (int)$model->count();
        $offset = ($page - 1) * $perPage;

        $items = $model
            ->order('id DESC')
            ->limit($offset . ',' . $perPage)
            ->select();

        if (!is_array($items)) {
            $items = [];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    public function findByUid(string $uid): ?array
    {
        $model = M(self::TABLE_DASHBOARDS);
        $row = $model->where(['uid' => $uid])->find();

        if (!is_array($row) || empty($row)) {
            return null;
        }

        return $row;
    }

    public function create(array $data): array
    {
        $uid = $this->generateUuid();
        $currentSchema = $data['current_schema'] ?? ['widgets' => [], 'layout' => [], 'variables' => []];
        $title = $data['title'] ?? '';

        $insertData = [
            'uid' => $uid,
            'title' => $title,
            'current_schema' => is_string($currentSchema)
                ? $currentSchema
                : json_encode($currentSchema, JSON_UNESCAPED_UNICODE),
            'status' => 'draft',
            'published_version_id' => null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ];

        $model = M(self::TABLE_DASHBOARDS);
        $id = $model->add($insertData);

        if (!$id) {
            throw new Exception('Failed to create dashboard');
        }

        return $this->findByUid($uid);
    }

    public function update(string $uid, array $data): array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            throw new Exception('Dashboard not found: ' . $uid);
        }

        $updateData = [];

        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }

        if (isset($data['current_schema'])) {
            $updateData['current_schema'] = is_string($data['current_schema'])
                ? $data['current_schema']
                : json_encode($data['current_schema'], JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['conversation_id'])) {
            $updateData['conversation_id'] = $data['conversation_id'];
        }

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        if (!empty($updateData)) {
            M(self::TABLE_DASHBOARDS)->where(['uid' => $uid])->save($updateData);
        }

        return $this->findByUid($uid);
    }

    public function archive(string $uid): bool
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            return false;
        }

        $affected = M(self::TABLE_DASHBOARDS)
            ->where(['uid' => $uid])
            ->save(['status' => 'archived']);

        return $affected !== false;
    }

    public function publish(string $uid): array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            throw new Exception('Dashboard not found: ' . $uid);
        }

        // 1. Deep copy current_schema
        $schemaRaw = $dashboard['current_schema'];
        $schema = is_string($schemaRaw)
            ? json_decode($schemaRaw, true)
            : $schemaRaw;

        if (!is_array($schema)) {
            $schema = [];
        }

        // 2. Recursively strip all g2_spec.data fields from widgets
        $schema = $this->stripG2SpecData($schema);

        // 3-5. Transaction: lock dashboard row, compute version, insert, update
        $dashboardId = (int)$dashboard['id'];

        M()->startTrans();
        try {
            // Pessimistic lock to prevent concurrent publish race
            $locked = M(self::TABLE_DASHBOARDS)
                ->where(['id' => $dashboardId])
                ->lock(true)
                ->find();

            if (!is_array($locked) || empty($locked)) {
                throw new Exception('Dashboard not found during lock: ' . $uid);
            }

            $maxVersion = M(self::TABLE_VERSIONS)
                ->where(['dashboard_id' => $dashboardId])
                ->max('version');

            $newVersion = ($maxVersion !== false && $maxVersion !== null)
                ? (int)$maxVersion + 1
                : 1;

            $versionData = [
                'dashboard_id' => $dashboardId,
                'version' => $newVersion,
                'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
                'published_at' => date('Y-m-d H:i:s'),
                'published_by' => $dashboard['created_by'] ?? null,
            ];

            $versionId = M(self::TABLE_VERSIONS)->add($versionData);

            if (!$versionId) {
                throw new Exception('Failed to create dashboard version');
            }

            M(self::TABLE_DASHBOARDS)
                ->where(['uid' => $uid])
                ->save([
                    'published_version_id' => $versionId,
                    'status' => 'published',
                ]);

            M()->commit();
        } catch (\Exception $e) {
            M()->rollback();
            throw $e;
        }

        // 6. Return assembled version snapshot (no extra query needed)
        $versionData['id'] = $versionId;
        return $versionData;
    }

    public function getPublishedSchema(string $uid): ?array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            return null;
        }

        $versionId = $dashboard['published_version_id'] ?? null;
        if ($versionId === null || $versionId === '') {
            return null;
        }

        $version = M(self::TABLE_VERSIONS)->find((int)$versionId);
        if (!is_array($version) || empty($version)) {
            return null;
        }

        $schema = $version['schema'];
        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }

        return is_array($schema) ? $schema : null;
    }

    public function getVersions(string $uid, int $page = 1, int $perPage = 20): array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }

        $dashboardId = (int)$dashboard['id'];
        $model = M(self::TABLE_VERSIONS)->where(['dashboard_id' => $dashboardId]);

        $total = (int)$model->count();
        $offset = ($page - 1) * $perPage;

        $items = $model
            ->order('version DESC')
            ->limit($offset . ',' . $perPage)
            ->select();

        if (!is_array($items)) {
            $items = [];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * Filter input array against the whitelist of allowed filter keys,
     * with value validation for enum and numeric fields.
     */
    private function filterWhitelist(array $filters): array
    {
        $safe = [];
        $validStatuses = ['draft', 'published', 'archived'];

        if (isset($filters['status']) && in_array($filters['status'], $validStatuses, true)) {
            $safe['status'] = $filters['status'];
        }
        if (isset($filters['created_by']) && is_numeric($filters['created_by'])) {
            $safe['created_by'] = (int) $filters['created_by'];
        }

        return $safe;
    }
}
