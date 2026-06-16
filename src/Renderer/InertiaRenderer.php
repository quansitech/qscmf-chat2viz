<?php

namespace Qscmf\Chat2Viz\Renderer;

/**
 * v14/v15 Inertia-based renderer.
 *
 * Delegates to Inertia::render() for SPA page rendering.
 */
class InertiaRenderer implements PageRendererInterface
{
    /**
     * Read the show_sql feature flag from the environment.
     *
     * Default: hidden (false). Set CHAT2VIZ_SHOW_SQL=true to display the
     * per-widget "查询语句" panel — typically for debugging.
     *
     * Declared public static so it can be unit-tested without Inertia boot.
     */
    public static function showSql(): bool
    {
        return filter_var(getenv('CHAT2VIZ_SHOW_SQL') ?: env('CHAT2VIZ_SHOW_SQL', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function renderList(array $dashboards, int $total, int $page, int $perPage): mixed
    {
        return \Qscmf\Lib\Inertia\Inertia::render('Chat2viz/DashboardList', [
            'dashboards' => $dashboards,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
        ]);
    }

    public function renderEdit(?array $dashboard = null): mixed
    {
        return \Qscmf\Lib\Inertia\Inertia::render('Chat2viz/DashboardEdit', [
            'dashboard' => $dashboard,
            'show_sql'  => self::showSql(),
        ]);
    }

    public function renderShow(array $dashboard, array $schema): mixed
    {
        return \Qscmf\Lib\Inertia\Inertia::render('Chat2viz/DashboardShow', [
            'dashboard' => $dashboard,
            'schema'    => $schema,
            'show_sql'  => self::showSql(),
        ]);
    }
}
