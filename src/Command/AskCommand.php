<?php

namespace Qscmf\Chat2Viz\Command;

use Qscmf\SseCore\SocketTransport;

class AskCommand extends \Illuminate\Console\Command
{
    protected $signature = 'chat2viz:ask
        {question : Natural language question}
        {--format=json : Output format (json|table|text)}
        {--conversation-id= : Continue existing conversation}';

    protected $description = 'Ask a question via Socket transport';

    public function handle()
    {
        $apiKey = env('CHAT2VIZ_API_KEY');
        if (empty($apiKey) || !is_string($apiKey) || trim($apiKey) === '') {
            $this->error('CHAT2VIZ_API_KEY 未配置。请在 .env 中设置有效的 API Key。');
            return 1;
        }

        $question = $this->argument('question');
        if (!is_string($question) || trim($question) === '') {
            $this->error('问题不能为空。');
            return 1;
        }
        if (mb_strlen($question) > 1000) {
            $this->error('问题长度不能超过1000字。');
            return 1;
        }

        $conversationId = $this->option('conversation-id');
        if ($conversationId !== null && !preg_match('/^[a-f0-9\-]{1,64}$/i', (string) $conversationId)) {
            $this->error('无效的会话ID格式。');
            return 1;
        }

        try {
            $transport = new SocketTransport([
                'socket_path' => env('CHAT2VIZ_SOCKET_PATH', '/run/chat2viz.sock'),
                'timeout'     => (int) env('CHAT2VIZ_SSE_TIMEOUT', 180),
            ]);

            $results = [];
            foreach ($transport->transact([
                'id'     => bin2hex(random_bytes(16)),
                'method' => 'ask_stream',
                'params' => [
                    'question'        => $question,
                    'conversation_id' => $conversationId,
                ],
                'auth' => ['api_key' => $apiKey],
            ]) as $frame) {
                if (($frame['type'] ?? '') === 'WIDGET_DATA_UPDATE') {
                    $results[] = $frame['data'];
                }
            }
        } catch (\Throwable $e) {
            $this->error('执行失败: ' . $e->getMessage());
            $this->line('请检查 CHAT2VIZ_SOCKET_PATH 配置或确认 Python Agent 已启动。');
            return 1;
        }

        switch ($this->option('format')) {
            case 'json':
                $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;
            case 'table':
                $this->renderTable($results);
                break;
            case 'text':
                $this->renderText($results);
                break;
            default:
                $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                break;
        }

        return 0;
    }

    private function renderTable(array $results): void
    {
        if (empty($results)) {
            $this->info('No results.');
            return;
        }

        $rows = [];
        foreach ($results as $i => $data) {
            $rows[] = [
                '#'         => $i + 1,
                'sql'       => isset($data['sql']) ? mb_substr($data['sql'], 0, 80) . (mb_strlen($data['sql']) > 80 ? '...' : '') : '-',
                'chart'     => $data['chart_type'] ?? '-',
            ];
        }

        $this->table(['#', 'sql', 'chart'], $rows);
    }

    private function renderText(array $results): void
    {
        if (empty($results)) {
            $this->info('No results.');
            return;
        }

        foreach ($results as $i => $data) {
            $this->line(sprintf("--- Result %d ---", $i + 1));
            if (isset($data['sql'])) {
                $this->line('SQL: ' . $data['sql']);
            }
            if (isset($data['chart_type'])) {
                $this->line('Chart: ' . $data['chart_type']);
            }
            $this->line('');
        }
    }
}
