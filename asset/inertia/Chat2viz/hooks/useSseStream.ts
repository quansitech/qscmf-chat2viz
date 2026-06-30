import { useRef, useCallback } from 'react';
import { useDashboardStore, generateId } from '../store/dashboardStore';
import type { Widget, ActionCall, ActionCallResult } from '../store/dashboardStore';
import { parseSseEvent, type SseEvent } from '../sse-parser';
import { planActionCall, findInProgressToolStep } from '../utils/aiSteps';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const SSE_ENDPOINT = '/extends/Chat2Viz/api_ask_stream';
const MAX_RETRIES = 2;
const RETRY_DELAY_MS = 1000;

/**
 * Stream-level timeout watchdog threshold (contract §6). Fixed constant —
 * MUST NOT be changed by implementors. Only the slowest widget gets a
 * pending→error fallback; the fetch itself is NOT aborted.
 */
const WIDGET_TIMEOUT_MS = 30000;

// ---------------------------------------------------------------------------
// Helpers for extracting typed values from untyped SSE data
// ---------------------------------------------------------------------------

function str(val: unknown, fallback = ''): string {
  return typeof val === 'string' ? val : fallback;
}

function obj<T = Record<string, unknown>>(val: unknown, fallback = {} as T): T {
  return val !== null && typeof val === 'object' && !Array.isArray(val)
    ? (val as T)
    : fallback;
}

// ---------------------------------------------------------------------------
// Per-widget timeout watchdog (time-driven, 30s threshold)
//
// declarative-frontend-adapter: under the whole-tree protocol the
// DASHBOARD_REPLACE frame settles every widget atomically, so the
// per-widget loading-timer map is no longer populated. The watchdog is kept
// as a defense-in-depth safety net (no-op today) alongside
// fallbackLoadingWidgetsToError() on the 'done' event; WIDGET_ERROR (contract
// §3) still drives immediate local degradation.
// ---------------------------------------------------------------------------

const widgetLoadingSince: Map<string, number> = new Map();

function clearWidgetLoading(widgetId: string): void {
  widgetLoadingSince.delete(widgetId);
}

function runWatchdog(): void {
  const now = Date.now();
  const expired: string[] = [];
  for (const [widgetId, since] of widgetLoadingSince) {
    if (now - since >= WIDGET_TIMEOUT_MS) {
      expired.push(widgetId);
    }
  }
  if (expired.length === 0) return;
  const store = useDashboardStore.getState();
  for (const widgetId of expired) {
    clearWidgetLoading(widgetId);
    store.setWidgetError(widgetId);
  }
}

