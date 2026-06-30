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

        // declarative-frontend-adapter: backfill each widget's non-empty sql into
        // dashboard.current_schema via updateWidgetSql (sourced from
        // DASHBOARD_REPLACE.widgets[wid].sql). Without this, widget cards lack SQL
        // and WidgetDataFetcher won't auto-fetch data on first refresh. The old
        // per-event backfillWidgetSql (sourced from sql_generated) was deleted with
        // the whole-tree refactor; this is its whole-tree successor.
        $this->backfillWidgetSqlFromWidgets($widgets);
    }

    /**
     * Backfill non-empty widget sql into current_schema.
     *
     * Iterates the widgets map from a DASHBOARD_REPLACE frame and, for each widget
     * carrying a non-empty 'sql', persists it via the repository's updateWidgetSql.
     * Skipped entirely when this router has no dashboard uid (e.g. transient mock
     * / no-persist contexts) — matches the old backfillWidgetSql guard.
     *
     * @param array<string, array{widget_id?: string, sql?: string}> $widgets
     */
    private function backfillWidgetSqlFromWidgets(array $widgets): void
    {
        if ($this->dashboardUid === '') {
            return;
        }
        foreach ($widgets as $wid => $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $sql = $widget['sql'] ?? '';
            if (!is_string($sql) || $sql === '') {
                continue;
            }
            // widget_id preferred; fall back to the map key (the LLM uses the
            // widget_id both as the JSON key and the widget_id field).
            $widgetId = isset($widget['widget_id']) && is_string($widget['widget_id']) && $widget['widget_id'] !== ''
                ? $widget['widget_id']
                : (string) $wid;
            // code-review MED: 回填是 best-effort, DB 写失败绝不能中断整条 SSE 流.
            try {
                $this->dashboardRepo->updateWidgetSql($this->dashboardUid, $widgetId, $sql);
            } catch (\Throwable $e) {
                ($this->logger)('backfillWidgetSql failed', sprintf(
                    'uid=%s widget=%s err=%s',
                    $this->dashboardUid,
                    $widgetId,
                    $e->getMessage(),
                ));
            }
        }
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
