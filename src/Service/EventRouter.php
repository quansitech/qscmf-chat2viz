<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Sse\StreamAccumulator;
use Qscmf\SseCore\SseEvent;

class EventRouter
{
    private DashboardRepositoryInterface $dashboardRepo;
    private string $dashboardUid;

    /** @var callable */
    private $logger;

    public function __construct(
        DashboardRepositoryInterface $dashboardRepo,
        string $dashboard_uid,
        ?callable $logger = null
    ) {
        $this->dashboardRepo = $dashboardRepo;
        $this->dashboardUid = $dashboard_uid;
        $this->logger = $logger ?? static function (string $tag, string $detail): void {
            \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
        };
    }

    /**
     * Route a transformed SSE event to the appropriate StreamAccumulator method.
     *
     * declarative-frontend-adapter: the whole-tree model collapsed the old
     * DASHBOARD_INIT / WIDGET_DATA_UPDATE / sql_generated / action_call paths.
     * Only answer text, the whole-tree DASHBOARD_REPLACE, mid-stream WIDGET_ERROR,
     * reasoning, and tool_call accumulation remain.
     */
    public function routeEvent(
        StreamAccumulator $accumulator,
        string $conversation_id,
        SseEvent $event
    ): void {
        $data = $event->data;

        switch ($event->type) {
            case 'answer':
                $this->handleAnswer($accumulator, $conversation_id, $data);
                break;

            case 'DASHBOARD_REPLACE':
                $this->handleDashboardReplace($accumulator, $conversation_id, $data);
                break;

            case 'tool_call':
                if (!empty($data) && $accumulator->isRedisAvailable()) {
                    $accumulator->accumulateToolCall($conversation_id, $data);
                }
                break;

            case 'reasoning':
                $text = $data['text'] ?? '';
                if ($text !== '' && $accumulator->isRedisAvailable()) {
                    $accumulator->accumulateReasoning($conversation_id, $text);
                }
                break;

            case 'WIDGET_ERROR':
                $this->handleWidgetError($accumulator, $conversation_id, $data);
                break;

            default:
                // No accumulation for: conversation_id, done, error, tool_start,
                // tool_result, content_block_*, dashboard_notice, comment/heartbeat,
                // and any deprecated event that slipped through the transformer
                // (DASHBOARD_INIT / WIDGET_DATA_UPDATE / dashboard_patch /
                // WIDGET_UPDATE / WIDGET_REMOVE / dashboard_rollback / sql_ready /
                // data_ready / action_call / action_call_result).
                break;
        }
    }

    private function handleAnswer(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $text = $data['text'] ?? '';
        if ($text === '') {
            return;
        }

        $accumulator->accumulateAnswer($conversation_id, $text);
        // declarative-frontend-adapter: the markdown-block SQL extraction
        // fallback was removed. SQL now lives inside DASHBOARD_REPLACE.widgets
        // (Python is the sole authority), so the conversation metadata no
        // longer carries a standalone 'sql' field.
    }

    /**
     * Accumulate DASHBOARD_REPLACE: overlay the whole {layout, widgets} tree
     * into the conversation metadata (contract §2). The frame also carries the
     * LLM answer text (contract §2 `answer`) — accumulate it as content so
     * finalizeStream persists the assistant reply (otherwise the message row
     * is saved with an empty content and the conversation history shows no LLM
     * reply). Verbatim — no per-field reducer, no recomputation of truncated/total.
     */
    private function handleDashboardReplace(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        // The LLM answer rides on the DASHBOARD_REPLACE frame (contract §2).
        // Accumulate it exactly like an `answer` event so it lands in the
        // message content column on finalizeStream.
        $answer = isset($data['answer']) && is_string($data['answer']) ? $data['answer'] : '';
        if ($answer !== '') {
            $accumulator->accumulateAnswer($conversation_id, $answer);
        }

        $layout = $data['layout'] ?? [];
        $widgets = $data['widgets'] ?? [];
        if (!is_array($layout)) {
            $layout = [];
        }
        if (!is_array($widgets)) {
            $widgets = [];
        }
        $accumulator->accumulateDashboardReplace($conversation_id, $layout, $widgets);
    }

    /**
     * Accumulate WIDGET_ERROR: store the error message per widget_id (mid-stream
     * local degradation, contract §3). Merges into the widgets map produced by
     * the most recent DASHBOARD_REPLACE so the persisted tree carries the error.
     */
    private function handleWidgetError(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $widgetId = (string) ($data['widget_id'] ?? $data['id'] ?? '');
        if ($widgetId === '') {
            return;
        }
        $payload = [];
        if (isset($data['error_msg'])) {
            $payload['error_msg'] = $data['error_msg'];
        } elseif (isset($data['error'])) {
            $payload['error_msg'] = $data['error'];
        }
        $payload['status'] = 'error';
        $accumulator->accumulateWidgetData($conversation_id, $widgetId, $payload);
    }
}