/** Reset all watchdog timers (e.g. at the start of a new stream). */
function resetWatchdog(): void {
  widgetLoadingSince.clear();
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export function useSseStream() {
  const abortRef = useRef<AbortController | null>(null);

  const sendQuestion = useCallback(async (question: string) => {
    const store = useDashboardStore.getState();

    // Cancel any in-flight request
    if (abortRef.current) {
      abortRef.current.abort();
    }

    const controller = new AbortController();
    abortRef.current = controller;

    // Ensure an active conversation exists before starting SSE stream.
    // If conversationId is missing, call api_conversation_history to
    // get/create one for the current dashboard.
    if (!store.conversationId && store.uid) {
      try {
        const resp = await fetch(
          `/extends/Chat2Viz/api_conversation_history?uid=${encodeURIComponent(store.uid)}`,
          { credentials: 'same-origin', signal: controller.signal },
        );
        if (resp.ok) {
          const result = await resp.json();
          if (result.status === 1 && result.data?.conversation_id) {
            useDashboardStore.getState().setConversationId(
              String(result.data.conversation_id),
            );
          }
        }
      } catch (err) {
        // If aborted, exit early
        if (controller.signal.aborted) return;
        // Non-fatal: proceed without conversation_id; backend will create one
      }
    }

    store.startConversation(question);

    // New stream — clear any stale per-widget watchdog timers.
    resetWatchdog();

    // fix-stream-message-persistence Decision 4 (submitted watchdog): if no
    // first frame arrives within 30s (markStreaming never fires), the state
    // would otherwise sit in 'submitted' forever showing the three-dot loader.
    // Flip to an explicit error so the user sees a timeout, not a hang.
    let submittedWatchdog: ReturnType<typeof setTimeout> | null = null;
    const armSubmittedWatchdog = (): void => {
      submittedWatchdog = setTimeout(() => {
        const cur = useDashboardStore.getState();
        if (cur.streamingState === 'submitted') {
          cur.setError('AI 响应超时，未收到任何数据');
        }
      }, 30000);
    };
    const clearSubmittedWatchdog = (): void => {
      if (submittedWatchdog !== null) {
        clearTimeout(submittedWatchdog);
        submittedWatchdog = null;
      }
    };
    armSubmittedWatchdog();

    // conversation-one-to-one: DEF-NEW-1 removed — dashboard_uid and widgets are
    // orthogonal. uid identifies which dashboard; widgets identify edit vs generate
    // mode (the backend has_editable_widgets() guard handles that). Send
    // dashboard_context whenever we have a uid (all turns except the first).
    const dashboardContext = store.getDashboardContext();
    const payload: Record<string, unknown> = { question };
    if (dashboardContext && useDashboardStore.getState().uid) {
      payload.dashboard_context = dashboardContext;
    }

    const conversationId = useDashboardStore.getState().conversationId;
    if (conversationId) {
      payload.conversation_id = conversationId;
    }

    let attempt = 0;

    while (attempt <= MAX_RETRIES) {
      try {
        // task 4.1: connection-level timeout (30s). SSE streams are long-lived,
        // so we only guard the CONNECT phase (headers). Once headers arrive,
        // the watchdog detaches and the stream runs until done/cancel.
        // Implementation: race fetch against a timer that rejects with a plain
        // Error (NOT controller.abort, which would also kill the stream and
        // user-cancel). Manual controller stays reserved for cancel().
        const connectTimeoutMs = 30000;
        let connectTimer: ReturnType<typeof setTimeout> | null = null;
        const connectTimeout = new Promise<never>((_, reject) => {
          connectTimer = setTimeout(
            () => reject(new Error('连接超时，请稍后重试')),
            connectTimeoutMs,
          );
        });

        let response: Response;
        try {
          response = await Promise.race([
            fetch(SSE_ENDPOINT, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {
                'Content-Type': 'application/json',
                'X-Event-Format': 'chat2viz',
              },
              body: JSON.stringify(payload),
              signal: controller.signal,
            }),
            connectTimeout,
          ]);
        } finally {
          if (connectTimer) clearTimeout(connectTimer);
        }

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        if (!response.body) {
          throw new Error('Response body is null; streaming not supported');
        }

        // Validate Content-Type — backend may return JSON error instead of SSE
        const contentType = response.headers.get('Content-Type') ?? '';
        if (!contentType.includes('text/event-stream')) {
          // Attempt to extract error message from JSON body
          const text = await response.text();
          let message = `意外的响应格式 (${contentType || 'unknown'})`;
          try {
            const json = JSON.parse(text) as Record<string, unknown>;
            message = String(json.info || json.message || json.error || message);
          } catch {
            // Not JSON — use the generic message above
          }
          throw new Error(message);
        }

        // Consume the SSE stream
        const streamClean = await consumeStream(response.body, controller.signal);

        // fix-stream-message-persistence: clear the submitted watchdog once the
        // stream has started producing frames (markStreaming already ran) or ended.
        clearSubmittedWatchdog();

        if (streamClean) {
          useDashboardStore.getState().completeConversation();
        } else {
          // fix-stream-message-persistence Decision 5 (two-branch): stream ended
          // without a 'done' event. If the current turn produced partial content,
          // preserve it and settle gently (the user can still read what arrived);
          // if nothing arrived, surface an explicit error instead of silently
          // dropping to idle (which left a blank reply area).
          const s = useDashboardStore.getState();
          const lastAssistant = [...s.messages].reverse().find((m) => m.role === 'assistant');
          if (lastAssistant && lastAssistant.content) {
            s.completeConversation();
          } else {
            s.setError('AI 回复超时或中断，请重试');
          }
        }

        // Auto-save is handled by useDashboardDraft's store subscription,
        // which detects isDirty transitions and triggers debounced saves.

        return;
      } catch (err) {
        // fix-stream-message-persistence: clear the submitted watchdog on any
        // exit path (error/retry/abort) so the timer never fires post-retry.
        clearSubmittedWatchdog();

        // task 4.2: distinguish user-cancel AbortError from other errors.
        // User-initiated cancel (controller.abort) → silent exit.
        // Connect-timeout / network errors → retry then surface.
        if (controller.signal.aborted) {
          return;
        }

        attempt++;

        if (attempt > MAX_RETRIES) {
          const message = err instanceof Error ? err.message : '流式请求失败';
          useDashboardStore.getState().setError(message);
          return;
        }

        // Wait before retrying
        await delay(RETRY_DELAY_MS, controller.signal);
      }
    }
  }, []);

  const cancel = useCallback(() => {
    if (abortRef.current) {
      abortRef.current.abort();
      abortRef.current = null;
      // code-review HIGH-3: 仅当确实处于流式生成(submitted/streaming)且最后一条
      // assistant 仍是 streaming 态时, 才标 interrupted —— 否则会把"错误关闭时
      // 历史里已成功的 assistant 消息"误标成已中断。错误 Alert 关闭也调 cancel,
      // 此时若最后 assistant 已是完成态(idle/无 status), 不应改动它。
      const state = useDashboardStore.getState();
      const lastAssistant = [...state.messages].reverse().find((m) => m.role === 'assistant');
      const inFlight = state.streamingState === 'submitted' || state.streamingState === 'streaming';
      if (inFlight && lastAssistant && (lastAssistant.message_status === 'streaming' || lastAssistant.message_status === undefined)) {
        useDashboardStore.setState((s) => {
          const la = [...s.messages].reverse().find((m) => m.role === 'assistant');
          if (la) la.message_status = 'interrupted';
        });
      }
      useDashboardStore.getState().completeConversation();
    }
  }, []);

  return { sendQuestion, cancel };
}

