<?php

namespace Qscmf\Chat2Viz\Controller;

use Qscmf\Chat2Viz\Exception\DashboardException;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;

/**
 * Public dashboard surface.
 *
 * Registered under the `extends` module, which QsController intentionally
 * leaves unauthenticated (it is outside BACKEND_MODULE and not an RBAC node).
 * These two actions are the public-facing endpoints: rendering a published
 * dashboard and serving its (rate-limited) widget data. No login required.
 */
class PublicDashboardController extends BaseDashboardController
{
    public function view()
    {
        $uid = (string) I('get.uid', '');
        if ($uid === '') {
            $this->error('缺少仪表盘ID');
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->error('无效的仪表盘ID');
            return;
        }

        try {
            $dashboard = $this->repo->findByUid($uid);
            if (!$dashboard) {
                $this->error('仪表盘不存在');
                return;
            }

            $dashboardStatus = $dashboard['dashboard_status'] ?? 'draft';

            if ($dashboardStatus === 'published') {
                $schema = $this->repo->getPublishedSchema($uid);
            } else {
                $schemaRaw = $dashboard['current_schema'] ?? null;
                $schema = is_string($schemaRaw) ? json_decode($schemaRaw, true) : $schemaRaw;
                if (!is_array($schema)) {
                    $schema = [];
                }
            }

            $this->renderer->renderShow($dashboard, $schema ?? []);
        } catch (DashboardNotFoundException $e) {
            $this->error('仪表盘不存在');
        } catch (DashboardException $e) {
            $this->logError('view failed', $e->getMessage());
            $this->error('加载仪表盘失败');
        }
    }

    public function api_widget_data()
    {
        if (!$this->requireMethod('GET')) return;

        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->getWidgetDataService()->checkRateLimit($clientIp)) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求过于频繁，请稍后再试']);
            return;
        }

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
            $result = $this->getWidgetDataService()->queryPublicWidgetData($uid, $widgetId, $clientIp);
            $this->ajaxReturn(['status' => 1, 'data' => $result->rows]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_widget_data query failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_widget_data query failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '数据查询失败']);
        }
    }
}
