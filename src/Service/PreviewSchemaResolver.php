<?php
declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

/**
 * fix-draft-view-restore: pure resolver for the admin preview action.
 *
 * The preview action renders a read-only view using the dashboard's
 * current_schema (the live draft). `findByUid()` returns current_schema as a
 * JSON string, but renderShow expects a decoded array. This helper centralizes
 * the decode + fallback so it is unit-testable without the controller stack
 * (the global I()/C() helpers are unavailable under PHPUnit — mirrors the
 * PublicViewStatusGuard extraction pattern).
 */
final class PreviewSchemaResolver
{
    /**
     * Decode a dashboard row's current_schema into the array shape renderShow
     * expects. Returns [] for a missing/empty/invalid current_schema so the
     * preview never fatal-errors on malformed draft rows.
     *
     * @param array<string, mixed>|null $dashboard Dashboard row (may carry a
     *   `current_schema` key that is a JSON string, an array, or absent).
     * @return array{widgets?:array, layout?:array}
     */
    public static function resolveFromCurrentSchema(?array $dashboard): array
    {
        if (!is_array($dashboard)) {
            return [];
        }
        $raw = $dashboard['current_schema'] ?? null;
        if (empty($raw)) {
            return [];
        }
        $parsed = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($parsed) ? $parsed : [];
    }
}