// ---------------------------------------------------------------------------
// Stream consumer
// ---------------------------------------------------------------------------

async function consumeStream(body: ReadableStream<Uint8Array>, signal: AbortSignal): Promise<boolean> {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let receivedDone = false;

  try {
    while (true) {
      if (signal.aborted) {
        reader.cancel();
        return false;
      }

      const { done, value } = await reader.read();
      if (done) break;

      const chunk = decoder.decode(value, { stream: true });
      buffer += chunk;

      // SSE frames are separated by double newlines.
      const frames = buffer.split('\n\n');
      // Keep the last incomplete frame in the buffer
      buffer = frames.pop() ?? '';

      for (const frame of frames) {
        if (!frame.trim()) continue;
        const event = parseSseEvent(frame);
        if (dispatchEvent(event)) {
          receivedDone = true;
        }
      }
    }

    // Process any remaining data in the buffer
    if (buffer.trim()) {
      const event = parseSseEvent(buffer);
      if (dispatchEvent(event)) {
        receivedDone = true;
      }
    }
  } finally {
    reader.releaseLock();
  }

  return receivedDone;
}

// ---------------------------------------------------------------------------
// Event dispatch
//
// declarative-frontend-adapter: the store is a render-only cache. The whole
// tree arrives in a single DASHBOARD_REPLACE frame; there are no reducers
// (applyPatches / deepSetByPath / deepDeleteByPath). tool_start / tool_result
// are the contract §5 tool-progress events (the legacy action_call /
// action_call_result rename was removed — useSseStream consumes the base
// names directly).
// ---------------------------------------------------------------------------

