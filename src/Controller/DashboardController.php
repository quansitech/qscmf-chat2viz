<?php

namespace Qscmf\Chat2Viz\Controller;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;

/**
 * Admin dashboard controller.
 *
 * Registered under the `admin` module (BACKEND_MODULE), so QsController's
 * framework auth applies: unauthenticated requests are redirected to the
 * login gateway, and getCurrentUserId() is never null inside these actions.
 * No hand-rolled auth code -- ownership relies on the logged-in admin.
 */
class DashboardController extends BaseDashboardController
{
    // -------------------------------------------------------
    // Page rendering methods (return HTML)
    // -------------------------------------------------------

    public function index()
    {
        $page = max(1, (int) I('get.page', 1));
        $perPage = 20;
        $filters = [];
        $dashboardStatus = I('get.dashboard_status');
        if ($dashboardStatus !== null && $dashboardStatus !== '') {
            $filters['dashboard_status'] = $dashboardStatus;
        }
        $titleSearch = I('get.q');
        if ($titleSearch !== null && $titleSearch !== '') {
            $filters['title_like'] = $titleSearch;
        }
        $result = $this->repo->list($page, $perPage, $filters);

        $statusOptions = [
            '' => '全部',
            'draft' => '草稿',
            'published' => '已发布',
            'archived' => '已归档',
        ];

        $builder = new \Qscmf\Builder\ListBuilder();
        $builder->setMetaTitle('仪表盘')
            ->addTopButton('addnew', [
                'title' => '新增仪表盘',
                'href'  => U(MODULE_NAME . '/' . CONTROLLER_NAME . '/add'),
            ])
            ->addSearchItem('dashboard_status', 'select', '发布状态', $statusOptions)
            ->addSearchItem('q', 'text', '标题')
            ->addTableColumn('title', '标题')
            ->addTableColumn('dashboard_status', '发布状态', 'fun', self::class . '::statusLabel(__data_id__)')
            ->addTableColumn('created_at', '创建时间', 'datetime')
            ->addTableColumn('right_button', '操作', 'btn')
            ->setTableDataList($result['items'])
            ->setTableDataListKey('uid')
            ->setTableDataPage($this->buildPagination((int) $result['total'], $perPage))
            ->addRightButton('edit')
            ->addRightButton('self', [
                'title' => '查看',
                'class' => 'qs-list-right-btn info',
                // fix-draft-view-restore: route through the admin preview action
                // (uses current_schema) instead of the public view (which rejects
                // non-published dashboards). All statuses preview here.
                // NOTE: literal href (no U()) — U() appends .html which collides
                // with ThinkPHP ACTION parsing (preview.html/uid/... → "非法操作").
                // The extends view button used a literal href for the same reason.
                'href'  => '/admin/Chat2VizDashboard/preview/uid/__data_id__',
            ])
            // 缺陷1: 已发布的仪表盘提供一个"复制链接"操作, 一键复制公开访问地址
            // (PUBLIC_BASE/view/uid/<uid>). ListBuilder 的 {key}/{condition}/{value}
            // 元属性让该按钮仅在 dashboard_status==='published' 时渲染 (见
            // TGenButton::parseButtonList). 按钮不跳转(href=javascript:void), 改由
            // data-url + 全局 click handler (chat2viz_copy_link 在 index.html 注入)
            // 写入剪贴板 —— handler 内置 execCommand fallback, HTTP(非安全上下文)
            // 下 navigator.clipboard 为 undefined 也能复制成功.
            ->addRightButton('self', [
                'title'      => '复制链接',
                'class'      => 'qs-list-right-btn chat2viz-copy-link-btn',
                // href 必填, 但本按钮不导航; 由全局 click handler 接管.
                'href'       => 'javascript:void(0)',
                // 注意: data-url 用 __uid__ 而非 __data_id__. ListBuilder 的
                // compileRightButton 只对 href/data-id 做显式 __data_id__ 替换
                // (TGenButton:108-119); 其他属性靠 parseData 按 __field__ 替换
                // (TGenButton:131), 字段名需匹配行的实际 key(setTableDataListKey
                // 设的是 'uid'), 用 __data_id__ 会得到未定义键 → 空串. __uid__ 正确.
                'data-url'   => '/extends/Chat2VizDashboard/view/uid/__uid__',
                '{key}'      => 'dashboard_status',
                '{condition}' => 'eq',
                '{value}'    => 'published',
            ])
            ->addRightButton('delete')
            ->addContentBottom($this->renderCopyLinkScript())
            ->build();
    }

