<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;
use Qscmf\Chat2Viz\Traits\DashboardFilterTrait;
use Qscmf\Chat2Viz\Traits\UuidTrait;
use Qscmf\Chat2Viz\Traits\SchemaStripTrait;

class ThinkModelDashboardRepository implements DashboardRepositoryInterface
{
    use UuidTrait;
    use SchemaStripTrait;
    use DashboardFilterTrait;
    private const TABLE_DASHBOARDS = 'chat2viz_dashboards';
    private const TABLE_VERSIONS = 'chat2viz_dashboard_versions';
    private const TABLE_MESSAGES = 'chat2viz_conversation_messages';

    /**
     * Create a fresh ThinkModel with where conditions applied.
     *
     * Uses the whitelist to build safe conditions and returns a new model
     * instance each time, preventing cross-query state leakage.
     *
     * @param string $table Table constant (self::TABLE_DASHBOARDS or self::TABLE_VERSIONS)
     * @param array $filters Raw filter input
     * @return \Think\Model Model with where() already applied (or raw model if no filters)
     */
    private function buildFilteredQuery(string $table, array $filters): \Think\Model
    {
        $where = [];
        $safeFilters = $this->filterWhitelist($filters);
        foreach ($safeFilters as $key => $value) {
            $where[$key] = $value;
        }

        $model = M($table);
        if (!empty($where)) {
            $model->where($where);
        }
        return $model;
    }

    /**
     * Create a fresh ThinkModel with explicit where conditions (no whitelist filtering).
     *
     * Used for internal queries with known-safe conditions (e.g. dashboard_id from a UID lookup).
     *
     * @param string $table Table constant
     * @param array<string, mixed> $where Pre-built where conditions
     * @return \Think\Model Model with where() already applied
     */
    private function buildWhereQuery(string $table, array $where): \Think\Model
    {
        $model = M($table);
        if (!empty($where)) {
            $model->where($where);
        }
        return $model;
    }

    /**
     * @param array $filters Whitelist keys: status, created_by
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function list(int $page, int $perPage, array $filters = []): array
    {
        $total = (int)$this->buildFilteredQuery(self::TABLE_DASHBOARDS, $filters)->count();

        $offset = ($page - 1) * $perPage;
        $items = $this->buildFilteredQuery(self::TABLE_DASHBOARDS, $filters)
            ->order('id DESC')
            ->limit($offset, $perPage)
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
            'status' => \Gy_Library\DBCont::NORMAL_STATUS,
            'dashboard_status' => 'draft',
            'published_version_id' => null,
            'created_by' => $data['created_by'] ?? null,
        ];

        $model = M(self::TABLE_DASHBOARDS);
        $id = $model->add($insertData);

        if (!$id) {
            throw new DashboardException('Failed to create dashboard');
        }

        return $this->findByUid($uid);
    }

    public function update(string $uid, array $data): array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            throw new DashboardNotFoundException($uid);
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

        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        if (isset($data['dashboard_status'])) {
            $updateData['dashboard_status'] = $data['dashboard_status'];
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
            ->save(['dashboard_status' => 'archived']);

        return $affected !== false;
    }

    public function publish(string $uid, ?int $publishedBy = null, string $title = ''): array
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            throw new DashboardNotFoundException($uid);
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
        $effectivePublishedBy = $publishedBy ?? (isset($dashboard['created_by']) ? (int)$dashboard['created_by'] : null);

        $transModel = M(self::TABLE_DASHBOARDS);
        $transModel->startTrans();
        try {
            // Pessimistic lock to prevent concurrent publish race
            $locked = $transModel
                ->where(['id' => $dashboardId])
                ->lock(true)
                ->find();

            if (!is_array($locked) || empty($locked)) {
                throw new DashboardNotFoundException($uid);
            }

            // Persist the publish-dialog title (§5) BEFORE the version snapshot.
            // A non-empty title updates the dashboard row so the published view,
            // <title> tag and <h1> reflect the user-supplied title. Empty title
            // leaves the stored value untouched (no clobber of prior edits).
            $rowUpdate = [
                'published_version_id' => null, // set below after insert
                'dashboard_status' => 'published',
            ];
            if (is_string($title) && trim($title) !== '') {
                $rowUpdate['title'] = trim($title);
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
                'published_by' => $effectivePublishedBy,
            ];

            $versionId = M(self::TABLE_VERSIONS)->add($versionData);

            if (!$versionId) {
                throw new DashboardException('Failed to create dashboard version');
            }

            $rowUpdate['published_version_id'] = $versionId;
            $transModel
                ->where(['uid' => $uid])
                ->save($rowUpdate);

            $transModel->commit();
        } catch (DashboardException $e) {
            $transModel->rollback();
            throw $e;
        } catch (\Think\Exception $e) {
            $transModel->rollback();
            throw new DashboardException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            $transModel->rollback();
            throw new DashboardException($e->getMessage(), 0, $e);
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

        $where = ['dashboard_id' => (int)$dashboard['id']];

        $total = (int)$this->buildWhereQuery(self::TABLE_VERSIONS, $where)->count();

        $offset = ($page - 1) * $perPage;
        $items = $this->buildWhereQuery(self::TABLE_VERSIONS, $where)
            ->order('version DESC')
            ->limit($offset, $perPage)
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

    public function updateWidgetSql(string $uid, string $widgetId, string $sql): void
    {
        $dashboard = $this->findByUid($uid);
        if ($dashboard === null) {
            throw new DashboardNotFoundException($uid);
        }

        $schemaRaw = $dashboard['current_schema'];
        $schema = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;
        if (!is_array($schema)) {
            return;
        }

        $widgets = $schema['widgets'] ?? [];
        $found = false;
        foreach ($widgets as $index => $widget) {
            if (is_array($widget) && ($widget['id'] ?? '') === $widgetId) {
                $widgets[$index]['sql'] = $sql;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return;
        }

        $schema['widgets'] = $widgets;
        M(self::TABLE_DASHBOARDS)
            ->where(['uid' => $uid])
            ->save([
                'current_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE),
            ]);
    }

    public function executeRawQuery(string $sql): array
    {
        $rows = M()->query($sql);
        return is_array($rows) ? $rows : [];
    }
}
