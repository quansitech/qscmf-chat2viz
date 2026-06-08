<?php

namespace Qscmf\Chat2Viz\Adapter;

use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Repository\ThinkModelDashboardRepository;
use Qscmf\Chat2Viz\Repository\EloquentDashboardRepository;
use Qscmf\Chat2Viz\Renderer\PageRendererInterface;
use Qscmf\Chat2Viz\Renderer\SmartyRenderer;
use Qscmf\Chat2Viz\Renderer\InertiaRenderer;

class AdapterFactory
{
    /**
     * Create the appropriate repository based on runtime ORM availability.
     *
     * v13/v14: Think\Model exists -> ThinkModelDashboardRepository
     * v15:     Think\Model absent -> EloquentDashboardRepository
     */
    public static function createRepository(): DashboardRepositoryInterface
    {
        return class_exists('Think\Model')
            ? new ThinkModelDashboardRepository()
            : new EloquentDashboardRepository();
    }

    /**
     * Create the appropriate renderer based on runtime template engine availability.
     *
     * v13:     No Inertia -> SmartyRenderer
     * v14/v15: Inertia exists -> InertiaRenderer
     *
     * @param object $controller The calling controller instance (needed by SmartyRenderer)
     */
    public static function createRenderer(object $controller): PageRendererInterface
    {
        return class_exists('Qscmf\Lib\Inertia\Inertia')
            ? new InertiaRenderer()
            : new SmartyRenderer($controller);
    }
}
