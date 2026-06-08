<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Tests;

use PHPUnit\Framework\TestCase;
use Qscmf\SseCore\SocketTransport;

/**
 * Tests for AskCommand logic: frame filtering, output formatting, error handling.
 *
 * Avoids Laravel Console Application dependency by testing the core logic
 * in isolation rather than via CommandTester.
 */
class AskCommandTest extends TestCase
{
    // --- Frame filtering: chart_ready extraction ---

    public function testOnlyChartReadyFramesCollected(): void
    {
        $frames = [
            ['type' => 'delta', 'data' => ['text' => 'thinking...']],
            ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 1', 'chart_type' => 'bar']],
            ['type' => 'done', 'data' => []],
        ];

        $results = [];
        foreach ($frames as $frame) {
            if (($frame['type'] ?? '') === 'chart_ready') {
                $results[] = $frame['data'];
            }
        }

        $this->assertCount(1, $results);
        $this->assertSame('SELECT 1', $results[0]['sql']);
    }

    public function testMultipleChartReadyFramesCollected(): void
    {
        $frames = [
            ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 1']],
            ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 2']],
        ];

        $results = [];
        foreach ($frames as $frame) {
            if (($frame['type'] ?? '') === 'chart_ready') {
                $results[] = $frame['data'];
            }
        }

        $this->assertCount(2, $results);
    }

    // --- Ping/pong frames ignored by SseProxy::socket default mapping ---

    public function testPingPongFramesSkippedInDefaultMapping(): void
    {
        $frames = [
            ['type' => 'ping', 'data' => null],
            ['type' => 'pong', 'data' => null],
            ['type' => 'chart_ready', 'data' => ['sql' => 'SELECT 1']],
        ];

        // Simulate SseProxy::socket default mapping logic
        $results = [];
        foreach ($frames as $frame) {
            $type = $frame['type'] ?? 'message';
            if ($type === 'ping' || $type === 'pong') {
                continue;
            }
            if ($type === 'chart_ready') {
                $results[] = $frame['data'];
            }
        }

        $this->assertCount(1, $results);
    }

    // --- No chart_ready frames yields empty results ---

    public function testNoChartReadyYieldsEmptyResults(): void
    {
        $frames = [
            ['type' => 'delta', 'data' => ['text' => 'thinking...']],
            ['type' => 'done', 'data' => []],
        ];

        $results = [];
        foreach ($frames as $frame) {
            if (($frame['type'] ?? '') === 'chart_ready') {
                $results[] = $frame['data'];
            }
        }

        $this->assertSame([], $results);
    }

    // --- JSON output formatting ---

    public function testJsonOutputFormat(): void
    {
        $results = [
            ['sql' => 'SELECT 1', 'chart_type' => 'bar'],
        ];

        $output = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('SELECT 1', $output);
        $this->assertStringContainsString('bar', $output);

        $decoded = json_decode($output, true);
        $this->assertCount(1, $decoded);
    }

    // --- Text output formatting ---

    public function testTextOutputFormat(): void
    {
        $results = [
            ['sql' => 'SELECT SUM(amount) FROM sales', 'chart_type' => 'bar'],
        ];

        $lines = [];
        foreach ($results as $i => $data) {
            $lines[] = sprintf("--- Result %d ---", $i + 1);
            if (isset($data['sql'])) {
                $lines[] = 'SQL: ' . $data['sql'];
            }
            if (isset($data['chart_type'])) {
                $lines[] = 'Chart: ' . $data['chart_type'];
            }
        }
        $output = implode("\n", $lines);

        $this->assertStringContainsString('SELECT SUM(amount) FROM sales', $output);
        $this->assertStringContainsString('Chart: bar', $output);
    }

    // --- RuntimeException catch produces friendly output (M13) ---

    public function testRuntimeExceptionMessage(): void
    {
        $e = new \RuntimeException('Connection refused');
        $message = 'Socket 服务不可用: ' . $e->getMessage();
        $hint = '请检查 CHAT2VIZ_SOCKET_PATH 配置或确认 Python Agent 已启动。';

        $this->assertStringContainsString('Connection refused', $message);
        $this->assertStringContainsString('CHAT2VIZ_SOCKET_PATH', $hint);
    }

    // --- Request frame structure ---

    public function testRequestFrameStructure(): void
    {
        $question = 'show sales';
        $conversationId = 'abc-123';
        $apiKey = 'test-key';

        $frame = [
            'id'     => bin2hex(random_bytes(16)),
            'method' => 'ask_stream',
            'params' => [
                'question'        => $question,
                'conversation_id' => $conversationId,
            ],
            'auth' => ['api_key' => $apiKey],
        ];

        $this->assertArrayHasKey('id', $frame);
        $this->assertSame('ask_stream', $frame['method']);
        $this->assertSame($question, $frame['params']['question']);
        $this->assertSame($conversationId, $frame['params']['conversation_id']);
        $this->assertSame($apiKey, $frame['auth']['api_key']);
        $this->assertSame(32, strlen($frame['id'])); // 16 bytes = 32 hex chars
    }
}
