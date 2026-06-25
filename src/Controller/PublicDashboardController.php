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

            // fix-public-view-draft-exposure §1: only published dashboards are
            // served on the public route. draft / archived / unknown fall to the
            // SAME error page as "UID does not exist" so an anonymous visitor
            // cannot enumerate which UIDs are drafts vs gone (spec scenario:
            // "draft/archived → error page identical to not-found"). Logic
            // lives in a pure, unit-tested helper (PublicViewStatusGuard) so
            // the status gate is verifiable without the controller stack.
            if (!\Qscmf\Chat2Viz\Service\PublicViewStatusGuard::isViewableOnPublicRoute($dashboard)) {
                $this->error('仪表盘不存在');
                return;
            }

            $schema = $this->repo->getPublishedSchema($uid);
            // Ensure the published view always shows a meaningful title.
            // If the dashboard row still holds an empty/"未命名仪表盘" draft
            // title (e.g. publish dialog didn't submit one), fall back to
            // a generic published title.
            $currentTitle = trim((string)($dashboard['title'] ?? ''));
            if ($currentTitle === '' || $currentTitle === '未命名仪表盘') {
                $dashboard['title'] = '已发布仪表盘';
            }

            // fix-public-view-draft-exposure §1.2: strip the raw SQL string
            // from every widget before the schema reaches the browser. The
            // public surface renders chart results only — exposing SQL leaks
            // the data-intent (table names, column projection, filters) which
            // View Source makes trivially readable. g2_spec is the chart spec,
            // NOT SQL, and is intentionally preserved. Logic lives in a pure,
            // unit-tested helper (PublicSchemaSanitizer) so the security-
            // critical stripping is verifiable without the controller stack.
            $schema = \Qscmf\Chat2Viz\Service\PublicSchemaSanitizer::stripSqlFromWidgets($schema);

            // Normalize g2_spec only when spec validation is OFF (rollback mode).
            // When validation is ON (default), specs are already validated at
            // commit_widget ingress — no heal-on-read needed.
            // (spec-typed-contract: prompt-unification / encode-normalize gate)
            $specValidationEnabled = getenv('CHAT2VIZ_SPEC_VALIDATION_ENABLED');
            $specValidationEnabled = ($specValidationEnabled === false)
                ? true  // default: validation ON
                : strtolower($specValidationEnabled) !== 'false';
            if (!$specValidationEnabled && is_array($schema)) {
                $schema = \Qscmf\Chat2Viz\Security\SpecNormalizer::normalizeSchema($schema);
            }

            // fix-public-view-draft-exposure §1.2 (E2E-found regression):
            // $dashboard['current_schema'] is the raw DRAFT schema string (with
            // widget SQL), persisted on the row and distinct from the
            // published_schema we just sanitized above. The public view must
            // NOT ship it — View Source on __PAGE_DATA__ leaks every widget's
            // SELECT/FROM. The view template only reads `schema` (published),
            // so current_schema is dead weight AND a leak vector. Delegate to
            // the unit-tested sanitizer so the row-level strip is verifiable.
            $dashboard = \Qscmf\Chat2Viz\Service\PublicSchemaSanitizer::stripDashboardRowForPublicView($dashboard);

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
            // Return a proper HTTP 429 so clients, CDNs, and gateways can
            // identify rate limiting and respect Retry-After.
            http_response_code(429);
            header('Retry-After: 60');
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
            // Canonical form: `data` is a BARE rows array (not an envelope).
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
