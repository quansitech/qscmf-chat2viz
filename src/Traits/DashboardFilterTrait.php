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
