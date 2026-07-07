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
     * createConversation, findById, findActiveByDashboardUid, findByDashboardUid.
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
     * Create the page renderer.
     *
     * chat2viz 始终走 SmartyRenderer —— 用预编译 bundle + Smarty 模板自挂载
     * (高内聚:bundle 自含 zustand/react-query/react-grid-layout 等前端依赖,
     *  仅 external G2 走 CDN),不依赖宿主 Vite 编译源码。
     *
     * 即使 v14/v15 宿主有 Inertia,也不走 Inertia::render —— 本包前端依赖未
     * 声明在宿主 package.json,走 Inertia 会让宿主 Vite 因依赖缺失而编译失败。
     * v15 是 Inertia+Smarty 混合架构(ANTD_ADMIN_BUILDER_ENABLE 控制各页走向,
     * ListBuilder 已自动桥接到 Inertia),chat2viz 的 edit/view 走 Smarty 与
     * 列表页(ListBuilder)并存是 v15 官方支持的混合模式(Inertia 的 invalid
     * handler 会把 text/html 响应降级为整页跳转,互跳不报错)。
     *
     * 未来若宿主补全依赖或本包改为 npm 包发布,可在此切回 InertiaRenderer。
     *
     * @param object $controller The calling controller instance (needed by SmartyRenderer)
     */
    public static function createRenderer(object $controller): PageRendererInterface
    {
        return new SmartyRenderer($controller);
    }
}
