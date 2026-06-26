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
 *
 * declarative-frontend-adapter: the mock emulates the Python emitter under
 * the whole-tree protocol — every intent delivers a single DASHBOARD_REPLACE
 * frame (contract §2), never the deprecated DASHBOARD_INIT / WIDGET_DATA_UPDATE
 * / dashboard_patch / WIDGET_UPDATE sequence. tool_start/tool_result are the
 * contract §5 tool-progress names.
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
            // dashboard_context may arrive as a nested stdClass (JSON object),
            // not an array — normalize so ['widgets'] access never throws
            // "Cannot use object of type stdClass as array".
            $ctx = $payload['dashboard_context'] ?? [];
            if (is_object($ctx)) {
                $ctx = json_decode((string) json_encode($ctx), true) ?? [];
            }
            $widgets = is_array($ctx) ? ($ctx['widgets'] ?? []) : [];
            $firstWidgetId = !empty($widgets) ? ($widgets[0]['id'] ?? null) : null;
            $intent = $this->classifyMockIntent($question);

            switch ($intent) {
                case 'modify_chart':
                    $this->emitMockModifyChart($writer, $question, $firstWidgetId, $widgets);
                    break;
                case 'rename':
                    $this->emitMockRename($writer, $question, $firstWidgetId, $widgets);
                    break;
                case 'delete':
                    $this->emitMockDelete($writer, $question, $firstWidgetId, $widgets);
                    break;
                case 'add':
                    $this->emitMockAddChart($writer, $question);
                    break;
                case 'multi':
                    // Whole-dashboard request: whole-tree delivery.
                    $this->emitMockMultiWidget($writer);
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
        // Multi-widget summary dashboard — matched first so a whole-dashboard
        // request takes the whole-tree delivery path (DASHBOARD_REPLACE).
        if (preg_match('/整体.*仪表盘|仪表盘|总览|概览|overview|dashboard|summary/u', $question)) {
            return 'multi';
        }
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

        $sql = 'SELECT category, COUNT(*) AS cnt FROM qs_film GROUP BY category ORDER BY cnt DESC';
        $widgetId = 'mock-' . substr(md5($question), 0, 8);
        $g2Spec = ['type' => 'interval', 'encode' => ['x' => 'category', 'y' => 'cnt']];
        $data = $this->mockFilmCategoryData();

        // Whole-tree delivery: single DASHBOARD_REPLACE carries the complete
        // widget (sql + g2_spec + data + truncated/total). No skeleton frame.
        $writer->sendEvent($this->buildDashboardReplace(
            [['i' => $widgetId, 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6]],
            [
                $widgetId => [
                    'widget_id'  => $widgetId,
                    'title'      => $this->mockTitleFromQuestion($question),
                    'status'     => 'success',
                    'sql'        => $sql,
                    'g2_spec'    => $g2Spec,
                    'data'       => $data,
                    'truncated'  => false,
                    'total'      => count($data),
                ],
            ],
            '已为您生成图表'
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockModifyChart(SseWriter $writer, string $question, ?string $widgetId, array $ctxWidgets): void
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

        // tool_start — contract §5 tool-progress indicator (the modify intent
        // emulates commit_widget re-spec). Contract §5 base event name.
        $writer->sendEvent(new SseEvent(
            type: 'tool_start',
            data: ['tool_name' => 'commit_widget', 'tool_args' => ['widget_id' => $widgetId]],
            raw: '',
        ));

        // Whole-tree delivery: rebuild the dashboard tree from the inbound
        // dashboard_context widgets, swapping the changed widget's g2_spec.
        // data:null slim — the modify path re-uses cached data (contract §2).
        $widgets = $this->rebuildWidgetsFromContext($ctxWidgets, $widgetId, [
            'status'  => 'success',
            'g2_spec' => $g2Spec,
            'data'    => null,
        ]);
        $layout = $this->rebuildLayoutFromContext($ctxWidgets);
        $writer->sendEvent($this->buildDashboardReplace($layout, $widgets, ''));

        // tool_result — contract §5.
        $writer->sendEvent(new SseEvent(
            type: 'tool_result',
            data: ['success' => true, 'summary' => "图表已改为{$chartType}"],
            raw: '',
        ));

        // answer
        $typeLabel = ['line' => '折线图', 'pie' => '饼图', 'bar' => '柱状图', 'table' => '表格'][$chartType] ?? $chartType;
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "已将图表改为{$typeLabel}"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockRename(SseWriter $writer, string $question, ?string $widgetId, array $ctxWidgets): void
    {
        $widgetId = $widgetId ?? 'mock-default';
        $newTitle = $this->extractMockNewTitle($question);

        // tool_start
        $writer->sendEvent(new SseEvent(
            type: 'tool_start',
            data: ['tool_name' => 'commit_widget', 'tool_args' => ['widget_id' => $widgetId, 'title' => $newTitle]],
            raw: '',
        ));

        // Whole-tree delivery: rebuild the tree, swapping the renamed title.
        // data:null slim — title change does not re-execute SQL.
        $widgets = $this->rebuildWidgetsFromContext($ctxWidgets, $widgetId, [
            'status'  => 'success',
            'title'   => $newTitle,
            'data'    => null,
        ]);
        $layout = $this->rebuildLayoutFromContext($ctxWidgets);
        $writer->sendEvent($this->buildDashboardReplace($layout, $widgets, ''));

        // tool_result
        $writer->sendEvent(new SseEvent(
            type: 'tool_result',
            data: ['success' => true, 'summary' => '标题已更新'],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => "标题已更新为'{$newTitle}'"], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockDelete(SseWriter $writer, string $question, ?string $widgetId, array $ctxWidgets): void
    {
        $widgetId = $widgetId ?? 'mock-default';

        // tool_start
        $writer->sendEvent(new SseEvent(
            type: 'tool_start',
            data: ['tool_name' => 'commit_widget', 'tool_args' => ['widget_id' => $widgetId, 'remove' => true]],
            raw: '',
        ));

        // Whole-tree delivery: rebuild the tree WITHOUT the deleted widget.
        // Per contract §2, widgets absent from the new tree are cleared.
        $widgets = $this->rebuildWidgetsFromContext($ctxWidgets, $widgetId, null);
        $layout = $this->rebuildLayoutFromContext($ctxWidgets, $widgetId);
        $writer->sendEvent($this->buildDashboardReplace($layout, $widgets, ''));

        // tool_result
        $writer->sendEvent(new SseEvent(
            type: 'tool_result',
            data: ['success' => true, 'summary' => '图表已删除'],
            raw: '',
        ));

        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '已删除该图表'], raw: ''));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    private function emitMockAddChart(SseWriter $writer, string $question): void
    {
        $writer->sendEvent(new SseEvent(type: 'answer', data: ['text' => '正在生成新图表...'], raw: ''));

        $sql = 'SELECT year, SUM(revenue) AS revenue FROM qs_film_yearly GROUP BY year ORDER BY year';
        $widgetId = 'mock-' . substr(md5($question . time()), 0, 8);
        $g2Spec = ['type' => 'line', 'encode' => ['x' => 'year', 'y' => 'revenue']];
        $data = [
            ['year' => '2020', 'revenue' => 1200],
            ['year' => '2021', 'revenue' => 1800],
            ['year' => '2022', 'revenue' => 2400],
            ['year' => '2023', 'revenue' => 3100],
            ['year' => '2024', 'revenue' => 4200],
        ];

        // Whole-tree delivery: single DASHBOARD_REPLACE with the new widget.
        $writer->sendEvent($this->buildDashboardReplace(
            [['i' => $widgetId, 'x' => 0, 'y' => 6, 'w' => 12, 'h' => 6]],
            [
                $widgetId => [
                    'widget_id'  => $widgetId,
                    'title'      => $this->mockTitleFromQuestion($question),
                    'status'     => 'success',
                    'sql'        => $sql,
                    'g2_spec'    => $g2Spec,
                    'data'       => $data,
                    'truncated'  => false,
                    'total'      => count($data),
                ],
            ],
            '已为您生成新图表'
        ));

        $writer->sendEvent(new SseEvent(type: 'done', data: [], raw: ''));
    }

    /**
     * Build the whole-tree DASHBOARD_REPLACE event frame (pure, no side effects).
     *
     * Kept separate so the frame layout is unit-testable without a live SseWriter.
     * Single frame carries the complete tree: layout + widgets map + answer.
     *
     * @param array<int, array{i:string,x:int,y:int,w:int,h:int}> $layout
     * @param array<string, array<string, mixed>>                 $widgets
     */
    public function buildDashboardReplace(array $layout, array $widgets, string $answer = ''): SseEvent
    {
        return new SseEvent(
            type: 'DASHBOARD_REPLACE',
            data: [
                'layout'  => $layout,
                'widgets' => $widgets,
                'answer'  => $answer,
            ],
            raw: '',
        );
    }

    /**
     * Build the multi-widget whole-tree event sequence (pure, no side effects).
     *
     * declarative-frontend-adapter: the deprecated DASHBOARD_INIT → N×
     * WIDGET_DATA_UPDATE sequence collapsed to a single DASHBOARD_REPLACE.
     *
     * @return SseEvent[]  [tool_start, DASHBOARD_REPLACE, tool_result, done]
     */
    public function buildMultiWidgetEvents(int $widgetCount = 3): array
    {
        $widgetCount = max(1, $widgetCount);

        $layoutMap = [
            ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
            ['x' => 12, 'y' => 0, 'w' => 12, 'h' => 6],
            ['x' => 0, 'y' => 6, 'w' => 24, 'h' => 6],
        ];
        $types = ['bar', 'line', 'pie'];
        $titles = ['分类销量对比', '月度趋势', '占比分布'];
        $g2Specs = [
            ['type' => 'interval', 'encode' => ['x' => 'category', 'y' => 'cnt']],
            ['type' => 'line', 'encode' => ['x' => 'month', 'y' => 'revenue']],
            ['type' => 'pie', 'encode' => ['color' => 'rating', 'y' => 'cnt']],
        ];

        $widgets = [];
        $layout = [];
        for ($i = 0; $i < $widgetCount; $i++) {
            $widgetId = 'w' . ($i + 1);
            $slot = $layoutMap[$i % count($layoutMap)];
            $data = $this->mockWidgetRows($i);
            // Contract §2: each widget carries the full record (sql + g2_spec +
            // data + truncated/total). Mock emulates Python's sole-computation
            // authority role for truncated/total.
            $widgets[$widgetId] = [
                'widget_id'  => $widgetId,
                'title'      => $titles[$i % count($titles)],
                'status'     => 'success',
                'sql'        => $this->mockWidgetSql($i),
                'g2_spec'    => $g2Specs[$i % count($g2Specs)],
                'data'       => $data,
                'truncated'  => false,
                'total'      => count($data),
            ];
            $layout[] = [
                'i' => $widgetId,
                'x' => $slot['x'],
                'y' => $slot['y'],
                'w' => $slot['w'],
                'h' => $slot['h'],
            ];
        }

        $events = [];
        $events[] = new SseEvent(
            type: 'tool_start',
            data: ['tool_name' => 'commit_widget', 'tool_args' => ['widget_count' => $widgetCount]],
            raw: '',
        );
        $events[] = $this->buildDashboardReplace($layout, $widgets, '已为您生成多图表仪表盘');
        $events[] = new SseEvent(
            type: 'tool_result',
            data: ['success' => true, 'summary' => "生成 {$widgetCount} 个图表"],
            raw: '',
        );
        $events[] = new SseEvent(type: 'done', data: [], raw: '');
        return $events;
    }

    /**
     * Emit the multi-widget whole-tree mock stream to the given SseWriter.
     * Companion to emitMockQuery() (single-widget, same whole-tree path).
     */
    public function emitMockMultiWidget(SseWriter $writer, int $widgetCount = 3): void
    {
        foreach ($this->buildMultiWidgetEvents($widgetCount) as $event) {
            $writer->sendEvent($event);
        }
    }

    // ── Context rebuild helpers (modify/rename/delete emulations) ────────────

    /**
     * Rebuild a widgets map from the inbound dashboard_context widgets.
     * - $override = array  → merge these fields into the target widget.
     * - $override = null   → omit the target widget (delete intent).
     *
     * @param array<int|string, array<string, mixed>> $ctxWidgets
     * @return array<string, array<string, mixed>>
     */
    private function rebuildWidgetsFromContext(array $ctxWidgets, string $targetId, ?array $override): array
    {
        $out = [];
        foreach ($ctxWidgets as $w) {
            if (!is_array($w)) {
                continue;
            }
            $id = (string) ($w['id'] ?? $w['widget_id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($id === $targetId && $override === null) {
                continue; // delete
            }
            $record = [
                'widget_id' => $id,
                'title'     => (string) ($w['title'] ?? ''),
                'status'    => 'success',
                'data'      => null,
            ];
            if (isset($w['sql'])) {
                $record['sql'] = (string) $w['sql'];
            }
            if ($id === $targetId && is_array($override)) {
                $record = array_merge($record, $override);
            }
            $out[$id] = $record;
        }
        return $out;
    }

    /**
     * Rebuild a layout array from the inbound dashboard_context widgets.
     * Optionally omit a target widget (delete intent).
     *
     * @param array<int|string, array<string, mixed>> $ctxWidgets
     * @return array<int, array{i:string,x:int,y:int,w:int,h:int}>
     */
    private function rebuildLayoutFromContext(array $ctxWidgets, ?string $omitId = null): array
    {
        $layout = [];
        $i = 0;
        foreach ($ctxWidgets as $w) {
            if (!is_array($w)) {
                continue;
            }
            $id = (string) ($w['id'] ?? $w['widget_id'] ?? '');
            if ($id === '' || $id === $omitId) {
                continue;
            }
            $slot = $w['layout'] ?? null;
            $layout[] = [
                'i' => $id,
                'x' => is_array($slot) ? (int) ($slot['x'] ?? 0) : 0,
                'y' => is_array($slot) ? (int) ($slot['y'] ?? 0) : 0,
                'w' => is_array($slot) ? (int) ($slot['w'] ?? 12) : 12,
                'h' => is_array($slot) ? (int) ($slot['h'] ?? 6) : 6,
            ];
            $i++;
        }
        return $layout;
    }

    private function mockWidgetSql(int $index): string
    {
        $sqls = [
            'SELECT category, COUNT(*) AS cnt FROM qs_film GROUP BY category ORDER BY cnt DESC',
            'SELECT month, SUM(revenue) AS revenue FROM qs_monthly_sales GROUP BY month ORDER BY month',
            'SELECT rating, COUNT(*) AS cnt FROM qs_film GROUP BY rating ORDER BY cnt DESC',
        ];
        return $sqls[$index % count($sqls)];
    }

    private function mockWidgetRows(int $index): array
    {
        $rows = [
            [
                ['category' => '动作', 'cnt' => 64],
                ['category' => '喜剧', 'cnt' => 51],
                ['category' => '剧情', 'cnt' => 43],
            ],
            [
                ['month' => '2026-01', 'revenue' => 1200],
                ['month' => '2026-02', 'revenue' => 1800],
                ['month' => '2026-03', 'revenue' => 2400],
            ],
            [
                ['rating' => 'PG', 'cnt' => 194],
                ['rating' => 'R', 'cnt' => 195],
                ['rating' => 'NC-17', 'cnt' => 210],
            ],
        ];
        return $rows[$index % count($rows)];
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
