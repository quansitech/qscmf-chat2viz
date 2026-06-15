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
        // Support status filter via query param (P1-4: list page filtering)
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
        $this->renderer->renderList($result['items'], $result['total'], $page, $perPage);
    }

    public function edit()
    {
        $uid = I('get.uid');
        $dashboard = $uid !== null ? $this->repo->findByUid((string) $uid) : null;
        $this->renderer->renderEdit($dashboard);
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
            // Strip g2_spec to minimal safe structure (type+encode+title+children)
            // to prevent any LLM-generated exotic fields from crashing G2 v5.
            $schemaRaw = $dashboard['current_schema'] ?? null;
            if (is_string($schemaRaw)) {
                $schema = json_decode($schemaRaw, true);
                if (is_array($schema) && isset($schema['widgets'])) {
                    foreach ($schema['widgets'] as &$w) {
                        if (isset($w['g2_spec']) && is_array($w['g2_spec'])) {
                            $spec = $w['g2_spec'];
                            $type = isset($spec['type']) ? $spec['type'] : 'interval';
                            if ($type === 'view' || $type === 'composite') $type = 'interval';
                            $clean = ['type' => $type];
                            if (isset($spec['title'])) $clean['title'] = $spec['title'];
                            // Keep encode with string-only x/y
                            if (isset($spec['encode']) && is_array($spec['encode'])) {
                                $enc = [];
                                foreach (['x','y','color','size','shape'] as $ch) {
                                    if (isset($spec['encode'][$ch])) {
                                        $val = $spec['encode'][$ch];
                                        if (is_array($val)) $val = isset($val[0]) ? $val[0] : 'count';
                                        $enc[$ch] = (string)$val;
                                    }
                                }
                                if (!empty($enc)) $clean['encode'] = $enc;
                            }
                            $w['g2_spec'] = $clean;
                        }
                    }
                    unset($w);
                    $dashboard['current_schema'] = $schema;
                }
            }
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
}
