<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Repository\DashboardRepositoryInterface;
use Qscmf\Chat2Viz\Exception\DashboardNotFoundException;
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
     */
    public function routeEvent(
        StreamAccumulator $accumulator,
        string $conversation_id,
        SseEvent $event
    ): void {
        if (!$accumulator->isRedisAvailable()) {
            return;
        }

        $data = $event->data;

        switch ($event->type) {
            case 'answer':
                $this->handleAnswer($accumulator, $conversation_id, $data);
                break;

            case 'sql_generated':
                $this->handleSqlGenerated($accumulator, $conversation_id, $data);
                break;

            case 'action_call':
                $accumulator->accumulateActionCall($conversation_id, [
                    'action_type' => $data['action_type'] ?? '',
                    'params'      => $data['params'] ?? [],
                ]);
                break;

            case 'tool_call':
                if (!empty($data)) {
                    $accumulator->accumulateToolCall($conversation_id, $data);
                }
                break;

            case 'reasoning':
                $text = $data['text'] ?? '';
                if ($text !== '') {
                    $accumulator->accumulateReasoning($conversation_id, $text);
                }
                break;

            case 'DASHBOARD_INIT':
                $this->handleDashboardInit($accumulator, $conversation_id, $data);
                break;

            case 'WIDGET_DATA_UPDATE':
                $this->handleWidgetDataUpdate($accumulator, $conversation_id, $data);
                break;

            case 'WIDGET_ERROR':
                $this->handleWidgetError($accumulator, $conversation_id, $data);
                break;

            default:
                // No accumulation for: conversation_id, done, error, action_call_result,
                // data_preview, dashboard_patch, comment/heartbeat events
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

        // Fallback: extract SQL from markdown code blocks if no sql_generated event
        $existingSql = $accumulator->peekField($conversation_id, 'sql');
        if ($existingSql === '' || $existingSql === null) {
            $content = $accumulator->peekField($conversation_id, 'content');
            if (is_string($content) && $content !== ''
                && preg_match_all('/```sql\s*\n([\s\S]*?)\n```/i', $content, $matches)
                && !empty($matches[1])) {
                $extractedSql = trim((string) end($matches[1]));
                if ($extractedSql !== '') {
                    $accumulator->accumulateSql($conversation_id, $extractedSql);
                }
            }
        }
    }

    private function handleSqlGenerated(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $sql = $data['sql'] ?? '';
        if ($sql === '') {
            return;
        }

        $accumulator->accumulateSql($conversation_id, $sql);

        $existingWidgetId = $accumulator->peekField($conversation_id, 'widget_id');
        if ($existingWidgetId !== '') {
            $this->backfillWidgetSql($existingWidgetId, $sql);
        }
    }

    /**
     * Accumulate DASHBOARD_INIT: persist each declared widget placeholder's
     * title/type into the conversation metadata, keyed by widget_id. Layout
     * placeholders carry no sql/g2_spec yet — they are skeleton frames.
     */
    private function handleDashboardInit(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $widgets = $data['widgets'] ?? [];
        if (!is_array($widgets)) {
            return;
        }
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $widgetId = (string) ($widget['widget_id'] ?? $widget['id'] ?? '');
            if ($widgetId === '') {
                continue;
            }
            $payload = [];
            if (isset($widget['title'])) {
                $payload['title'] = $widget['title'];
            }
            // Skeleton widgets carry the chart kind as `chart_type` (contract §2
            // Python emitter). Tolerate the `type` alias too. Without this the
            // persisted skeleton silently loses its chart kind.
            if (isset($widget['chart_type'])) {
                $payload['chart_type'] = $widget['chart_type'];
            } elseif (isset($widget['type'])) {
                $payload['chart_type'] = $widget['type'];
            }
            $accumulator->accumulateWidgetData($conversation_id, $widgetId, $payload);
        }
    }

    /**
     * Accumulate WIDGET_DATA_UPDATE: store sql/data/truncated/total per widget_id.
     * truncated/total are forwarded verbatim — never recomputed locally.
     */
    private function handleWidgetDataUpdate(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $widgetId = (string) ($data['widget_id'] ?? $data['id'] ?? '');
        if ($widgetId === '') {
            return;
        }
        $payload = [];
        foreach (['sql', 'data', 'truncated', 'total', 'g2_spec', 'chart_type'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        $accumulator->accumulateWidgetData($conversation_id, $widgetId, $payload);
    }

    /**
     * Accumulate WIDGET_ERROR: store the error message per widget_id.
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
        $accumulator->accumulateWidgetData($conversation_id, $widgetId, $payload);
    }

    /**
     * Backfill the SQL field on an existing dashboard widget via Repository.
     */
    public function backfillWidgetSql(string $widget_id, string $sql): void
    {
        if ($this->dashboardUid === '') {
            return;
        }

        try {
            $this->dashboardRepo->updateWidgetSql($this->dashboardUid, $widget_id, $sql);
        } catch (DashboardNotFoundException $e) {
            // Dashboard not found — non-critical, skip
        } catch (\Throwable $e) {
            ($this->logger)('backfill widget sql failed', $e->getMessage());
        }
    }
}
