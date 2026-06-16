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
