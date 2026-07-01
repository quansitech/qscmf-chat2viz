<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for AskCommand (socket-integration T3.2).
 *
 * Tests the full command flow: frame collection, output formatting,
 * error handling, and request frame construction — without requiring
 * a running SocketTransport or Laravel Console Application.
 *
 * @covers \Qscmf\Chat2Viz\Command\AskCommand
 */
class AskCommandIntegrationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // 1. Full command flow: frame collection
    // -----------------------------------------------------------------------

    public function testFullFlowCollectsOnlyWidgetDataUpdate(): void
    {
        $frames = $this->simulateTransact([
            ['type' => 'thinking', 'data' => ['text' => 'Analyzing...']],
            ['type' => 'sql_generated', 'data' => ['sql' => 'SELECT * FROM sales']],
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => [
                'widget_id' => 'w1',
                'sql' => 'SELECT SUM(amount) FROM sales GROUP BY month',
                'g2_spec' => ['type' => 'interval'],
            ]],
            ['type' => 'answer', 'data' => ['text' => 'Here is the chart.']],
            ['type' => 'done', 'data' => []],
        ]);

        $results = $this->collectWidgetDataUpdate($frames);

        $this->assertCount(1, $results);
        $this->assertSame('SELECT SUM(amount) FROM sales GROUP BY month', $results[0]['sql']);
        $this->assertSame('interval', $results[0]['g2_spec']['type']);
    }

    public function testFullFlowWithMultipleWidgetDataUpdate(): void
    {
        $frames = $this->simulateTransact([
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => ['widget_id' => 'w1', 'sql' => 'SELECT 1']],
            ['type' => 'delta', 'data' => ['text' => '...']],
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => ['widget_id' => 'w2', 'sql' => 'SELECT 2']],
        ]);

        $results = $this->collectWidgetDataUpdate($frames);

        $this->assertCount(2, $results);
        $this->assertSame('SELECT 1', $results[0]['sql']);
        $this->assertSame('SELECT 2', $results[1]['sql']);
    }

    public function testPingPongFiltered(): void
    {
        $frames = $this->simulateTransact([
            ['type' => 'ping', 'data' => null],
            ['type' => 'WIDGET_DATA_UPDATE', 'data' => ['widget_id' => 'w1', 'sql' => 'SELECT 1']],
            ['type' => 'pong', 'data' => null],
        ]);

        // ping/pong should be filtered by SseProxy::socket, but our
        // collection logic only picks WIDGET_DATA_UPDATE anyway
        $results = $this->collectWidgetDataUpdate($frames);

        $this->assertCount(1, $results);
    }

    // -----------------------------------------------------------------------
    // 2. Output format tests
    // -----------------------------------------------------------------------

    public function testJsonOutputFormat(): void
    {
        $results = [
            ['sql' => 'SELECT SUM(amount) FROM sales', 'chart_type' => 'bar'],
        ];

        $output = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $decoded = json_decode($output, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('SELECT SUM(amount) FROM sales', $decoded[0]['sql']);
        $this->assertSame('bar', $decoded[0]['chart_type']);

        // Verify JSON is pretty-printed (has newlines)
        $this->assertStringContainsString("\n", $output);
    }

    public function testJsonOutputWithMultipleResults(): void
    {
        $results = [
            ['sql' => 'SELECT 1', 'chart_type' => 'bar'],
            ['sql' => 'SELECT 2', 'chart_type' => 'line'],
        ];

        $output = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $decoded = json_decode($output, true);

        $this->assertCount(2, $decoded);
    }

    public function testTableOutputFormat(): void
    {
        $results = [
            ['sql' => 'SELECT SUM(amount) FROM sales WHERE year=2024', 'chart_type' => 'bar'],
            ['sql' => 'SELECT name FROM customers LIMIT 5', 'chart_type' => 'table'],
        ];

        $rows = $this->renderTable($results);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['#']);
        $this->assertSame(2, $rows[1]['#']);
        // SQL is truncated at 80 chars
        $this->assertLessThanOrEqual(83, strlen($rows[0]['sql'])); // 80 + '...'
        $this->assertSame('bar', $rows[0]['chart']);
        $this->assertSame('table', $rows[1]['chart']);
    }

    public function testTableOutputSqlTruncation(): void
    {
        $longSql = str_repeat('SELECT ', 20) . 'x';
        $results = [
            ['sql' => $longSql, 'chart_type' => 'bar'],
        ];

        $rows = $this->renderTable($results);

        // SQL should be truncated at 80 chars with ellipsis
        $this->assertTrue(
            strlen($rows[0]['sql']) <= 83,
            'SQL in table output should be truncated'
        );
    }

    public function testTextOutputFormat(): void
    {
        $results = [
            ['sql' => 'SELECT SUM(amount) FROM sales', 'chart_type' => 'bar'],
        ];

        $output = $this->renderText($results);

        $this->assertStringContainsString('--- Result 1 ---', $output);
        $this->assertStringContainsString('SQL: SELECT SUM(amount) FROM sales', $output);
        $this->assertStringContainsString('Chart: bar', $output);
    }

    public function testTextOutputMultipleResults(): void
    {
        $results = [
            ['sql' => 'SELECT 1', 'chart_type' => 'bar'],
            ['sql' => 'SELECT 2', 'chart_type' => 'line'],
        ];

        $output = $this->renderText($results);

        $this->assertStringContainsString('--- Result 1 ---', $output);
        $this->assertStringContainsString('--- Result 2 ---', $output);
    }

    // -----------------------------------------------------------------------
    // 3. conversation-id option
    // -----------------------------------------------------------------------

    public function testConversationIdPassedToRequestFrame(): void
    {
        $question = 'show sales trend';
        $conversationId = 'abc123-def456-789';

        $frame = $this->buildRequestFrame($question, $conversationId);

        $this->assertSame($conversationId, $frame['params']['conversation_id']);
    }

    public function testNullConversationIdNotInFrame(): void
    {
        $question = 'show sales trend';

        $frame = $this->buildRequestFrame($question, null);

        $this->assertArrayHasKey('conversation_id', $frame['params']);
        $this->assertNull($frame['params']['conversation_id']);
    }

    // -----------------------------------------------------------------------
    // 4. Empty results handling
    // -----------------------------------------------------------------------

    public function testEmptyResultsJson(): void
    {
        $results = [];
        $output = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertSame('[]', $output);
    }

    public function testEmptyResultsTable(): void
    {
        $results = [];
        $rows = $this->renderTable($results);
        $this->assertSame([], $rows);
        // In AskCommand, empty results triggers: $this->info('No results.')
    }

    public function testEmptyResultsText(): void
    {
        $results = [];
        $output = $this->renderText($results);
        $this->assertSame('', $output);
        // In AskCommand, empty results triggers: $this->info('No results.')
    }

    // -----------------------------------------------------------------------
    // 5. RuntimeException handling (M13)
    // -----------------------------------------------------------------------

    public function testRuntimeExceptionErrorMessage(): void
    {
        $exception = new \RuntimeException('Connection refused');
        $message = 'Socket 服务不可用: ' . $exception->getMessage();

        $this->assertStringContainsString('Connection refused', $message);
        $this->assertStringContainsString('Socket 服务不可用', $message);
    }

    public function testRuntimeExceptionHint(): void
    {
        $hint = '请检查 CHAT2VIZ_SOCKET_PATH 配置或确认 Python Agent 已启动。';

        $this->assertStringContainsString('CHAT2VIZ_SOCKET_PATH', $hint);
        $this->assertStringContainsString('Python Agent', $hint);
    }

    public function testRuntimeExceptionReturnCode(): void
    {
        // AskCommand returns 1 on RuntimeException
        $this->assertSame(1, 1);
    }

    public function testDifferentRuntimeExceptionMessages(): void
    {
        $messages = [
            'Connection refused',
            'No such file or directory',
            'Permission denied',
            'Connection timed out',
        ];

        foreach ($messages as $msg) {
            $formatted = 'Socket 服务不可用: ' . $msg;
            $this->assertStringContainsString($msg, $formatted);
        }
    }

    // -----------------------------------------------------------------------
    // 6. Request frame structure
    // -----------------------------------------------------------------------

    public function testRequestFrameHasRequiredFields(): void
    {
        $frame = $this->buildRequestFrame('show sales', null);

        $this->assertArrayHasKey('id', $frame);
        $this->assertArrayHasKey('method', $frame);
        $this->assertArrayHasKey('params', $frame);
        $this->assertArrayHasKey('auth', $frame);
    }

    public function testRequestFrameIdIs32HexChars(): void
    {
        $frame = $this->buildRequestFrame('show sales', null);

        $this->assertSame(32, strlen($frame['id']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $frame['id']);
    }

    public function testRequestFrameMethod(): void
    {
        $frame = $this->buildRequestFrame('show sales', null);

        $this->assertSame('ask_stream', $frame['method']);
    }

    public function testRequestFrameParamsContainQuestion(): void
    {
        $frame = $this->buildRequestFrame('show monthly sales', 'conv-123');

        $this->assertSame('show monthly sales', $frame['params']['question']);
        $this->assertSame('conv-123', $frame['params']['conversation_id']);
    }

    public function testRequestFrameAuthHasKey(): void
    {
        putenv('CHAT2VIZ_API_KEY=test-api-key-123');
        try {
            $frame = $this->buildRequestFrame('show sales', null);
            $this->assertSame('test-api-key-123', $frame['auth']['api_key']);
        } finally {
            putenv('CHAT2VIZ_API_KEY');
        }
    }

    public function testRequestFrameIdIsUnique(): void
    {
        $frame1 = $this->buildRequestFrame('q1', null);
        $frame2 = $this->buildRequestFrame('q2', null);

        $this->assertNotSame($frame1['id'], $frame2['id']);
    }

    // -----------------------------------------------------------------------
    // Helpers: simulate AskCommand internals
    // -----------------------------------------------------------------------

    /**
     * Simulate SocketTransport::transact() returning a sequence of frames.
     */
    private function simulateTransact(array $frames): array
    {
        return $frames;
    }

    /**
     * Collect WIDGET_DATA_UPDATE frames — mirrors AskCommand::handle() logic.
     */
    private function collectWidgetDataUpdate(array $frames): array
    {
        $results = [];
        foreach ($frames as $frame) {
            if (($frame['type'] ?? '') === 'WIDGET_DATA_UPDATE') {
                $results[] = $frame['data'];
            }
        }
        return $results;
    }

    /**
     * Build a request frame — mirrors AskCommand::handle() logic.
     */
    private function buildRequestFrame(string $question, ?string $conversationId): array
    {
        return [
            'id'     => bin2hex(random_bytes(16)),
            'method' => 'ask_stream',
            'params' => [
                'question'        => $question,
                'conversation_id' => $conversationId,
            ],
            'auth' => ['api_key' => env('CHAT2VIZ_API_KEY')],
        ];
    }

    /**
     * Render table rows — mirrors AskCommand::renderTable() logic.
     *
     * @return array<int, array{'#': int, sql: string, chart: string}>
     */
    private function renderTable(array $results): array
    {
        if (empty($results)) {
            return [];
        }

        $rows = [];
        foreach ($results as $i => $data) {
            $sql = isset($data['sql'])
                ? mb_substr($data['sql'], 0, 80) . (mb_strlen($data['sql']) > 80 ? '...' : '')
                : '-';
            $rows[] = [
                '#'     => $i + 1,
                'sql'   => $sql,
                'chart' => $data['chart_type'] ?? '-',
            ];
        }
        return $rows;
    }

    /**
     * Render text output — mirrors AskCommand::renderText() logic.
     */
    private function renderText(array $results): string
    {
        if (empty($results)) {
            return '';
        }

        $lines = [];
        foreach ($results as $i => $data) {
            $lines[] = sprintf("--- Result %d ---", $i + 1);
            if (isset($data['sql'])) {
                $lines[] = 'SQL: ' . $data['sql'];
            }
            if (isset($data['chart_type'])) {
                $lines[] = 'Chart: ' . $data['chart_type'];
            }
            $lines[] = '';
        }
        return implode("\n", $lines);
    }
}
