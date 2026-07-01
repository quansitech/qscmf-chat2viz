<?php

namespace Qscmf\Chat2Viz\Controller;

use Qscmf\Chat2Viz\Adapter\AdapterFactory;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Renderer\PageRendererInterface;
use Qscmf\Chat2Viz\Service\DashboardService;
use Qscmf\Chat2Viz\Service\WidgetDataService;
use Qscmf\Chat2Viz\Traits\JsonInputTrait;
use Qscmf\Chat2Viz\Traits\UuidTrait;
use Qscmf\Core\QsController;

/**
 * Shared base for the dashboard controllers.
 *
 * Auth is delegated entirely to the framework: QsController::_initialize()
 * enforces login only inside BACKEND_MODULE, so the SAME _initialize yields
 * different behavior purely from the module the controller is registered under:
 *   - admin module   -> framework auth (isAdminLogin redirect + RBAC)
 *   - extends module -> no auth (public surface)
 *
 * Concrete controllers split the action set by sensitivity:
 *   - DashboardController       (admin)   : CRUD / editor
 *   - PublicDashboardController (extends) : published view + widget data
 */
abstract class BaseDashboardController extends QsController
{
    use JsonInputTrait;
    use UuidTrait;

    protected DashboardRepositoryInterface $repo;
    protected PageRendererInterface $renderer;

    private ?DashboardService $dashboardService = null;
    private ?WidgetDataService $widgetDataService = null;

    protected function _initialize()
    {
        parent::_initialize();
        $this->initAdapters();
    }

    protected function initAdapters(): void
    {
        $this->repo = AdapterFactory::createRepository();
        $this->renderer = AdapterFactory::createRenderer($this);
    }

    protected function getDashboardService(): DashboardService
    {
        if ($this->dashboardService === null) {
            $this->dashboardService = new DashboardService($this->repo);
        }
        return $this->dashboardService;
    }

    protected function getWidgetDataService(): WidgetDataService
    {
        if ($this->widgetDataService === null) {
            $this->widgetDataService = new WidgetDataService(
                $this->repo,
                fn(string $tag, string $detail) => $this->logError($tag, $detail)
            );
        }
        return $this->widgetDataService;
    }

    protected function requireMethod(string $method): bool
    {
        if ($_SERVER['REQUEST_METHOD'] !== $method) {
            $this->ajaxReturn(['status' => 0, 'info' => '请求方法不允许']);
            return false;
        }
        return true;
    }

    protected function getCurrentUserId(): ?int
    {
        $authId = session(C('USER_AUTH_KEY'));
        return is_numeric($authId) ? (int) $authId : null;
    }

    /**
     * Check ownership and send error response if mismatch.
     * Returns true if owner, false if rejected (response already sent).
     */
    protected function checkOwnershipAndReject(array $dashboard): bool
    {
        if (!$this->getDashboardService()->checkOwnership($dashboard, $this->getCurrentUserId())) {
            $this->ajaxReturn(['status' => 0, 'info' => '无权操作']);
            return false;
        }
        return true;
    }

    protected function logError(string $tag, string $detail): void
    {
        \Think\Log::write(
            sprintf('[chat2viz:dashboard] %s | %s', $tag, $detail),
            \Think\Log::ERR
        );
    }
}
