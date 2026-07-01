<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Sse;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Qscmf\SseCore\Contracts\SseEventHandler;
use Qscmf\SseCore\SseError;
use Qscmf\SseCore\SseEvent;
use Qscmf\SseCore\SsePassthrough;
use Qscmf\SseCore\SseReader;
use Qscmf\SseCore\SseWriter;

/**
 * HTTP fallback for SSE streaming when Unix socket transport fails.
 *
 * Extracted from Chat2VizController to keep the controller under 800 lines.
 */
class GuzzleStreamFallback
{
    private Client $httpClient;
    private string $serviceUrl;
    /** @var callable(string, string): void */
    private $logger;
    /** @var callable(StreamAccumulator, string, SseEvent): void */
    private $eventRouter;

    /**
     * @param callable(string, string): void $logger (tag, detail)
     * @param callable(StreamAccumulator, string, SseEvent): void $eventRouter
     */
    public function __construct(
        ?Client $httpClient,
        string $serviceUrl,
        callable $logger,
        callable $eventRouter
    ) {
        $this->httpClient = $httpClient ?? new Client(['timeout' => 120, 'stream' => true]);
        $this->serviceUrl = $serviceUrl;
        $this->logger = $logger;
        $this->eventRouter = $eventRouter;
    }

    public function __invoke(
        array $payload,
        bool $wantsChat2viz = false,
        string $conversationId = '',
        ?StreamAccumulator $accumulator = null,
        ?int $assistantMessageId = null,
        array $headers = [],
        ?string $dashboardUid = null
    ): bool {
        $timeout = (int) env('CHAT2VIZ_SSE_TIMEOUT', 180);

        // SSE headers may already be set by SseProxy::socket() or need to be
        // sent now.  Either way, all error exits from this method must be SSE
        // events — never ajaxReturn() (which would set application/json).
        SseWriter::sendHeaders();
        SseWriter::clearOutputBuffers();
        SseWriter::applyExecutionGuards();
        $sseWriter = new SseWriter(autoStart: false);

        try {
            $response = $this->httpClient->post(
                $this->serviceUrl . '/api/v1/ask/stream',
                [
                    RequestOptions::JSON    => $payload,
                    RequestOptions::HEADERS => $headers,
                    RequestOptions::STREAM  => true,
                    RequestOptions::TIMEOUT => $timeout,
                ]
            );
        } catch (ConnectException $e) {
            ($this->logger)('stream connect failed', 'ConnectException');
            $sseWriter->sendError('service_unavailable', '分析服务不可用');
            return false;
        } catch (RequestException $e) {
            ($this->logger)('stream request failed', 'RequestException');
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        } catch (GuzzleException $e) {
            ($this->logger)('stream guzzle error', 'GuzzleException');
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            ($this->logger)('stream non-200', sprintf('status=%d', $response->getStatusCode()));
            $sseWriter->sendError('service_error', '分析服务请求失败');
            return false;
        }

        $eventRouter = $this->eventRouter;
        $acc = $accumulator;
        $convId = $conversationId;

        if ($wantsChat2viz) {
            $transformer = new Nl2sqlEventTransformer($conversationId, $dashboardUid);
            $handler = new class($transformer, $sseWriter, $acc, $convId, $eventRouter) implements SseEventHandler {
                public function __construct(
                    private Nl2sqlEventTransformer $transformer,
                    private SseWriter $writer,
                    private ?StreamAccumulator $accumulator,
                    private string $conversationId,
                    /** @var callable(StreamAccumulator, string, SseEvent): void */
                    private $eventRouter
                ) {}

                public function onEvent(SseEvent $event): void
                {
                    if (connection_aborted()) {
                        return;
                    }
                    foreach ($this->transformer->transform($event) as $mapped) {
                        if ($this->accumulator !== null) {
                            ($this->eventRouter)(
                                $this->accumulator,
                                $this->conversationId,
                                $mapped
                            );
                        }
                        $this->writer->sendEvent($mapped);
                    }
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
        } else {
            $passthrough = new SsePassthrough($sseWriter);
            $handler = new class($passthrough, $sseWriter) implements SseEventHandler {
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
        }

        $body = $response->getBody();
        (new SseReader($body))->consume($handler);
        return true;
    }
}
