<?php

namespace Qscmf\Chat2Viz\Traits;

/**
 * Shared filter whitelist logic for dashboard repositories.
 *
 * Prevents adapter drift between ThinkModel and Eloquent implementations
 * by centralising the allowed filter keys and their value validation.
 */
trait DashboardFilterTrait
{
    /**
     * Filter input array against the whitelist of allowed filter keys,
     * with value validation for enum and numeric fields.
     *
     * @return array<string, mixed> Sanitised filter map
     */
    private function filterWhitelist(array $filters): array
    {
        $safe = [];
        $validDashboardStatuses = ['draft', 'published', 'archived'];

        // Technical status: TINYINT (1=enabled, 0=disabled)
        if (isset($filters['status']) && is_numeric($filters['status'])) {
            $safe['status'] = (int) $filters['status'];
        }

        // Business status: ENUM (draft/published/archived)
        if (isset($filters['dashboard_status']) && in_array($filters['dashboard_status'], $validDashboardStatuses, true)) {
            $safe['dashboard_status'] = $filters['dashboard_status'];
        }

        if (isset($filters['created_by']) && is_numeric($filters['created_by'])) {
            $safe['created_by'] = (int) $filters['created_by'];
        }

        // Title search (LIKE match) — sanitized to prevent SQL injection
        if (isset($filters['title_like']) && is_string($filters['title_like']) && trim($filters['title_like']) !== '') {
            $safe['title_like'] = trim($filters['title_like']);
        }

        return $safe;
    }
}
