<?php

namespace Qscmf\Chat2Viz;

use Bootstrap\Provider;
use Bootstrap\RegisterContainer;
use Qscmf\Chat2Viz\Controller\Chat2VizController;

class Chat2VizServiceProvider implements Provider
{
    public function register()
    {
        RegisterContainer::registerController(
            'extends',
            'Chat2Viz',
            Chat2VizController::class
        );

        RegisterContainer::registerSymLink(
            WWW_DIR . '/Public/inertia-chat2viz',
            __DIR__ . '/../asset/inertia/Chat2viz'
        );
    }
}
