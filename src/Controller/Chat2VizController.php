<?php

namespace Qscmf\Chat2Viz\Controller;

use Gy_Library\GyController;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Qscmf\Lib\Inertia\Inertia;
use Qscmf\SseCore\Contracts\SseEventHandler;
use Qscmf\SseCore\SseError;
use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SsePassthrough;
use Qscmf\SseCore\SseReader;
use Qscmf\SseCore\SseWriter;

class Chat2VizController extends GyController
{
    protected string $serviceUrl;
    protected string $apiKey;
    protected Client $httpClient;

    protected function _initialize()
    {
        parent::_initialize();

        $this->serviceUrl = rtrim((string) env('CHAT2VIZ_SERVICE_URL', ''), '/');
        $this->apiKey = (string) env('CHAT2VIZ_API_KEY', '');
        $this->httpClient = new Client(['timeout' => 60]);
    }

    public function index()
    {
        Inertia::render('Chat2viz/Index', ['meta_title' => '智能分析']);
    }

    public function api_ask()
    {
        $input = $this->parseInput();
        $validation = $this->validateParsedInput($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $headers = $this->buildHeaders();

        try {
            $response = $this->httpClient->post($this->serviceUrl . '/api/v1/ask', [
                'json' => $payload,
                'headers' => $headers,
            ]);

            $body = (string) $response->getBody();
            $serviceRaw = json_decode($body, true);
            if (!is_array($serviceRaw)) {
                $this->ajaxReturn(['status' => 0, 'info' => '分析服务返回格式错误']);
                return;
            }

            $this->ajaxReturn($this->adapt($serviceRaw));
        } catch (ConnectException $e) {
            $this->logError('connect failed', sprintf('question_len=%d | err=%s', mb_strlen($payload['question'] ?? ''), $e->getMessage()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务不可用']);
        } catch (RequestException $e) {
            $code = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $this->logError('request failed', sprintf('http_code=%d | err=%s | body=%s', $code, $e->getMessage(), $body));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
        } catch (GuzzleException $e) {
            $this->logError('guzzle error', sprintf('err=%s', $e->getMessage()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
        }
    }

    public function api_ask_stream()
    {
        $input = $this->parseInput();
        $validation = $this->validateParsedInput($input);
        if ($validation !== null) {
            $this->ajaxReturn($validation);
            return;
        }

        $payload = $this->buildPayloadFromParsed($input);
        $headers = $this->buildHeaders();

        try {
            $response = $this->httpClient->post(
                $this->serviceUrl . '/api/v1/ask/stream',
                [
                    RequestOptions::JSON    => $payload,
                    RequestOptions::HEADERS => $headers,
                    RequestOptions::STREAM  => true,
                    RequestOptions::TIMEOUT => 120,
                ]
            );
        } catch (ConnectException $e) {
            $this->logError('stream connect failed', 'ConnectException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务不可用']);
            return;
        } catch (RequestException $e) {
            $this->logError('stream request failed', 'RequestException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return;
        } catch (GuzzleException $e) {
            $this->logError('stream guzzle error', 'GuzzleException');
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return;
        }

        if ($response->getStatusCode() !== 200) {
            $this->logError('stream non-200', sprintf('status=%d', $response->getStatusCode()));
            $this->ajaxReturn(['status' => 0, 'info' => '分析服务请求失败']);
            return;
        }

        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();

        $writer = new SseWriter(autoStart: false);
        $passthrough = new SsePassthrough($writer);

        $handler = new class($passthrough, $writer) implements SseEventHandler {
            public function __construct(
                private SsePassthrough $passthrough,
                private SseWriter $writer,
            ) {}

            public function onEvent(SseEvent $event): void
            {
                if (connection_aborted()) {
                    return;
                }
                $this->passthrough->onEvent($event);
            }

            public function onError(SseError $error): void
            {
                $errorEvent = SseEvent::fromRaw(
                    "event: error\ndata: " . json_encode([
                        'type' => 'upstream_disconnected',
                        'info' => "分析服务连接中断",
                    ], JSON_UNESCAPED_UNICODE)
                );
                if ($errorEvent !== null) {
                    $this->writer->sendEvent($errorEvent);
                }
            }

            public function onComplete(): void
            {
                // No-op: do not emit any completion event to the browser.
            }
        };

        $body = $response->getBody();
        (new SseReader($body))->consume($handler);
    }

    private function parseInput(): ?array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($contentType, 'application/json') === false) {
            return null;
        }
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function validateParsedInput(?array $input): ?array
    {
        if (!is_array($input)) {
            $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
            if (stripos($contentType, 'application/json') === false) {
                return ['status' => 0, 'info' => '请求格式不支持'];
            }
            return ['status' => 0, 'info' => '请求格式错误'];
        }

        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            return ['status' => 0, 'info' => '请输入问题'];
        }

        if (mb_strlen($question) > 1000) {
            return ['status' => 0, 'info' => '问题长度不能超过1000字'];
        }

        if ($this->serviceUrl === '') {
            return ['status' => 0, 'info' => '分析服务未配置'];
        }

        $conversationId = $input['conversation_id'] ?? null;
        if ($conversationId !== null && !preg_match('/^[a-f0-9\-]{1,64}$/i', (string) $conversationId)) {
            return ['status' => 0, 'info' => '无效的会话ID'];
        }

        return null;
    }

    private function buildPayloadFromParsed(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        $conversationId = $input['conversation_id'] ?? null;

        $payload = ['question' => $question];
        if (is_string($conversationId) && $conversationId !== '') {
            $payload['conversation_id'] = $conversationId;
        }

        return $payload;
    }

    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }

        return $headers;
    }

    private function adapt(array $raw): array
    {
        if (!empty($raw['success'])) {
            return [
                'status' => 1,
                'data' => [
                    'answer'          => $raw['answer'] ?? '',
                    'sql'             => $raw['sql'] ?? '',
                    'g2_spec'         => $raw['g2_spec'] ?? null,
                    'conversation_id' => $raw['conversation_id'] ?? null,
                ],
            ];
        }

        return [
            'status' => 0,
            'info'   => $raw['error'] ?? '分析服务请求失败',
        ];
    }

    private function logError(string $tag, string $detail): void
    {
        \Think\Log::write(sprintf('[chat2viz] %s | %s', $tag, $detail), \Think\Log::ERR);
    }
}
