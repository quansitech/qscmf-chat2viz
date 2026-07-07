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

            // declarative-frontend-adapter (哑前端契约): g2_spec heal-on-read
            // removed. Python validate_and_coerce_spec is the single SSoT —
            // specs are render-ready at write time, so the read path is a pure
            // pass-through. CHAT2VIZ_SPEC_VALIDATION_ENABLED rollback gate and
            // SpecNormalizer deleted (project pre-launch, no legacy data).

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
        // Support both GET (form A — uid-based) and POST (form B — dsl in body).
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->getWidgetDataService()->checkRateLimit($clientIp)) {
            http_response_code(429);
            header('Retry-After: 60');
            $this->ajaxReturn(['status' => 0, 'info' => '请求过于频繁，请稍后再试']);
            return;
        }

        $uid = '';
        $widgetId = '';
        $dsl = null;
        $slicerValues = [];

        if ($method === 'POST') {
            // Form B (contract §3.2): body carries {widget_id, dsl, slicer_values}.
            $input = $this->parseJsonInput();
            if ($input === null) {
                $this->ajaxReturn(['status' => 0, 'info' => '请求格式错误']);
                return;
            }
            $widgetId = (string) ($input['widget_id'] ?? '');
            $dsl = isset($input['dsl']) && is_array($input['dsl']) ? $input['dsl'] : null;
            $slicerValues = isset($input['slicer_values']) && is_array($input['slicer_values'])
                ? $input['slicer_values'] : [];
            // uid is optional in form B (edit-page unsaved linkage / MCP); when
            // present it's carried for audit but dsl takes precedence.
            $uid = (string) ($input['uid'] ?? '');
        } else {
            // Form A (contract §3.2): GET by uid + widgetId.
            $uid = (string) I('get.uid', '');
            $widgetId = (string) I('get.widgetId', '');
            $rawSlicerValues = (string) I('get.slicer_values', '');
            if ($rawSlicerValues !== '') {
                $decoded = json_decode($rawSlicerValues, true);
                $slicerValues = is_array($decoded) ? $decoded : [];
            }
        }

        if ($widgetId === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少必要参数']);
            return;
        }
        // Form A requires uid; form B requires dsl.
        if ($dsl === null && $uid === '') {
            http_response_code(400);
            $this->ajaxReturn(['status' => 0, 'info' => '缺少 dsl 与 uid（需至少提供其一）']);
            return;
        }
        if ($uid !== '' && !self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $result = $this->getWidgetDataService()->queryPublicWidgetData(
                $uid, $widgetId, $clientIp, $dsl, $slicerValues
            );
            // Response mapping (§3.2): rows/columns → data; total/truncated → top-level.
            $data = ['rows' => $result->rows];
            $this->ajaxReturn(['status' => 1, 'data' => $data]);
        } catch (DashboardNotFoundException $e) {
            $this->ajaxReturn(['status' => 0, 'info' => '仪表盘不存在']);
        } catch (DashboardException $e) {
            $this->logError('api_widget_data query failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            // Surface the HTTP status code the service intended (400/503/etc).
            if ($e->getCode() > 0) {
                http_response_code($e->getCode());
            }
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            // Generic message — do NOT leak the specific validation rule that
            // fired (avoids aiding SQL-injection reconnaissance).
            $this->ajaxReturn(['status' => 0, 'info' => '查询不被允许']);
        } catch (\Exception $e) {
            $this->logError('api_widget_data query failed', sprintf(
                'uid=%s widget=%s err=%s', $uid, $widgetId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '数据查询失败']);
        }
    }

    /**
     * GET /api_slicer_options?uid=<uid>&slicerId=<slicerId>
     *
     * Returns the option list for a slicer (contract §3.3). The column-mapping
     * rule (first column → value, second → label) is applied by the frontend.
     */
    public function api_slicer_options()
    {
        if (!$this->requireMethod('GET')) return;

        $uid = (string) I('get.uid', '');
        $slicerId = (string) I('get.slicerId', '');

        if ($uid === '' || $slicerId === '') {
            $this->ajaxReturn(['status' => 0, 'info' => '缺少必要参数']);
            return;
        }
        if (!self::validateUuid($uid)) {
            $this->ajaxReturn(['status' => 0, 'info' => '无效的仪表盘ID']);
            return;
        }

        try {
            $rows = $this->getWidgetDataService()->querySlicerOptions($slicerId, null, $uid);
            $this->ajaxReturn(['status' => 1, 'data' => $rows]);
        } catch (DashboardException $e) {
            if ($e->getCode() > 0) {
                http_response_code($e->getCode());
            }
            $this->ajaxReturn(['status' => 0, 'info' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->logError('api_slicer_options failed', sprintf(
                'uid=%s slicer=%s err=%s', $uid, $slicerId, $e->getMessage()
            ));
            $this->ajaxReturn(['status' => 0, 'info' => '选项加载失败']);
        }
    }
}
