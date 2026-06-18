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

    /**
     * Read the show_sql feature flag from the environment.
     *
     * Default: hidden (false). Set CHAT2VIZ_SHOW_SQL=true to display the
     * per-widget "查询语句" panel — typically for debugging.
     *
     * Declared public static so it can be unit-tested without a controller.
     */
    public static function showSql(): bool
    {
        return filter_var(env('CHAT2VIZ_SHOW_SQL', false), FILTER_VALIDATE_BOOLEAN);
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
        // Explicitly target edit.html — both the `add` (new dashboard) and
        // `edit` (existing dashboard) actions render this SAME template so the
        // dashboard-edit React component is reused for both routes (routes stay
        // semantically distinct while the component is shared). display() would
        // otherwise default to ACTION_NAME, requiring an add.html duplicate.
        // Parse current_schema so json_encode in the template does not double-encode it
        if ($dashboard !== null && isset($dashboard['current_schema']) && is_string($dashboard['current_schema'])) {
            $parsed = json_decode($dashboard['current_schema'], true);
            $dashboard['current_schema'] = is_array($parsed) ? $parsed : [];
        }

        $this->callProtected('assign', 'meta_title', $dashboard ? '编辑仪表盘' : '新建仪表盘');
        $this->callProtected('assign', 'dashboard', $dashboard);
        $this->callProtected('assign', 'show_sql', self::showSql());
        $this->callProtected('display', 'edit');
        return null;
    }

    public function renderShow(array $dashboard, array $schema): mixed
    {
        $this->callProtected('assign', 'meta_title', $dashboard['title'] ?? '仪表盘');
        $this->callProtected('assign', 'dashboard', $dashboard);
        $this->callProtected('assign', 'schema', $schema);
        $this->callProtected('assign', 'show_sql', self::showSql());
        // Explicitly target view.html template
        // NOTE: Must pass 'view' because display() defaults to ACTION_NAME which is 'view'
        $this->callProtected('display', 'view');
        return null;
    }
}