function dispatchEvent(event: SseEvent | null): boolean {
  if (!event) return false;

  const store = useDashboardStore.getState();

  switch (event.type) {
    case 'DASHBOARD_REPLACE': {
      // Whole-tree replace (contract §2). The store action clears the old
      // widgets and rebuilds from the payload, reusing cached data for any
      // widget whose data is null (slim). MUST NOT set global store.error.
      const widgetsField = event.data.widgets;
      const widgetMap = (widgetsField !== null && typeof widgetsField === 'object' && !Array.isArray(widgetsField))
        ? widgetsField as Record<string, Record<string, unknown>>
        : {};

      const layoutField = event.data.layout;
      const layout = Array.isArray(layoutField)
        ? layoutField as Array<{ i: string; x: number; y: number; w: number; h: number }>
        : [];

      useDashboardStore.getState().replaceDashboard(layout, widgetMap);

      // First real frame — flip 'submitted' → 'streaming'.
      useDashboardStore.getState().markStreaming();

      // The DASHBOARD_REPLACE frame also carries the LLM answer text (contract
      // §2 answer field). Apply it as the assistant message content.
      const answer = str(event.data.answer);
      if (answer !== '') {
        useDashboardStore.getState().appendAnswer(answer);
      }

      runWatchdog();
      break;
    }

    case 'WIDGET_ERROR': {
      const widgetId = str(event.data.widget_id) || str((event.data as { id?: string }).id);
      if (widgetId) {
        // Local degradation: only this widget → error. MUST NOT set store.error.
        useDashboardStore.getState().setWidgetError(widgetId);
        clearWidgetLoading(widgetId);
      }
      runWatchdog();
      break;
    }

    case 'tool_start': {
      // GAP-4 / declarative-frontend-adapter: tool_start replaces the legacy
      // action_call. tool_name → action_type mapping is gone (contract §5
      // consumes the base names); we drive the AI-step indicator from
      // tool_name directly.
      const toolName = str(event.data.tool_name);
      const action: ActionCall = {
        action_type: toolName,
        params: obj<Record<string, unknown>>(event.data.tool_args),
      };
      store.executeAction(action);
      const toolLabel = getToolLabel(toolName);
      useDashboardStore.getState().markStreaming();
      // De-duplicate tool steps so the indicator shows a single processing row
      // instead of appending a new badge per tool_start event.
      const decision = planActionCall(
        useDashboardStore.getState().aiSteps,
        toolName,
        toolLabel,
        () => ({
          id: generateStepId(),
          type: 'tool_start' as const,
          label: toolLabel,
          timestamp: generateId(),
          completed: false,
        }),
      );
      const st = useDashboardStore.getState();
      if (decision.kind === 'refresh') {
        st.refreshAiStep(decision.id);
      } else {
        if (decision.kind === 'completeAndAdd' && decision.completeId) {
          st.completeAiStep(decision.completeId);
        }
        st.addAiStep(decision.step);
      }
      break;
    }

    case 'tool_result': {
      // GAP-4: tool_result replaces the legacy action_call_result. The
      // contract frame carries {success, summary}; map onto the metadata
      // result record. DEF-07 structured failure fields (error_code/error)
      // are forwarded verbatim when present.
      const success = event.data.success !== false;
      const result: ActionCallResult = {
        success,
        result: 'summary' in event.data ? event.data.summary : event.data.result,
        error_code: str(event.data.error_code) || undefined,
        error: str(event.data.error) || undefined,
      };
      applyActionResult(result);
      const s = useDashboardStore.getState();
      if (success) {
        const inProgress = findInProgressToolStep(s.aiSteps);
        if (inProgress) {
          s.completeAiStep(inProgress.id);
        }
      } else {
        useDashboardStore.setState((state) => {
          const lastAssistant = [...state.messages]
            .reverse()
            .find((m) => m.role === 'assistant');
          if (lastAssistant) {
            lastAssistant.message_status = 'failed';
          }
        });
      }
      break;
    }

    case 'answer': {
      // First real frame — flip 'submitted' → 'streaming'.
      useDashboardStore.getState().markStreaming();
      store.appendAnswer(str(event.data.text));
      break;
    }

    case 'reasoning': {
      // thought/answer split: ReAct 推理过程(如"先查一下表结构")累积到
      // message.thought,供可折叠"AI 思考过程"面板展示。不影响 answer 通道。
      // markStreaming 让 submitted watchdog 在首帧 reasoning 时就清除(不再超时)。
      useDashboardStore.getState().markStreaming();
      store.appendThought(str(event.data.text));
      break;
    }

    case 'conversation_id': {
      store.setConversationId(str(event.data.conversation_id));
      if (event.data.uid) {
        // conversation-one-to-one: first message — backend created the dashboard,
        // surface the uid here so the frontend state + URL are consistent.
        useDashboardStore.setState({ uid: str(event.data.uid) });
        // Replace add.html URL with edit?uid=<uid> without triggering navigation
        if (!window.location.pathname.includes('/edit')) {
          const newUrl = window.location.pathname.replace('/add', '/edit')
            + '?uid=' + encodeURIComponent(str(event.data.uid));
          window.history.replaceState({}, '', newUrl);
        }
      }
      break;
    }

    case 'error': {
      {
        // Support multiple error formats:
        // 1. Nl2sqlEventTransformer: {info: '...'}
        // 2. SseWriter::sendError: {type:'error', error:{code:'...', message:'...'}}
        // 3. Simple: {message: '...'}
        const info = str(event.data.info);
        const message = str(event.data.message);
        const nestedError = obj<{ message?: string }>(event.data.error);
        const nestedMsg = str(nestedError.message);
        store.setError(info || message || nestedMsg || '未知错误');
      }
      break;
    }

    case 'done': {
      // Event-driven cleanup: widgets still loading at stream end lost their
      // DASHBOARD_REPLACE / WIDGET_ERROR frames — fall them back to error so
      // the skeleton does not hang forever. No time threshold; done triggers it.
      useDashboardStore.getState().fallbackLoadingWidgetsToError();
      resetWatchdog();
      store.clearAiSteps();
      return true;
    }

    default: {
      // Unknown / deprecated events are silently ignored. The deprecated
      // DASHBOARD_INIT / WIDGET_DATA_UPDATE / dashboard_patch / WIDGET_UPDATE /
      // WIDGET_REMOVE / dashboard_rollback / sql_generated / data_preview /
      // action_call / action_call_result have no sources under the whole-tree
      // protocol (contract §4) and intentionally fall through here.
      break;
    }
  }

  return false;
}

function applyActionResult(result: ActionCallResult): void {
  // Must use immer's draft context — the frozen state objects cannot be
  // mutated directly outside setState().
  useDashboardStore.setState((state) => {
    const lastAssistant = [...state.messages]
      .reverse()
      .find((m) => m.role === 'assistant');
    if (lastAssistant) {
      if (!lastAssistant.metadata) {
        lastAssistant.metadata = {};
      }
      if (!lastAssistant.metadata.actionResults) {
        lastAssistant.metadata.actionResults = [];
      }
      lastAssistant.metadata.actionResults.push(result);
    }
  });
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function delay(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms);
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer);
        reject(new DOMException('Aborted', 'AbortError'));
      },
      { once: true },
    );
  });
}

function generateStepId(): string {
  return 'step-' + generateId().slice(0, 8);
}

const TOOL_LABELS: Record<string, string> = {
  execute_sql: '执行查询...',
  search_objects: '搜索相关表...',
  list_tables: '获取表列表...',
  describe_table: '分析表结构...',
  commit_widget: '生成图表...',
};

function getToolLabel(actionType: string): string {
  return TOOL_LABELS[actionType] || '执行操作...';
}

// Re-export Widget so existing imports of the type from this module still work.
export type { Widget };
