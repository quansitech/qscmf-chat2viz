<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

/**
 * Sanitizes a dashboard schema before it reaches the public (anonymous)
 * view surface.
 *
 * fix-public-view-draft-exposure §1.2: the public view renders chart results
 * only — the raw SQL string behind each widget is "query intent" (table names,
 * column projections, filters) and must NOT reach the browser, where View
 * Source makes it trivially readable. `g2_spec` is the chart spec (NOT SQL)
 * and is intentionally preserved.
 *
 * Extracted as a pure, side-effect-free function so the security-critical
 * stripping logic is unit-testable without booting the ThinkPHP controller
 * stack (QsController → Think\Controller depends on the full runtime).
 */
final class PublicSchemaSanitizer
{
    /**
     * Return a copy of $schema with the `sql` key removed from every widget.
     *
     * Non-destructive: the input array is never mutated. Widgets lacking a
     * `sql` key pass through unchanged. Non-array widgets are skipped safely
     * (defense against malformed persisted schemas).
     *
     * @param array<string, mixed>|null $schema Dashboard schema, e.g.
     *   `['widgets' => [['id' => 'w1', 'sql' => 'SELECT ...', 'g2_spec' => [...]]], ...]`.
     * @return array<string, mixed> Sanitized schema (same shape, no `sql`).
     */
    public static function stripSqlFromWidgets(?array $schema): array
    {
        if (!is_array($schema)) {
            return [];
        }

        if (!isset($schema['widgets']) || !is_array($schema['widgets'])) {
            return $schema;
        }

        $cleaned = $schema;
        foreach ($cleaned['widgets'] as $i => $widget) {
            if (is_array($widget) && array_key_exists('sql', $widget)) {
                unset($cleaned['widgets'][$i]['sql']);
            }
        }
        return $cleaned;
    }

    /**
     * Return a copy of a dashboard ROW with leak-vectors removed for the
     * public (anonymous) view surface.
     *
     * fix-public-view-draft-exposure §1.2 (E2E-found regression): the dashboard
     * row carries `current_schema` — the raw DRAFT schema string, which embeds
     * every widget's SQL. This is distinct from the published_schema that
     * `stripSqlFromWidgets()` already cleans. The view template only consumes
     * the published `schema`, so `current_schema` is dead weight on the public
     * route AND a leak vector (View Source on __PAGE_DATA__ exposes it).
     *
     * Non-destructive: input is never mutated.
     *
     * @param array<string, mixed>|null $dashboard Dashboard row from the repo.
     * @return array<string, mixed> Row with `current_schema` unset (and any
     *   future leak-vectors added here). Null → empty array.
     */
    public static function stripDashboardRowForPublicView(?array $dashboard): array
    {
        if (!is_array($dashboard)) {
            return [];
        }
        $cleaned = $dashboard;
        unset($cleaned['current_schema']);
        return $cleaned;
    }
}
