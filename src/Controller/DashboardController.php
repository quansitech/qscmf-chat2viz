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
        $result = $this->repo->list($page, $perPage);
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

            $version = $this->repo->publish($uid);
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
