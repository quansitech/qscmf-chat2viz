<?php
declare(strict_types=1);
namespace Qscmf\Chat2Viz\Sse;

use Qscmf\SseCore\SseEvent;

class Nl2sqlEventTransformer
{
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
            'chart_ready' => $this->mapChartReady($event),
            'message_stop' => [new SseEvent(type: 'done', data: [], raw: '')],
            'tool_start' => $this->mapToolStart($event),
            'tool_result' => $this->mapToolResult($event),
            'sql_ready' => [new SseEvent(type: 'sql_generated', data: ['sql' => $event->data['sql'] ?? ''], raw: '')],
            'data_ready' => [new SseEvent(type: 'data_preview', data: $event->data, raw: '')],
            'error' => $this->mapError($event),
            // Skip these events (return empty array)
            'content_block_start', 'content_block_stop', 'message_delta' => [],
            // Unknown event types are silently dropped
            default => [],
        };
    }

    // message_start → conversation_id (only if conversation_id exists in data)
    private function mapMessageStart(SseEvent $event): array
    {
        $cid = $event->data['conversation_id'] ?? null;
        if ($cid === null || $cid === '') {
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

    // chart_ready → chart_ready (passthrough + auto-generate id if missing)
    private function mapChartReady(SseEvent $event): array
    {
        $data = $event->data;
        if (!isset($data['id']) || $data['id'] === '') {
            $data['id'] = bin2hex(random_bytes(8));
        }
        return [new SseEvent(type: 'chart_ready', data: $data, raw: '')];
    }

    // tool_start → action_call (tool→action_type, args→params)
    private function mapToolStart(SseEvent $event): array
    {
        return [new SseEvent(
            type: 'action_call',
            data: [
                'action_type' => $event->data['tool'] ?? '',
                'params' => $event->data['args'] ?? [],
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
        } else {
            $info = '未知错误';
        }
        return [new SseEvent(type: 'error', data: ['info' => $info], raw: '')];
    }
}
