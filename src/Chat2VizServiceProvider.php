<?php

namespace Qscmf\Chat2Viz;

use Bootstrap\Provider;
use Bootstrap\LaravelProvider;
use Bootstrap\RegisterContainer;
use Qscmf\Chat2Viz\Command\AskCommand;
use Qscmf\Chat2Viz\Controller\Chat2VizController;
use Qscmf\Chat2Viz\Controller\DashboardController;
use Qscmf\Chat2Viz\Controller\PublicDashboardController;

class Chat2VizServiceProvider implements Provider, LaravelProvider
{
    public function register()
    {
        // === Existing: single-chart conversation ===
        RegisterContainer::registerController(
            'extends',
            'Chat2Viz',
            Chat2VizController::class
        );

        $this->safeRegisterSymLink(
            WWW_DIR . '/Public/chat2viz',
            __DIR__ . '/../asset/chat2viz'
        );

        $this->safeRegisterSymLink(
            APP_PATH . 'Extends/View/default/Chat2Viz',
            __DIR__ . '/../view/default/Chat2Viz'
        );

        // === Dashboard: admin CRUD (framework-authed) + public view (extends) ===
        // DashboardController runs under the admin module so QsController enforces
        // login (isAdminLogin redirect + RBAC); PublicDashboardController keeps the
        // published view + widget-data endpoints public under extends. Both reuse the
        // Chat2VizDashboard URL name, distinguished by module:
        //   /admin/Chat2VizDashboard   -> DashboardController       (login required)
        //   /extends/Chat2VizDashboard -> PublicDashboardController (public)
        RegisterContainer::registerController(
            'admin',
            'Chat2VizDashboard',
            DashboardController::class
        );

        RegisterContainer::registerController(
            'extends',
            'Chat2VizDashboard',
            PublicDashboardController::class
        );

        // v13 Smarty templates. edit.html / index.html already extend the Admin
        // layout, so they render via the admin module view dir; the public view
        // page renders via the extends module view dir.
        $this->safeRegisterSymLink(
            APP_PATH . 'Admin/View/default/Chat2VizDashboard',
            __DIR__ . '/../view/default/Chat2VizDashboard'
        );

        $this->safeRegisterSymLink(
            APP_PATH . 'Extends/View/default/Chat2VizDashboard',
            __DIR__ . '/../view/default/Chat2VizDashboard'
        );

        // v13 compiled bundle
        $this->safeRegisterSymLink(
            WWW_DIR . '/Public/chat2viz-dashboard',
            __DIR__ . '/../asset/chat2viz-dashboard'
        );

        // v14/v15: chat2viz 不再软链源码到宿主 Pages/ 目录。
        // 本包前端依赖(zustand/react-query/react-grid-layout 等)未声明在宿主
        // package.json,把源码交给宿主 Vite 编译会因依赖缺失而失败。改为统一走
        // SmartyRenderer + 预编译 bundle(上面的 Public/chat2viz-dashboard 软链
        // 已提供产物),v13/v15 共用同一路径。详见 AdapterFactory::createRenderer。
        // 未来若宿主补全依赖或本包 npm 化,可恢复此软链并切回 InertiaRenderer。
    }

    public function registerLara()
    {
        \Illuminate\Console\Application::starting(function ($artisan) {
            $artisan->resolveCommands([
                \Qscmf\Chat2Viz\Command\SeedSakilaCommand::class,
                \Qscmf\Chat2Viz\Command\UnseedSakilaCommand::class,
                AskCommand::class,
            ]);
        });

        // Dashboard database migrations
        RegisterContainer::registerMigration(__DIR__ . '/../database/migrations');
    }

    /**
     * Register a symlink with error logging on failure.
     *
     * Gracefully logs failures instead of crashing the entire application
     * when a symlink cannot be created (e.g. permissions, path issues).
     */
    private function safeRegisterSymLink(string $linkPath, string $targetPath): void
    {
        try {
            RegisterContainer::registerSymLink($linkPath, $targetPath);
        } catch (\Throwable $e) {
            $this->logWarning(sprintf(
                '[chat2viz:sp] symlink failed: link=%s target=%s error=%s',
                $linkPath,
                $targetPath,
                $e->getMessage()
            ));
        }
    }

    private function logWarning(string $message): void
    {
        if (class_exists(\Think\Log::class)) {
            \Think\Log::write($message, \Think\Log::WARN);
        } elseif (function_exists('logger')) {
            logger()->warning($message);
        }
    }
}
