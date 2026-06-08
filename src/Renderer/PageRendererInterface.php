<?php

namespace Qscmf\Chat2Viz\Renderer;

interface PageRendererInterface
{
    /**
     * Render the dashboard list page.
     *
     * @param array<int, array> $dashboards List of dashboard rows
     */
    public function renderList(array $dashboards, int $total, int $page, int $perPage): mixed;

    /**
     * Render the dashboard edit page.
     *
     * @param array|null $dashboard Existing dashboard for editing, or null for new
     */
    public function renderEdit(?array $dashboard = null): mixed;

    /**
     * Render the published dashboard show page.
     *
     * @param array $dashboard The dashboard row
     * @param array $schema    The published schema (g2_spec.data stripped)
     */
    public function renderShow(array $dashboard, array $schema): mixed;
}
