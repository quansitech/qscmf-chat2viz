<?php

declare(strict_types=1);

namespace Qscmf\Chat2Viz\Service;

use Qscmf\Chat2Viz\Sse\{
    GuzzleStreamFallback,
    MockStreamEmitter,
    Nl2sqlEventTransformer,
    SseEvent,
    SseProxy,
    SseWriter,
    StreamAccumulator,
};
use Qscmf\Chat2Viz\Transport\SocketTransport;

class StreamService
{
    private string $serviceUrl;
    private string $apiKey;
    /** @var callable */
    private $logger;
    private EventRouter $eventRouter;
    private ConversationService $conversationService;

    /**
     * @param string $serviceUrl Upstream NL2SQL service URL
     * @param string $apiKey API key for upstream service
     * @param callable $logger function(string $tag, string $detail): void
     * @param EventRouter $eventRouter Event routing instance
     * @param ConversationService $conversationService Conversation lifecycle manager
     */
    public function __construct(
        string $serviceUrl,
        string $apiKey,
        callable $logger,
        EventRouter $eventRouter,
        ConversationService $conversationService
    ) {
        $this->serviceUrl = $serviceUrl;
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->eventRouter = $eventRouter;
        $this->conversationService = $conversationService;
    }

    /**
     * Dispatch the stream via Unix socket with HTTP fallback.
     */
    public function dispatchStream(
        SocketTransport $transport,
        array $payload,
        bool $wants_chat2viz,
        string $conversation_id,
        StreamAccumulator $accumulator,
        ?int $assistant_message_id
    ): void {
        try {
            $requestFrame = [
                'id'     => bin2hex(random_bytes(16)),
                'method' => 'ask_stream',
                'params' => $payload,
                'auth'   => ['api_key' => $this->apiKey],
            ];

            if ($wants_chat2viz) {
                $transformer = new Nl2sqlEventTransformer($conversation_id);
                SseProxy::socket($transport, $requestFrame, function (array $frame) use ($transformer, $accumulator, $conversation_id): ?SseEvent {
                    $type = $frame['type'] ?? 'message';
                    if ($type === 'ping' || $type === 'pong') {
                        return null;
                    }
                    $input = new SseEvent(
                        type: $type,
                        data: $frame['data'] ?? [],
                        raw: '',
                    );
                    if ($input->isComment()) {
                        return $input;
                    }
                    $outputs = $transformer->transform($input);
                    foreach ($outputs as $mapped) {
                        $this->eventRouter->routeEvent($accumulator, $conversation_id, $mapped);
                    }
                    return $outputs[0] ?? null;
                });
            } else {
                SseProxy::socket($transport, $requestFrame);
            }

            $this->conversationService->finalizeStream(
                $accumulator, $conversation_id, $assistant_message_id, 'complete'
            );
        } catch (\Throwable $e) {
            ($this->logger)('socket failed, falling back to http', $e->getMessage());

            if (headers_sent()) {
                $this->conversationService->finalizeStream(
                    $accumulator, $conversation_id, $assistant_message_id, 'interrupted'
                );
                $writer = new SseWriter(autoStart: false);
                $writer->sendError('socket_fallback_failed', '分析服务连接失败');
                return;
            }

            $stream_completed = $this->fallbackToHttp(
                $payload, $wants_chat2viz, $conversation_id, $accumulator, $assistant_message_id
            );

            $this->conversationService->finalizeStream(
                $accumulator,
                $conversation_id,
                $assistant_message_id,
                $stream_completed ? 'complete' : 'interrupted'
            );
        }
    }

    public function fallbackToHttp(
        array $payload,
        bool $wants_chat2viz = false,
        string $conversation_id = '',
        ?StreamAccumulator $accumulator = null,
        ?int $assistant_message_id = null
    ): bool {
        $fallback = new GuzzleStreamFallback(
            null, // http_client passed from caller context
            $this->serviceUrl,
            $this->logger,
            function (StreamAccumulator $acc, string $convId, SseEvent $evt): void {
                $this->eventRouter->routeEvent($acc, $convId, $evt);
            }
        );

        return $fallback($payload, $wants_chat2viz, $conversation_id, $accumulator, $assistant_message_id, $this->buildHeaders());
    }

    public function handleMockStream(MockStreamEmitter $emitter): void
    {
        $emitter->emit();
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['X-API-Key'] = $this->apiKey;
        }
        return $headers;
    }
}
