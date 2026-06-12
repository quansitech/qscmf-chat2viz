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

            case 'chart_ready':
                $this->handleChartReady($accumulator, $conversation_id, $data);
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

    private function handleChartReady(
        StreamAccumulator $accumulator,
        string $conversation_id,
        array $data
    ): void {
        $g2Spec = $data['g2_spec'] ?? null;
        if (is_array($g2Spec)) {
            $accumulator->accumulateG2Spec($conversation_id, $g2Spec);
        }

        $chartType = $data['chart_type'] ?? '';
        $widgetId = $data['id'] ?? $data['widget_id'] ?? '';
        if ($chartType !== '' || $widgetId !== '') {
            $accumulator->accumulateChartMeta($conversation_id, (string) $chartType, (string) $widgetId);
        }

        // Merge SQL: payload.sql first, then previously accumulated sql_generated.
        $payloadSql = $data['sql'] ?? '';
        if (!is_string($payloadSql) || $payloadSql === '') {
            $payloadSql = $accumulator->peekField($conversation_id, 'sql');
        }
        if ($payloadSql !== '' && $widgetId !== '') {
            $this->backfillWidgetSql((string) $widgetId, $payloadSql);
        }
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
