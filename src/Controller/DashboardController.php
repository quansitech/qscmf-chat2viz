<?php

namespace Qscmf\Chat2Viz\Controller;

use Gy_Library\GyController;
use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Renderer\PageRendererInterface;
use Qscmf\Chat2Viz\Security\SqlValidator;
use Qscmf\Chat2Viz\Traits\JsonInputTrait;

class DashboardController extends GyController
{
    use JsonInputTrait;

    protected DashboardRepositoryInterface $repo;
    protected PageRendererInterface $renderer;

    /**
     * Actions that bypass the default GyController auth check.
     * These are publicly accessible (e.g. published dashboard view, widget data).
     *
     * @var string[]
     */
    protected array $publicActions = ['view', 'api_widget_data'];

    protected function _initialize()
    {
        if (in_array(ACTION_NAME, $this->publicActions, true)) {
            // Skip parent auth — initialize adapters only
            $this->repo = AdapterFactory::createRepository();
            $this->renderer = AdapterFactory::createRenderer($this);
            return;
        }

        parent::_initialize();
        $this->repo = AdapterFactory::createRepository();
        $this->renderer = AdapterFactory::createRenderer($this);
    }

    /**
     * Validate that a uid string matches UUID v4 format.
     */
    private function validateUid(string $uid): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uid
        ) === 1;
    }

    // -------------------------------------------------------
    // Page rendering methods (return HTML)
    // -------------------------------------------------------

    /**
     * Dashboard list page.
     * URL: GET /extends/Chat2VizDashboard/index
     */
    public function index()
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;
        $result = $this->repo->list($page, $perPage);
        $this->renderer->renderList($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * Dashboard editor page (new or existing).
     * URL: GET /extends/Chat2VizDashboard/edit?uid={uid}
     */
    public function edit()
    {
        $uid = $_GET['uid'] ?? null;
        $dashboard = $uid !== null ? $this->repo->findByUid((string) $uid) : null;
        $this->renderer->renderEdit($dashboard);
    }

    /**
     * Published dashboard view page (public).
     * URL: GET /extends/Chat2VizDashboard/view?uid={uid}
     */
    public function view()
    {
        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->error('缺少仪表盘ID');
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->error('无效的仪表盘ID');
            return;
        }

        $dashboard = $this->repo->findByUid($uid);
        if (!$dashboard || ($dashboard['status'] ?? '') !== 'published') {
            $this->error('仪表盘不存在或未发布');
            return;
        }

        $schema = $this->repo->getPublishedSchema($uid);
        $this->renderer->renderShow($dashboard, $schema ?? []);
    }

    // -------------------------------------------------------
    // CRUD API methods (return JSON)
    // -------------------------------------------------------

    /**
     * List dashboards with pagination.
     * URL: GET /extends/Chat2VizDashboard/api_list
     */
    public function api_list()
    {
        if (!$this->requireMethod('GET')) return;

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['perPage'] ?? 20)));

        $filters = [];
        if (isset($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (isset($_GET['created_by'])) {
            $filters['created_by'] = $_GET['created_by'];
        }

        try {
            $result = $this->repo->list($page, $perPage, $filters);
            $this->ajaxReturn(['status' => 1, 'data' => $result]);
        } catch (\Exception $e) {
            $this->logError('api_list failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取列表失败']);
        }
    }

    /**
     * Create a new dashboard.
     * URL: POST /extends/Chat2VizDashboard/api_create
     */
    public function api_create()
    {
        if (!$this->requireMethod('POST')) return;

        $input = $this->parseJsonInput();
        if ($input === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
            return;
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = '未命名仪表盘';
        }

        $currentSchema = $input['current_schema'] ?? ['widgets' => [], 'layout' => [], 'variables' => []];

        try {
            $dashboard = $this->repo->create([
                'title' => $title,
                'current_schema' => $currentSchema,
            ]);
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (\Exception $e) {
            $this->logError('api_create failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '创建失败']);
        }
    }

    /**
     * Read a single dashboard by UID.
     * URL: GET /extends/Chat2VizDashboard/api_read?uid={uid}
     */
    public function api_read()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $dashboard = $this->repo->findByUid($uid);
            if ($dashboard === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (\Exception $e) {
            $this->logError('api_read failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取详情失败']);
        }
    }

    /**
     * Update a dashboard's current_schema or title.
     * URL: PUT /extends/Chat2VizDashboard/api_update?uid={uid}
     */
    public function api_update()
    {
        if (!$this->requireMethod('PUT')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        $input = $this->parseJsonInput();
        if ($input === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
            return;
        }

        $updateData = [];
        if (isset($input['title'])) {
            $updateData['title'] = trim((string) $input['title']);
        }
        if (isset($input['current_schema'])) {
            $updateData['current_schema'] = $input['current_schema'];
        }
        if (isset($input['conversation_id'])) {
            $updateData['conversation_id'] = $input['conversation_id'];
        }

        if (empty($updateData)) {
            $this->ajaxReturn(['status' => 0, 'info' => '没有可更新的字段']);
            return;
        }

        try {
            $dashboard = $this->repo->update($uid, $updateData);
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (\Exception $e) {
            $this->logError('api_update failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '更新失败']);
        }
    }

    /**
     * Archive (soft-delete) a dashboard.
     * URL: DELETE /extends/Chat2VizDashboard/api_archive?uid={uid}
     */
    public function api_archive()
    {
        if (!$this->requireMethod('DELETE')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $result = $this->repo->archive($uid);
            if (!$result) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在或已归档']);
                return;
            }
            $this->ajaxReturn(['status' => 1, 'data' => ['uid' => $uid]]);
        } catch (\Exception $e) {
            $this->logError('api_archive failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '归档失败']);
        }
    }

    // -------------------------------------------------------
    // Publish API
    // -------------------------------------------------------

    /**
     * Publish a dashboard (create version snapshot).
     * URL: POST /extends/Chat2VizDashboard/api_publish?uid={uid}
     */
    public function api_publish()
    {
        if (!$this->requireMethod('POST')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $version = $this->repo->publish($uid);
            $this->ajaxReturn(['status' => 1, 'data' => $version]);
        } catch (\Exception $e) {
            $this->logError('api_publish failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '发布失败']);
        }
    }

    /**
     * Get version history for a dashboard.
     * URL: GET /extends/Chat2VizDashboard/api_versions?uid={uid}
     */
    public function api_versions()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($_GET['perPage'] ?? 20)));

        try {
            $result = $this->repo->getVersions($uid, $page, $perPage);
            $this->ajaxReturn(['status' => 1, 'data' => $result]);
        } catch (\Exception $e) {
            $this->logError('api_versions failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取版本历史失败']);
        }
    }

    // -------------------------------------------------------
    // Data query + security (public)
    // -------------------------------------------------------

    /**
     * Public widget data query endpoint.
     *
     * Flow:
     * 1. Look up published schema for the dashboard
     * 2. Extract the widget's SQL from schema
     * 3. Validate SELECT-only via SqlValidator
     * 4. Enforce LIMIT via SqlValidator
     * 5. Set MySQL max_execution_time = 30s
     * 6. Check APCu cache (key = md5 hash, TTL <= 600s)
     * 7. Execute query
     * 8. Store in APCu cache
     * 9. Write audit log
     * 10. Return data
     *
     * URL: GET /extends/Chat2VizDashboard/api_widget_data?uid={uid}&widgetId={id}
     */
    public function api_widget_data()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) ($_GET['uid'] ?? '');
        $widgetId = (string) ($_GET['widgetId'] ?? '');

        if ($uid === '' || $widgetId === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少必要参数']);
            return;
        }
        if (!$this->validateUid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        // 1. Get dashboard and published schema in one pass
        $dashboard = $this->repo->findByUid($uid);
        if ($dashboard === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
            return;
        }

        $schema = $this->repo->getPublishedSchema($uid);
        if ($schema === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘未发布']);
            return;
        }

        // 2. Find the widget and extract its SQL
        $sql = $this->extractWidgetSql($schema, $widgetId);
        if ($sql === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '组件不存在或未配置数据查询']);
            return;
        }

        // 3-4. Validate and enforce SQL rules
        try {
            SqlValidator::validateSelectOnly($sql);
            $sql = SqlValidator::enforceLimit($sql, 1000);
        } catch (\InvalidArgumentException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
            return;
        }

        // 5-6. Cache check — include versionId so cache invalidates on re-publish
        $versionId = $dashboard['published_version_id'] ?? 'none';
        $cacheKey = 'chat2viz:widget:' . md5($uid . ':' . $versionId . ':' . $widgetId . ':' . $sql);

        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if ($cached !== false) {
                $this->ajaxReturn(['status' => 1, 'data' => $cached, 'cache' => 'HIT']);
                return;
            }
        }

        // 7. Execute query with safety constraints
        try {
            $this->setExecutionTimeout();

            $rows = M()->query($sql);
            if (!is_array($rows)) {
                $rows = [];
            }

            // 8. Store in APCu cache (TTL max 600s)
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $rows, 600);
            }

            // 9. Audit log
            $this->auditWidgetQuery($uid, $widgetId, $sql, count($rows));

            // 10. Return data
            $this->ajaxReturn(['status' => 1, 'data' => $rows, 'cache' => 'MISS']);

        } catch (\Exception $e) {
            $this->logError('api_widget_data query failed', sprintf(
                'uid=%s widget=%s err=%s',
                $uid,
                $widgetId,
                $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '数据查询失败']);
        }
    }

    // -------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------

    /**
     * Validate that the incoming request uses the expected HTTP method.
     * Sends a JSON error response and returns false if method does not match.
     */
    private function requireMethod(string $method): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return false;
        }
        return true;
    }

    /**
     * Extract the SQL query from a widget in the published schema.
     *
     * @return string|null The SQL string, or null if widget not found or has no SQL
     */
    private function extractWidgetSql(array $schema, string $widgetId): ?string
    {
        $widgets = $schema['widgets'] ?? [];
        if (!is_array($widgets)) {
            return null;
        }

        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            if (($widget['id'] ?? '') === $widgetId) {
                $sql = $widget['sql'] ?? null;
                if (is_string($sql) && trim($sql) !== '') {
                    return $sql;
                }
                return null;
            }
        }

        return null;
    }

    /**
     * Set MySQL session max_execution_time to 30 seconds.
     * This prevents runaway queries from blocking the server.
     */
    private function setExecutionTimeout(): void
    {
        try {
            M()->execute('SET SESSION max_execution_time = 30000');
        } catch (\Exception $e) {
            // Non-critical: if the MySQL version does not support this, continue
            $this->logError('set_execution_timeout', $e->getMessage());
        }
    }

    /**
     * Write an audit log entry for widget data queries.
     */
    private function auditWidgetQuery(string $uid, string $widgetId, string $sql, int $rowCount): void
    {
        $entry = json_encode([
            'action' => 'widget_data_query',
            'dashboard_uid' => $uid,
            'widget_id' => $widgetId,
            'sql_hash' => md5($sql),
            'row_count' => $rowCount,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '-',
            'time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        \Think\Log::write($entry, 'INFO', 'dashboard_audit');
    }

    /**
     * Log an error with the chat2viz prefix.
     */
    private function logError(string $tag, string $detail): void
    {
        \Think\Log::write(
            sprintf('[chat2viz:dashboard] %s | %s', $tag, $detail),
            \Think\Log::ERR
        );
    }
}
