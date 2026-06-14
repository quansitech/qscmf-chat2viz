<?php
declare(strict_types=1);
namespace Qscmf\Chat2Viz\Sse;

use Qscmf\SseCore\SseEvent;

class Nl2sqlEventTransformer
{
    /** @var callable|null */
    private $logger;

    public function __construct(private string $phpConversationId, ?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Transform a Python NL2SQL SSE event into chat2viz format events.
     * All mappings are strictly 1:1 or 1:0 (skip).
     *
     * @return SseEvent[]  Zero or one event (never more).
     */
    public function transform(SseEvent $event): array
    {
        // Comment/heartbeat events pass through unchanged
        if ($event->isComment()) {
            return [$event];
        }

        return match($event->type) {
            'message_start' => $this->mapMessageStart($event),
            'content_block_delta' => $this->mapContentBlockDelta($event),
            'message_stop' => [new SseEvent(type: 'done', data: [], raw: '')],
            'tool_start' => $this->mapToolStart($event),
            'tool_result' => $this->mapToolResult($event),
            'sql_ready' => [new SseEvent(type: 'sql_generated', data: ['sql' => $event->data['sql'] ?? ''], raw: '')],
            'data_ready' => [new SseEvent(type: 'data_preview', data: $event->data, raw: '')],
            'error' => $this->mapError($event),
            'dashboard_patch' => [new SseEvent(type: 'dashboard_patch', data: $event->data, raw: '')],
            // multi-widget three events: 1:1 passthrough (no rename, no field add/remove,
            // no parse, no recomputation of truncated/total)
            'DASHBOARD_INIT', 'WIDGET_DATA_UPDATE', 'WIDGET_ERROR' => [
                new SseEvent(type: $event->type, data: $event->data, raw: $event->raw),
            ],
            // Skip these events (return empty array)
            'content_block_start', 'content_block_stop', 'message_delta' => [],
            // Unknown event types: log a warning and pass through (do not silently drop).
            // Protects future/unknown events so the frontend actually receives them.
            default => $this->mapDefaultPassthrough($event),
        };
    }

    /**
     * Default branch: log a warning naming the event type, then pass it through
     * unchanged. Only reaches here for events with no explicit case and not in
     * the explicit skip list.
     *
     * @return SseEvent[]
     */
    private function mapDefaultPassthrough(SseEvent $event): array
    {
        if ($this->logger !== null) {
            ($this->logger)('warning', sprintf(
                '[chat2viz] Nl2sqlEventTransformer: passthrough unknown event type "%s"',
                $event->type,
            ));
        }
        return [new SseEvent(type: $event->type, data: $event->data, raw: $event->raw)];
    }

    // message_start → conversation_id — PHP is the sole authority; Python's ID is silently discarded
    private function mapMessageStart(SseEvent $event): array
    {
        $cid = $this->phpConversationId;
        if ($cid === '') {
            return [];
        }
        return [new SseEvent(type: 'conversation_id', data: ['conversation_id' => $cid], raw: '')];
    }

    // content_block_delta → answer (only if delta.text is non-empty)
    private function mapContentBlockDelta(SseEvent $event): array
    {
        $text = $event->data['delta']['text'] ?? '';
        if ($text === '') {
            return [];
        }
        return [new SseEvent(type: 'answer', data: ['text' => $text], raw: '')];
    }

    // tool_start → action_call (tool_name→action_type, tool_args→params)
    private function mapToolStart(SseEvent $event): array
    {
        return [new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => $event->data['tool_name'] ?? '',
                'params' => $event->data['tool_args'] ?? [],
            ],
            raw: '',
        )];
    }

    // tool_result → action_call_result (summary→result, success: true)
    private function mapToolResult(SseEvent $event): array
    {
        return [new SseEvent(
            type: 'action_call_result',
            data: [
                'success' => true,
                'result' => $event->data['summary'] ?? '',
            ],
            raw: '',
        )];
    }

    // error → error (extract message → info; supports 3 formats)
    private function mapError(SseEvent $event): array
    {
        $error = $event->data['error'] ?? null;
        if ($error === null) {
            $info = '未知错误';
        } elseif (is_string($error)) {
            $info = $error;
        } elseif (is_array($error) && isset($error['message'])) {
            $info = $error['message'];
            // Translate the upstream's generic INTERNAL_ERROR to something
            // actionable for the user.  This is a mid-stream failure inside
            // the Python agent (e.g. LLM provider hiccup, tool error); the
            // user can simply retry.
            if (($error['code'] ?? '') === 'INTERNAL_ERROR') {
                $info = '生成过程出错，请稍后重试';
            }
        } else {
            $info = '未知错误';
        }
        return [new SseEvent(type: 'error', data: ['info' => $info], raw: '')];
    }
}
