<?php
declare(strict_types=1);
namespace Qscmf\Chat2Viz\Sse;

use Qscmf\SseCore\SseEvent;

class Nl2sqlEventTransformer
{
    /** @var callable|null */
    private $logger;

    /**
     * @param string      $phpConversationId The BIGINT conversation id emitted on
     *                                       the conversation_id SSE frame.
     * @param string|null $dashboardUid      conversation-one-to-one-and-first-msg-init:
     *                                       when non-empty (first message, backend
     *                                       just created the dashboard), it is
     *                                       appended to the conversation_id frame so
     *                                       the frontend can switch to edit mode.
     * @param callable|null $logger
     */
    public function __construct(
        private string $phpConversationId,
        private ?string $dashboardUid = null,
        ?callable $logger = null
    ) {
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
            'tool_start', 'tool_result' => [
                // GAP-4 / declarative-frontend-adapter: tool_start/tool_result
                // pass through verbatim (contract §5 base event names). The legacy
                // rename to action_call/action_call_result is removed — the
                // frontend consumes the contract names directly. No field
                // rename, no parse.
                new SseEvent(type: $event->type, data: $event->data, raw: $event->raw),
            ],
            'error' => $this->mapError($event),
            // declarative-frontend-adapter: DASHBOARD_REPLACE is the single
            // whole-tree delivery event (generate + modify unified, contract §2).
            // 1:1 verbatim passthrough — no rename, no field add/remove, no
            // parse, no recomputation of truncated/total (Python is the sole
            // computation authority). data:null slim widgets and status=error
            // widgets are forwarded as-is; the frontend is the cache-reuse /
            // local-degradation boundary, NOT this passthrough layer.
            'DASHBOARD_REPLACE',
            // WIDGET_ERROR is retained for mid-stream single-widget local
            // degradation (contract §3). 1:1 verbatim passthrough.
            'WIDGET_ERROR',
            // Dashboard lifecycle notices (non-critical UI hints).
            'dashboard_notice' => [
                new SseEvent(type: $event->type, data: $event->data, raw: $event->raw),
            ],
            // Skip these events (return empty array)
            'content_block_start', 'content_block_stop', 'message_delta' => [],
            // Unknown event types: log a warning and pass through (do not silently drop).
            // Protects future/unknown events so the frontend actually receives them.
            // NOTE (declarative-frontend-adapter): the deprecated events
            // DASHBOARD_INIT / WIDGET_DATA_UPDATE / dashboard_patch /
            // WIDGET_UPDATE / WIDGET_REMOVE / dashboard_rollback / sql_ready /
            // data_ready / action_call / action_call_result have NO explicit
            // case (contract §4 — Python never emits them under the whole-tree
            // protocol). Any stray emission falls through here, is logged, and
            // is forwarded as-is rather than silently dropped or renamed.
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

    // message_start → conversation_id — PHP is the sole authority; Python's ID
    // is silently discarded. On the first message (backend initialized the
    // dashboard) the frame also carries `uid` so the frontend can adopt the new
    // dashboard and switch the URL to edit mode. Subsequent turns omit `uid`.
    private function mapMessageStart(SseEvent $event): array
    {
        $cid = $this->phpConversationId;
        if ($cid === '') {
            return [];
        }
        $data = ['conversation_id' => $cid];
        if ($this->dashboardUid !== null && $this->dashboardUid !== '') {
            $data['uid'] = $this->dashboardUid;
        }
        return [new SseEvent(type: 'conversation_id', data: $data, raw: '')];
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
