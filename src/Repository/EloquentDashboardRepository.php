<?php

namespace Qscmf\Chat2Viz\Repository;

use Qscmf\Chat2Viz\Model\Dashboard;
use Qscmf\Chat2Viz\Model\DashboardVersion;
use Qscmf\Chat2Viz\Traits\UuidTrait;
use Qscmf\Chat2Viz\Traits\SchemaStripTrait;
use Illuminate\Support\Facades\DB;

class EloquentDashboardRepository implements DashboardRepositoryInterface
{
    use UuidTrait;
    use SchemaStripTrait;
    private const FILTER_WHITELIST = ['status', 'created_by'];

    /**
     * @param array $filters Whitelist keys: status, created_by
     * @return array{items: array<int, array>, total: int, page: int, perPage: int}
     */
    public function list(int $page, int $perPage, array $filters = []): array
    {
        $safeFilters = $this->filterWhitelist($filters);

        $query = Dashboard::query();
        foreach ($safeFilters as $key => $value) {
            $query->where($key, $value);
        }

        $total = $query->count();
        $items = $query
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    public function findByUid(string $uid): ?array
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            return null;
        }

        return $dashboard->toArray();
    }

    public function create(array $data): array
    {
        $uid = $this->generateUuid();
        $currentSchema = $data['current_schema'] ?? ['widgets' => [], 'layout' => [], 'variables' => []];

        $dashboard = Dashboard::create([
            'uid' => $uid,
            'title' => $data['title'] ?? '',
            'current_schema' => $currentSchema,
            'status' => 'draft',
            'published_version_id' => null,
            'conversation_id' => $data['conversation_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        return $dashboard->toArray();
    }

    public function update(string $uid, array $data): array
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            throw new \RuntimeException('Dashboard not found: ' . $uid);
        }

        $updateData = [];
        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        if (isset($data['current_schema'])) {
            $updateData['current_schema'] = $data['current_schema'];
        }
        if (isset($data['conversation_id'])) {
            $updateData['conversation_id'] = $data['conversation_id'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
        }

        if (!empty($updateData)) {
            $dashboard->fill($updateData)->save();
        }

        // fill() already updates in-memory attributes; no fresh() needed
        return $dashboard->toArray();
    }

    public function archive(string $uid): bool
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            return false;
        }

        return $dashboard->update(['status' => 'archived']);
    }

    public function publish(string $uid): array
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            throw new \RuntimeException('Dashboard not found: ' . $uid);
        }

        // 1. Deep copy current_schema
        $schema = $dashboard->current_schema;
        if (!is_array($schema)) {
            $schema = [];
        }

        // 2. Recursively strip all g2_spec.data fields from widgets
        $schema = $this->stripG2SpecData($schema);

        // 3-5. Transaction: lock, compute version, insert, update
        $versionArray = DB::transaction(function () use ($dashboard, $schema) {
            // Pessimistic lock to prevent concurrent publish race
            $locked = Dashboard::where('id', $dashboard->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new \RuntimeException('Dashboard not found during lock: ' . $dashboard->uid);
            }

            $maxVersion = DashboardVersion::where('dashboard_id', $dashboard->id)
                ->max('version');
            $newVersion = ($maxVersion !== null) ? (int)$maxVersion + 1 : 1;

            $version = DashboardVersion::create([
                'dashboard_id' => $dashboard->id,
                'version' => $newVersion,
                'schema' => $schema,
                'published_at' => now(),
                'published_by' => $dashboard->created_by,
            ]);

            $locked->update([
                'published_version_id' => $version->id,
                'status' => 'published',
            ]);

            return $version->toArray();
        });

        return $versionArray;
    }

    public function getPublishedSchema(string $uid): ?array
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            return null;
        }

        $versionId = $dashboard->published_version_id;
        if ($versionId === null) {
            return null;
        }

        $version = DashboardVersion::find($versionId);
        if ($version === null) {
            return null;
        }

        return $version->schema;
    }

    public function getVersions(string $uid, int $page = 1, int $perPage = 20): array
    {
        $dashboard = Dashboard::where('uid', $uid)->first();
        if ($dashboard === null) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage];
        }

        $query = DashboardVersion::where('dashboard_id', $dashboard->id);
        $total = $query->count();

        $items = $query
            ->orderBy('version', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->toArray();

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
