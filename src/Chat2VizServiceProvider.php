<?php

namespace Qscmf\Chat2Viz;

use Bootstrap\Provider;
use Bootstrap\LaravelProvider;
use Bootstrap\RegisterContainer;
use Qscmf\Chat2Viz\Command\AskCommand;
use Qscmf\Chat2Viz\Controller\Chat2VizController;
use Qscmf\Chat2Viz\Controller\DashboardController;

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

        // === New: Dashboard ===
        RegisterContainer::registerController(
            'extends',
            'Chat2VizDashboard',
            DashboardController::class
        );

        // v13 Smarty templates
        $this->safeRegisterSymLink(
            APP_PATH . 'Extends/View/default/Chat2VizDashboard',
            __DIR__ . '/../view/default/Chat2VizDashboard'
        );

        // v13 compiled bundle
        $this->safeRegisterSymLink(
            WWW_DIR . '/Public/chat2viz-dashboard',
            __DIR__ . '/../asset/chat2viz-dashboard'
        );

        // v14/v15 Inertia source (host Vite compiles) - skip if path unavailable (v13)
        $inertiaPath = false;
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            try {
                $candidate = realpath(app_path('../../resources/js/backend/Pages/Chat2viz'));
                if ($candidate !== false) {
                    $inertiaPath = $candidate;
                }
            } catch (\Throwable $e) {
                // Not in a Laravel context — skip silently
            }
        }
        if ($inertiaPath !== false) {
            $this->safeRegisterSymLink(
                $inertiaPath,
                __DIR__ . '/../asset/inertia/Chat2viz'
            );
        }
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