    /**
     * 缺陷1: 注入"复制链接"按钮的全局 click handler.
     *
     * ListBuilder 服务端渲染, 没有 React; 该脚本挂在 .chat2viz-copy-link-btn 上,
     * 读 data-url 拼成完整公开地址并写入剪贴板. 内置 execCommand('copy') fallback,
     * 在非安全上下文(内网 HTTP)下 navigator.clipboard 为 undefined 时也能成功 ——
     * 与 React PublishDialog 的复制语义保持一致.
     */
    private function renderCopyLinkScript(): string
    {
        return <<<'HTML'
<script>
(function () {
  function fallbackCopy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      return true;
    } catch (e) {
      return false;
    }
  }
  function copyLink(url) {
    var ok = false;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        window.alert ? alert('公开链接已复制：\n' + url) : '';
      }).catch(function () {
        if (!fallbackCopy(url)) { alert('复制失败，请手动复制：\n' + url); }
        else { alert('公开链接已复制：\n' + url); }
      });
      return;
    }
    ok = fallbackCopy(url);
    alert(ok ? '公开链接已复制：\n' + url : '复制失败，请手动复制：\n' + url);
  }
  document.addEventListener('click', function (e) {
    var t = e.target instanceof Element ? e.target.closest('.chat2viz-copy-link-btn') : null;
    if (!t) return;
    e.preventDefault();
    var raw = t.getAttribute('data-url') || '';
    if (!raw) return;
    copyLink(window.location.origin + raw);
  });
})();
</script>
HTML;
    }

    /**
     * Render the dashboard_status enum as a Bootstrap label for ListBuilder's
     * "fun" column type. Returns raw HTML (ListBuilder does not escape fun output).
     */
    public static function statusLabel(string $value): string
    {
        static $map = [
            'draft'     => '<span class="label label-default">草稿</span>',
            'published' => '<span class="label label-success">已发布</span>',
            'archived'  => '<span class="label label-warning">已归档</span>',
        ];
        return $map[$value] ?? htmlspecialchars($value);
    }

    /**
     * Build the framework pagination data for ListBuilder. GyPage::show() returns
     * an ARRAY; QsPage reads current page from $_GET['page'] (VAR_PAGE config).
     */
    private function buildPagination(int $total, int $perPage): array
    {
        $pageObj = new \Gy_Library\GyPage($total, $perPage);
        return $pageObj->show();
    }

    /**
     * New dashboard route. Renders the dashboard editor in "create" mode
     * (dashboard=null) so the frontend starts a fresh, unsaved dashboard. The
     * React dashboard-edit component is shared with edit() — only the route
     * semantics differ (add = create, edit = modify an existing record).
     */
    public function add()
    {
        $this->renderer->renderEdit(null);
    }

    /**
     * Read-only preview of a dashboard (any status) using its current_schema.
     *
     * fix-draft-view-restore: the public view route (/extends/Chat2VizDashboard/view)
     * rejects non-published dashboards (fix-public-view-draft-exposure, a7619bb),
     * so the admin list's 查看 button was repointed here. This action serves
     * the SAME view template (view.html / dashboard-view.js) but:
     *   - renders current_schema (the live draft), not getPublishedSchema
     *   - runs under admin auth (login + RBAC, any logged-in administrator)
     *   - does NOT run PublicSchemaSanitizer (admins may see widget SQL)
     *   - does NOT mask the draft title (admins see the real working title)
     *
     * Auth is positional (controller mounted under the admin module); no
     * ownership check, mirroring edit()'s posture.
     */
    public function preview()
    {
        $uid = (string) I('get.uid', '');
        $dashboard = $uid !== '' ? $this->repo->findByUid($uid) : null;
        if ($dashboard === null) {
            $this->error('仪表盘不存在');
            return;
        }

        // findByUid returns current_schema as a JSON string; renderShow expects
        // a decoded array. Decode logic is centralized in PreviewSchemaResolver
        // (pure, unit-tested) — see tests/Service/PreviewSchemaResolverTest.
        $schema = \Qscmf\Chat2Viz\Service\PreviewSchemaResolver::resolveFromCurrentSchema($dashboard);

        // Signal the shared DashboardView React app (which serves BOTH the
        // public view and this admin preview) that this is an admin-authorized
        // preview, so it renders draft/archived schemas instead of showing the
        // "该仪表盘暂未发布" empty state (DashboardView.tsx §2.2 gate). The
        // public view path never sets this, so its draft gate stays intact.
        $dashboard['__is_preview'] = true;

        $this->renderer->renderShow($dashboard, $schema);
    }

    /**
     * Edit an EXISTING dashboard. Requires a valid uid pointing at a record
     * that actually exists; otherwise redirect to the add route so the URL
     * always reflects the real intent (create vs. modify).
     */
    public function edit()
    {
        $uid = (string) I('get.uid', '');
        $dashboard = $uid !== '' ? $this->repo->findByUid($uid) : null;
        if ($dashboard === null) {
            redirect(U(MODULE_NAME . '/' . CONTROLLER_NAME . '/add'));
            return;
        }
        $this->renderer->renderEdit($dashboard);
    }

    /**
     * ListBuilder delete entry point: AJAX GET delete?ids=<uid> with confirm.
     * Supports single uid (right button) or comma batch (top-button bulk).
     */
    public function delete()
    {
        $ids = I('get.ids', '');
        if ($ids === '') {
            $this->error('请选择要删除的数据');
            return;
        }

        $uids = array_filter(array_map('strval', explode(',', (string) $ids)));
        $userId = $this->getCurrentUserId();
        $service = $this->getDashboardService();

        $failed = [];
        foreach ($uids as $uid) {
            if (!self::validateUuid($uid)) {
                $failed[] = $uid;
                continue;
            }
            try {
                $existing = $this->repo->findByUid($uid);
                if ($existing === null) {
                    continue;
                }
                if (!$service->checkOwnership($existing, $userId)) {
                    $failed[] = $uid;
                    continue;
                }
                $service->delete($uid, $userId);
            } catch (\Exception $e) {
                $this->logError('delete failed', sprintf('uid=%s err=%s', $uid, $e->getMessage()));
                $failed[] = $uid;
            }
        }

        if (!empty($failed)) {
            $this->error('部分数据删除失败或无权操作：' . implode(',', $failed));
            return;
        }

        $this->success('删除成功', U(MODULE_NAME . '/' . CONTROLLER_NAME . '/index'));
    }
    // -------------------------------------------------------
    // CRUD API methods (return JSON)
    // -------------------------------------------------------

    public function api_list()
    {
        if (!$this->requireMethod('GET')) return;

        $page = max(1, (int) I('get.page', 1));
        $perPage = min(100, max(1, (int) I('get.perPage', 20)));

        $filters = [];
        $status = I('get.status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }
        // Business status filter (draft/published/archived) — distinct from
        // the technical ``status`` tinyint column.
        $dashboardStatus = I('get.dashboard_status');
        if ($dashboardStatus !== null && $dashboardStatus !== '') {
            $filters['dashboard_status'] = $dashboardStatus;
        }
        $createdBy = I('get.created_by');
        if ($createdBy !== null && $createdBy !== '') {
            $filters['created_by'] = $createdBy;
        }

        try {
            $result = $this->repo->list($page, $perPage, $filters);
            $this->ajaxReturn(['status' => 1, 'data' => $result]);
        } catch (\Exception $e) {
            $this->logError('api_list failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取列表失败']);
        }
    }

    public function api_create()
    {
        if (!$this->requireMethod('POST')) return;

        $input = $this->parseJsonInput();
        if ($input === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
            return;
        }

        try {
            $userId = $this->getCurrentUserId();
            $dashboard = $this->getDashboardService()->create($input, $userId);
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (DashboardException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_create failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '创建失败']);
        }
    }

    public function api_read()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $dashboard = $this->repo->findByUid($uid);
            if ($dashboard === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            // fix-backend 2.11 / fix-public-view 1.3: api_read is the full
            // dashboard row (including current_schema with SQL) and lives in the
            // admin module — enforce ownership so a logged-in user cannot read
            // another user's draft or its SQL via this endpoint. Mirrors the
            // existing checkOwnershipAndReject at api_update / api_archive /
            // api_publish / api_draft_widget_data. Same '无权操作' message keeps
            // the rejection indistinguishable across endpoints.
            if (!$this->checkOwnershipAndReject($dashboard)) return;

            // g2_spec is returned as stored — no stripping.
            // Specs are validated at commit_widget ingress (spec-typed-contract),
            // so the DB only contains valid specs. The old strip-to-type+encode+title
            // logic caused silent data loss (dropping transform/scale/etc) and is removed.
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_read failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取详情失败']);
        } catch (\Exception $e) {
            $this->logError('api_read failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取详情失败']);
        }
    }

    public function api_update()
    {
        if (!$this->requireMethod('PUT')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        $input = $this->parseJsonInput();
        if ($input === null) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            if (!$this->checkOwnershipAndReject($existing)) return;

            // Validate widget g2_spec structure via Python validate-spec endpoint.
            // (spec-typed-contract: dashboard-schema Requirement — all spec-write
            // paths validate). 2s timeout, fail-open on unavailable (blocking all
            // manual edits when Python is down is worse than the rare dirty-edit risk).
            if (isset($input['current_schema']['widgets'])) {
                $pythonHost = getenv('CHAT2VIZ_PYTHON_HOST') ?: 'http://localhost:7860';
                $apiKey = getenv('CHAT2VIZ_API_KEY') ?: 'local-test-key-not-secure';
                foreach ($input['current_schema']['widgets'] as $w) {
                    if (isset($w['g2_spec']) && is_array($w['g2_spec'])) {
                        $validated = $this->validateSpecViaPython(
                            $pythonHost, $apiKey, $w['g2_spec']
                        );
                        if ($validated === false) {
                            // Validation explicitly failed (not a timeout) → reject
                            $this->ajaxReturn(['status' => 0, 'info' => '图表规格校验失败，请检查 g2_spec 结构']);
                            return;
                        }
                        // $validated === null → timeout/unavailable → fail-open (continue)
                    }
                }
            }

            $dashboard = $this->getDashboardService()->update($uid, $input, $this->getCurrentUserId());
            $this->ajaxReturn(['status' => 1, 'data' => $dashboard]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_update failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_update failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '更新失败']);
        }
    }

    public function api_archive()
    {
        if (!$this->requireMethod('DELETE')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            if (!$this->checkOwnershipAndReject($existing)) return;

            $this->getDashboardService()->archive($uid, $this->getCurrentUserId());
            $this->ajaxReturn(['status' => 1, 'data' => ['uid' => $uid]]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_archive failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '归档失败']);
        } catch (\Exception $e) {
            $this->logError('api_archive failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '归档失败']);
        }
    }

    public function api_delete()
    {
        if (!$this->requireMethod('DELETE')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            if (!$this->checkOwnershipAndReject($existing)) return;

            $this->getDashboardService()->delete($uid, $this->getCurrentUserId());
            $this->ajaxReturn(['status' => 1, 'data' => ['uid' => $uid]]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_delete failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_delete failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '删除失败']);
        }
    }
    // -------------------------------------------------------
    // Publish API
    // -------------------------------------------------------

    public function api_publish()
    {
        if (!$this->requireMethod('POST')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            if (!$this->checkOwnershipAndReject($existing)) return;

            // Publish dialog (§5) submits the title in the JSON body. Persist it
            // alongside the version snapshot so the published view reflects the
            // user-supplied title. Empty/missing title leaves the stored value.
            $title = '';
            $jsonBody = $this->parseJsonInput();
            if (is_array($jsonBody) && isset($jsonBody['title']) && is_string($jsonBody['title'])) {
                $title = trim($jsonBody['title']);
            }

            $version = $this->repo->publish($uid, null, $title);
            $this->ajaxReturn(['status' => 1, 'data' => $version]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_publish failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '发布失败']);
        } catch (\Exception $e) {
            $this->logError('api_publish failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '发布失败']);
        }
    }

    public function api_versions()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少仪表盘ID']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        $page = max(1, (int) I('get.page', 1));
        $perPage = min(50, max(1, (int) I('get.perPage', 20)));

        try {
            $result = $this->repo->getVersions($uid, $page, $perPage);
            $this->ajaxReturn(['status' => 1, 'data' => $result]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_versions failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取版本历史失败']);
        } catch (\Exception $e) {
            $this->logError('api_versions failed', $e->getMessage());
            $this->ajaxReturn(['status' => 0, 'info' => '获取版本历史失败']);
        }
    }

    public function api_draft_widget_data()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) I('get.uid', '');
        $widgetId = (string) I('get.widgetId', '');

        if ($uid === '' || $widgetId === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少必要参数']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            if (!$this->checkOwnershipAndReject($existing)) return;

            $result = $this->getWidgetDataService()->queryDraftWidgetData($uid, $widgetId);
            // Canonical form: `data` is a BARE rows array (not an envelope).
            // The view page and edit-reload both consume this shape directly.
            $this->ajaxReturn(['status' => 1, 'data' => $result->rows]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_draft_widget_data failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_draft_widget_data failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '数据查询失败']);
        }
    }

    /**
     * Preview widget data — like api_draft_widget_data but WITHOUT the
     * ownership check (fix-draft-view-restore: any logged-in administrator may
     * preview). Used by the admin preview page (DashboardView.tsx under
     * isAdminPreview) to fetch chart data from current_schema. Auth is the
     * admin module's login + RBAC gate (positional, same as edit/preview).
     *
     * SQL is still validated (SqlValidator::validateSelectOnly + enforceLimit)
     * inside WidgetDataService::queryDraftWidgetData.
     */
    public function api_preview_widget_data()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) I('get.uid', '');
        $widgetId = (string) I('get.widgetId', '');

        if ($uid === '' || $widgetId === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少必要参数']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $existing = $this->repo->findByUid($uid);
            if ($existing === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
                return;
            }
            // NO ownership check — any logged-in admin can preview (the admin
            // module RBAC gate already restricted access to this controller).
            // Compare api_draft_widget_data above which DOES checkOwnership.

            $result = $this->getWidgetDataService()->queryDraftWidgetData($uid, $widgetId);
            $this->ajaxReturn(['status' => 1, 'data' => $result->rows]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_preview_widget_data failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_preview_widget_data failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '数据查询失败']);
        }
    }

    /**
     * Validate a g2_spec via the Python /internal/validate-spec endpoint.
     *
     * Returns:
     *   true  — spec is valid
     *   false — spec is invalid (caller should reject)
     *   null  — endpoint unavailable/timeout (caller should fail-open)
     *
     * (spec-typed-contract: dashboard-schema — all spec-write paths validate)
     */
    private function validateSpecViaPython(string $host, string $apiKey, array $spec): ?bool
    {
        $url = rtrim($host, '/') . '/api/v1/internal/validate-spec';
        $payload = json_encode(['spec' => $spec]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2, // 2s hard timeout — fail-open if Python is slow/down
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        // Timeout or connection error → fail-open (return null)
        if ($errno !== 0 || $httpCode !== 200) {
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['valid'])) {
            return null; // unexpected response → fail-open
        }

        return $data['valid'] === true;
    }
}
