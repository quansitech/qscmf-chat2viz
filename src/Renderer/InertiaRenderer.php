<?php

namespace Qscmf\Chat2Viz\Renderer;

/**
 * v14/v15 Inertia-based renderer.
 *
 * Delegates to Inertia::render() for SPA page rendering.
 */
class InertiaRenderer implements PageRendererInterface
{
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
        ]);
    }

    public function renderShow(array $dashboard, array $schema): mixed
    {
        return \Qscmf\Lib\Inertia\Inertia::render('Chat2viz/DashboardShow', [
            'dashboard' => $dashboard,
            'schema'    => $schema,
        ]);
    }
}
