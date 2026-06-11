<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Sse;

use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SseWriter;

/**
 * Deterministic SSE mock emitter for E2E tests and offline development.
 *
 * Extracted from Chat2VizController to keep the controller under 800 lines.
 * All methods are pure SSE emitters — they only use SseWriter, SseEvent,
 * and local data. No controller context or logger dependency needed.
 */
class MockStreamEmitter
{
    public function isMockMode(): bool
    {
        $val = (string) env('CHAT2VIZ_MOCK_MODE', 'false');
        return in_array(strtolower($val), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Emit a deterministic SSE stream with intent-based mock responses.
     * Used in mock mode / E2E tests when the real Python NL2SQL service is offline.
     */
    public function emitMockStream(array $payload, bool $wantsChat2viz, string $conversationId): void
    {
        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();
        $writer = new SseWriter(autoStart: false);

        $question = $payload['question'] ?? '';

        // conversation_id
        $writer->sendEvent(new SseEvent(
            type: 'conversation_id',
            data: ['conversation_id' => $conversationId, 'is_mock' => true],
            raw: '',
        ));

        if ($wantsChat2viz) {
            $widgets = $payload['dashboard_context']['widgets'] ?? [];
            $firstWidgetId = !empty($widgets) ? ($widgets[0]['id'] ?? null) : null;
            $intent = $this->classifyMockIntent($question);

            switch ($intent) {
                case 'modify_chart':
                    $this->emitMockModifyChart($writer, $question, $firstWidgetId);
                    break;
                case 'rename':
                    $this->emitMockRename($writer, $question, $firstWidgetId);
                    break;
                case 'delete':
                    $this->emitMockDelete($writer, $question, $firstWidgetId);
                    break;
                case 'add':
                    $this->emitMockAddChart($writer, $question);
                    break;
                default:
                    $this->emitMockQuery($writer, $question);
                    break;
            }
        } else {
            // Plain SSE passthrough — send simple answer + done
            $writer->sendEvent(new SseEvent(
                type: 'message',
                data: ['text' => '[Mock] 查询已完成: ' . $question],
                raw: '',
            ));
        }
    }

    // ── Mock intent classification ──────────────────────────────────────

    private function classifyMockIntent(string $question): string
    {
        if (preg_match('/换成|换.*图|改.*图|变成.*图|修改.*图/u', $question)) {
            return 'modify_chart';
        }
        if (preg_match('/标题.*改成|改.*标题|改名|重命名/u', $question)) {
            return 'rename';
        }
        if (preg_match('/删|删除|去掉|移除|不要.*图/u', $question)) {
            return 'delete';
        }
        if (preg_match('/再加|新增|加一个|添加|再加一个/u', $question)) {
            return 'add';
        }
        return 'query';
    }

    private function extractMockChartType(string $question): string
    {
        $map = [
            '/折线|line/u' => 'line',
            '/饼|pie|占比/u' => 'pie',
            '/表格|table|列表/u' => 'table',
            '/柱|bar|柱状/u' => 'bar',
        ];
        foreach ($map as $pattern => $type) {
            if (preg_match($pattern, $question)) {
                return $type;
            }
        }
        return 'line'; // default fallback for modify_chart
    }

    private function extractMockNewTitle(string $question): string
    {
        // Extract text within quotes (single or double or Chinese quotes)
        if (preg_match('/[\'"\'"](.*?)[\'"\'"]/u', $question, $m)) {
            return $m[1];
        }
        // Extract text after "改成" or "改为"
        if (preg_match('/改成|改为|修改为(.+)/u', $question, $m)) {
            return trim($m[1]);
        }
        return '新标题';
    }

    // ── Mock SSE event emitters per intent ───────────────────────────────

    private function emitMockQuery(SseWriter $writer, string $question): void
    {
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '正在分析'], raw: ''));

        $sql = 'SELECT category, COUNT(*) AS cnt FROM film GROUP BY category ORDER BY cnt DESC';
        $writer->sendEvent(new SseEvent(type: 'sql_generated', data: ['sql' => $sql], raw: ''));

