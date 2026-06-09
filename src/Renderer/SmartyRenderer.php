<?php

namespace Qscmf\Chat2Viz\Renderer;

use Think\Controller;

/**
 * v13 Smarty-based renderer.
 *
 * Uses Closure::bind to call protected assign() / display() on the controller.
 */
class SmartyRenderer implements PageRendererInterface
{
    private Controller $controller;

    public function __construct(object $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Call a protected method on the controller via Closure binding.
     */
    private function callProtected(string $method, mixed ...$args): mixed
    {
        return (fn () => $this->{$method}(...$args))->call($this->controller);
    }

    public function renderList(array $dashboards, int $total, int $page, int $perPage): mixed
    {
        // NOTE: display() uses action name as template name (index→index.html, edit→edit.html)
        $this->callProtected('assign', 'meta_title', '仪表盘管理');
        $this->callProtected('assign', 'dashboards', $dashboards);
        $this->callProtected('assign', 'total', $total);
        $this->callProtected('assign', 'page', $page);
        $this->callProtected('assign', 'perPage', $perPage);
        $this->callProtected('display');
        return null;
    }

    public function renderEdit(?array $dashboard = null): mixed
    {
        // NOTE: display() uses action name as template name (index→index.html, edit→edit.html)
        // Parse current_schema so json_encode in the template does not double-encode it
        if ($dashboard !== null && isset($dashboard['current_schema']) && is_string($dashboard['current_schema'])) {
            $parsed = json_decode($dashboard['current_schema'], true);
            $dashboard['current_schema'] = is_array($parsed) ? $parsed : [];
        }

        $this->callProtected('assign', 'meta_title', $dashboard ? '编辑仪表盘' : '新建仪表盘');
        $this->callProtected('assign', 'dashboard', $dashboard);
        $this->callProtected('display');
        return null;
    }

    public function renderShow(array $dashboard, array $schema): mixed
    {
        $this->callProtected('assign', 'meta_title', $dashboard['title'] ?? '仪表盘');
        $this->callProtected('assign', 'dashboard', $dashboard);
        $this->callProtected('assign', 'schema', $schema);
        $this->callProtected('display'); // resolves to view.html (action=view)
        return null;
    }
}
