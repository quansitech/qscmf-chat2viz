<?php
/**
 * Standalone SSE test endpoint - bypasses all controller caching.
 * Access: /extends/Chat2Viz/sse_test
 */
namespace Qscmf\Chat2Viz\Controller;

use Gy_Library\GyController;

class SseTestController extends GyController
{
    public function index()
    {
        // Environment guard: only available in debug mode
        if (env('APP_DEBUG') !== true) {
            http_response_code(404);
            echo json_encode(['status' => 0, 'info' => 'Not Found']);
            return;
        }

        // API key from environment — reject if not configured
        $apiKey = env('CHAT2VIZ_TEST_API_KEY', '');
        if ($apiKey === '') {
            http_response_code(403);
            echo json_encode(['status' => 0, 'info' => 'Test endpoint not configured']);
            return;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $question = $input['question'] ?? 'no question';
        $hasCtx = isset($input['dashboard_context']) ? 'yes' : 'no';

        // Test 1: just echo SSE
        echo "event: debug\ndata: " . json_encode(['question' => $question, 'has_ctx' => $hasCtx]) . "\n\n";
        flush();

        // Test 2: try socket
        try {
            $sock = @stream_socket_client("unix:///run/chat2viz.sock", $errno, $errstr, 5);
            if (!$sock) {
                echo "event: debug\ndata: " . json_encode(['socket' => 'FAILED', 'error' => $errstr]) . "\n\n";
                flush();
                return;
            }

            echo "event: debug\ndata: " . json_encode(['socket' => 'CONNECTED']) . "\n\n";
            flush();

            $frame = json_encode([
                'id' => bin2hex(random_bytes(8)),
                'method' => 'ask_stream',
                'params' => [
                    'question' => $question,
                    'dashboard_context' => $input['dashboard_context'] ?? null,
                ],
                'auth' => ['api_key' => $apiKey],
            ]);

            $len = pack("N", strlen($frame));
            fwrite($sock, $len . $frame);
            stream_set_timeout($sock, 30);

            // Read and forward frames
            $count = 0;
            while ($count < 20) {
                $lenBuf = fread($sock, 4);
                if (strlen($lenBuf) < 4) break;
                $respLen = unpack("N", $lenBuf)[1];
                $resp = fread($sock, $respLen);
                $data = json_decode($resp, true);

                echo "event: frame\ndata: " . json_encode($data) . "\n\n";
                flush();
                $count++;

                if (($data['type'] ?? '') === 'message_stop') break;
            }
            fclose($sock);

            echo "event: debug\ndata: " . json_encode(['done' => true, 'frames' => $count]) . "\n\n";
            flush();
        } catch (\Throwable $e) {
            echo "event: error\ndata: " . json_encode(['class' => get_class($e), 'msg' => $e->getMessage()]) . "\n\n";
            flush();
        }
    }
}
