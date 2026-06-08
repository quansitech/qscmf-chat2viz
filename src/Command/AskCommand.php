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
                    'question'        => $this->argument('question'),
                    'conversation_id' => $this->option('conversation-id'),
                ],
                'auth' => ['api_key' => env('CHAT2VIZ_API_KEY')],
            ]) as $frame) {
                if (($frame['type'] ?? '') === 'chart_ready') {
                    $results[] = $frame['data'];
                }
            }
        } catch (\RuntimeException $e) {
            $this->error('Socket 服务不可用: ' . $e->getMessage());
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
