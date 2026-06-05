<?php

namespace Qscmf\Chat2Viz;

use Bootstrap\Provider;
use Bootstrap\LaravelProvider;
use Bootstrap\RegisterContainer;
use Qscmf\Chat2Viz\Controller\Chat2VizController;

class Chat2VizServiceProvider implements Provider, LaravelProvider
{
    public function register()
    {
        RegisterContainer::registerController(
            'extends',
            'Chat2Viz',
            Chat2VizController::class
        );

        RegisterContainer::registerSymLink(
            WWW_DIR . '/Public/chat2viz',
            __DIR__ . '/../asset/chat2viz'
        );

        RegisterContainer::registerSymLink(
            APP_PATH . 'Extends/View/default/Chat2Viz',
            __DIR__ . '/../view/default/Chat2Viz'
        );
    }

    public function registerLara()
    {
        \Illuminate\Console\Application::starting(function ($artisan) {
            $artisan->resolveCommands([
                \Qscmf\Chat2Viz\Command\SeedSakilaCommand::class,
                \Qscmf\Chat2Viz\Command\UnseedSakilaCommand::class,
            ]);
        });
    }
}
