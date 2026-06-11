<?php

namespace Qscmf\Chat2Viz\Adapter;

use Qscmf\Chat2Viz\Repository\ConversationRepositoryInterface;
use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Repository\MessageRepositoryInterface;
use Qscmf\Chat2Viz\Repository\ThinkModelConversationRepository;
use Qscmf\Chat2Viz\Repository\ThinkModelDashboardRepository;
use Qscmf\Chat2Viz\Repository\ThinkModelMessageRepository;
use Qscmf\Chat2Viz\Repository\EloquentConversationRepository;
use Qscmf\Chat2Viz\Repository\EloquentDashboardRepository;
use Qscmf\Chat2Viz\Repository\EloquentMessageRepository;
use Qscmf\Chat2Viz\Renderer\PageRendererInterface;
use Qscmf\Chat2Viz\Renderer\SmartyRenderer;
use Qscmf\Chat2Viz\Renderer\InertiaRenderer;

class AdapterFactory
{
    /**
     * Create the appropriate repository based on runtime ORM availability.
     *
     * v13/v14: Think\Model exists -> ThinkModelDashboardRepository
     * v15:     Think\Model absent -> EloquentDashboardRepository
     */
    public static function createRepository(): DashboardRepositoryInterface
    {
        return class_exists('Think\Model')
            ? new ThinkModelDashboardRepository()
            : new EloquentDashboardRepository();
    }

    /**
     * Create the appropriate conversation repository based on runtime ORM availability.
     *
     * Manages conversation records (qs_chat2viz_conversations):
     * createConversation, findById, findActiveByDashboardUid, findByDashboardUid, archive.
     *
     * v13/v14: Think\Model exists -> ThinkModelConversationRepository
     * v15:     Think\Model absent -> EloquentConversationRepository
     */
    public static function createConversationRepository(): ConversationRepositoryInterface
    {
        return class_exists('Think\Model')
            ? new ThinkModelConversationRepository()
            : new EloquentConversationRepository();
    }

    /**
     * Create the appropriate message repository based on runtime ORM availability.
     *
     * Manages message records (qs_chat2viz_conversation_messages):
     * createMessage, getMessages, getRecentConversationIds,
     * createPreallocatedAssistantMessage, updateMessageWithMetadata, findConversationHistory.
     *
     * v13/v14: Think\Model exists -> ThinkModelMessageRepository
     * v15:     Think\Model absent -> EloquentMessageRepository
     */
    public static function createMessageRepository(): MessageRepositoryInterface
    {
        return class_exists('Think\Model')
            ? new ThinkModelMessageRepository()
            : new EloquentMessageRepository();
    }

    /**
     * Create the appropriate renderer based on runtime template engine availability.
     *
     * v13:     No Inertia -> SmartyRenderer
     * v14/v15: Inertia exists -> InertiaRenderer
     *
     * @param object $controller The calling controller instance (needed by SmartyRenderer)
     */
    public static function createRenderer(object $controller): PageRendererInterface
    {
        return class_exists('Qscmf\Lib\Inertia\Inertia')
            ? new InertiaRenderer()
            : new SmartyRenderer($controller);
    }
}
