<?php

namespace Qscmf\Chat2Viz\Renderer;

/**
 * v13 Smarty-based renderer.
 *
 * Delegates to the GyController's assign() + display() methods.
 */
class SmartyRenderer implements PageRendererInterface
{
    private object $controller;

    public function __construct(object $controller)
    {
        $this->controller = $controller;
    }

    public function renderList(array $dashboards, int $total, int $page, int $perPage): mixed
    {
        $this->controller->assign('meta_title', '仪表盘管理');
        $this->controller->assign('dashboards', $dashboards);
        $this->controller->assign('total', $total);
        $this->controller->assign('page', $page);
        $this->controller->assign('perPage', $perPage);
        $this->controller->display();
        return null;
    }

    public function renderEdit(?array $dashboard = null): mixed
    {
        $this->controller->assign('meta_title', $dashboard ? '编辑仪表盘' : '新建仪表盘');
        $this->controller->assign('dashboard', $dashboard);
        $this->controller->display();
        return null;
    }

    public function renderShow(array $dashboard, array $schema): mixed
    {
        $this->controller->assign('meta_title', $dashboard['title'] ?? '仪表盘');
        $this->controller->assign('dashboard', $dashboard);
        $this->controller->assign('schema', $schema);
        $this->controller->display();
        return null;
    }
}