        $widgetId = 'mock-' . substr(md5($question), 0, 8);
        $writer->sendEvent(new SseEvent(
            type: 'chart_ready',
            data: [
                'id'         => $widgetId,
                'title'      => $this->mockTitleFromQuestion($question),
                'chart_type' => 'bar',
                'g2_spec'    => [
                    'type'  => 'interval',
                    'encode' => ['x' => 'category', 'y' => 'cnt'],
                ],
                'data' => $this->mockFilmCategoryData(),
                'sql'    => $sql,
                'layout' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            ],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockModifyChart(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';
        $chartType = $this->extractMockChartType($question);

        $g2SpecMap = [
            'line'  => ['type' => 'line', 'encode' => ['x' => 'category', 'y' => 'cnt']],
            'pie'   => ['type' => 'pie', 'encode' => ['color' => 'category', 'y' => 'cnt']],
            'bar'   => ['type' => 'interval', 'encode' => ['x' => 'category', 'y' => 'cnt']],
            'table' => ['type' => 'table', 'columns' => ['category', 'cnt']],
        ];
        $g2Spec = $g2SpecMap[$chartType] ?? $g2SpecMap['line'];

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'change_chart_type',
                'params' => ['widget_id' => $widgetId, 'new_type' => $chartType],
            ],
            raw: '',
        ));

        // dashboard_patch — replace g2_spec and chart_type
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/g2_spec", 'value' => $g2Spec],
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/chart_type", 'value' => $chartType],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 2]],
            raw: '',
        ));

        // answer
        $typeLabel = ['line' => '折线图', 'pie' => '饼图', 'bar' => '柱状图', 'table' => '表格'][$chartType] ?? $chartType;
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "已将图表改为{$typeLabel}"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockRename(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';
        $newTitle = $this->extractMockNewTitle($question);

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'update_widget_field',
                'params' => ['widget_id' => $widgetId, 'field' => 'title', 'value' => $newTitle],
            ],
            raw: '',
        ));

        // dashboard_patch
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'replace', 'path' => "/widgets/{$widgetId}/title", 'value' => $newTitle],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 1]],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "标题已更新为'{$newTitle}'"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockDelete(SseWriter $writer, string $question, ?string $widgetId): void
    {
        $widgetId = $widgetId ?? 'mock-default';

        // action_call
        $writer->sendEvent(new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => 'remove_widget',
                'params' => ['widget_id' => $widgetId],
            ],
            raw: '',
        ));

        // dashboard_patch
        $writer->sendEvent(new SseEvent(
            type: 'dashboard_patch',
            data: [
                'patches' => [
                    ['op' => 'remove', 'path' => "/widgets/{$widgetId}"],
                ],
            ],
            raw: '',
        ));

        // action_call_result
        $writer->sendEvent(new SseEvent(
            type: 'action_call_result',
            data: ['success' => true, 'result' => ['widget_id' => $widgetId, 'patch_count' => 1]],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '已删除该图表'], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockAddChart(SseWriter $writer, string $question): void
    {
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '正在生成新图表...'], raw: ''));

        $sql = 'SELECT year, SUM(revenue) AS revenue FROM film_yearly GROUP BY year ORDER BY year';
        $writer->sendEvent(new SseEvent(type: 'sql_generated', data: ['sql' => $sql], raw: ''));

        $widgetId = 'mock-' . substr(md5($question . time()), 0, 8);
        $writer->sendEvent(new SseEvent(
            type: 'chart_ready',
            data: [
                'id'         => $widgetId,
                'title'      => $this->mockTitleFromQuestion($question),
                'chart_type' => 'line',
                'g2_spec'    => [
                    'type'  => 'line',
                    'encode' => ['x' => 'year', 'y' => 'revenue'],
                ],
                'data' => [
                    ['year' => '2020', 'revenue' => 1200],
                    ['year' => '2021', 'revenue' => 1800],
                    ['year' => '2022', 'revenue' => 2400],
                    ['year' => '2023', 'revenue' => 3100],
                    ['year' => '2024', 'revenue' => 4200],
                ],
                'sql'    => $sql,
                'layout' => ['x' => 0, 'y' => 6, 'w' => 12, 'h' => 6],
            ],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function mockFilmCategoryData(): array
    {
        return [
            ['category' => '动作', 'cnt' => 64],
            ['category' => '喜剧', 'cnt' => 51],
            ['category' => '剧情', 'cnt' => 43],
            ['category' => '恐怖', 'cnt' => 28],
            ['category' => '科幻', 'cnt' => 22],
        ];
    }

    private function mockTitleFromQuestion(string $question): string
    {
        if (mb_strlen($question) > 20) {
            return mb_substr($question, 0, 20) . '...';
        }
        return $question;
    }
}
