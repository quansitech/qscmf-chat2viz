import { useRef, useCallback } from 'react';
import { useDashboardStore, generateId } from '../store/dashboardStore';
import type {
  Widget,
  ActionCall,
  ActionCallResult,
  DashboardPatch,
} from '../store/dashboardStore';
import { parseSseEvent, type SseEvent } from '../sse-parser';
import { computeNextSlot } from '../utils/layout';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const SSE_ENDPOINT = '/extends/Chat2Viz/api_ask_stream';
const MAX_RETRIES = 2;
const RETRY_DELAY_MS = 1000;

// ---------------------------------------------------------------------------
// Helpers for extracting typed values from untyped SSE data
// ---------------------------------------------------------------------------

function str(val: unknown, fallback = ''): string {
  return typeof val === 'string' ? val : fallback;
}

function bool(val: unknown, fallback = false): boolean {
  return typeof val === 'boolean' ? val : fallback;
}

function obj<T = Record<string, unknown>>(val: unknown, fallback = {} as T): T {
  return val !== null && typeof val === 'object' && !Array.isArray(val)
    ? (val as T)
    : fallback;
}

function arr<T>(val: unknown, fallback: T[] = []): T[] {
  return Array.isArray(val) ? (val as T[]) : fallback;
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

    const payload: Record<string, unknown> = {
      question,
      dashboard_context: store.getDashboardContext(),
    };

    const conversationId = useDashboardStore.getState().conversationId;
    if (conversationId) {
      payload.conversation_id = conversationId;
    }

    let attempt = 0;

    while (attempt <= MAX_RETRIES) {
      try {
        const response = await fetch(SSE_ENDPOINT, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-Event-Format': 'chat2viz',
          },
          body: JSON.stringify(payload),
          signal: controller.signal,
        });

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

        if (streamClean) {
          useDashboardStore.getState().completeConversation();
        } else {
          // Stream terminated (done=true) without receiving a 'done' event.
          // Clean up loading state without displaying an error message — the
          // partial response may still be useful to the user.
          const s = useDashboardStore.getState();
          if (s.isLoading) {
            useDashboardStore.setState({
              isLoading: false,
              streamingState: 'idle',
              aiSteps: [],
            });
          }
        }

        // Auto-save is handled by useDashboardDraft's store subscription,
        // which detects isDirty transitions and triggers debounced saves.

        return;
      } catch (err) {
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
// ---------------------------------------------------------------------------

function dispatchEvent(event: SseEvent | null): boolean {
  if (!event) return false;

  const store = useDashboardStore.getState();

  switch (event.type) {
    case 'chart_ready': {
      if (!validateWidget(event.data)) {
        // Silently skip invalid chart data to prevent UI disruption
        break;
      }
      // Resolve SQL: payload.sql takes priority, then lastAssistant.metadata.sql
      const payloadSql = str(event.data.sql);
      const metadataSql = (() => {
        const last = [...store.messages].reverse().find(m => m.role === 'assistant');
        return last?.metadata?.sql ?? '';
      })();
      const resolvedSql = payloadSql || metadataSql || undefined;

      // Normalize widget: build a new object to avoid mutating event.data
      // (immutable update) and to coalesce id/widget_id aliasing.
      const raw = event.data as unknown as Widget;
      const widget: Widget = {
        ...raw,
        id: raw.id || (raw as unknown as { widget_id?: string }).widget_id || generateId(),
        title: raw.title || (raw.g2_spec as { title?: string })?.title || '',
        g2_spec: raw.g2_spec || {},
        data: raw.data || {},
        sql: resolvedSql,
        layout: computeNextSlot(store.widgets),
      };
      store.addPanel(widget);
      store.addAiStep({
        id: generateStepId(),
        type: 'chart_ready',
        label: '图表已生成',
        timestamp: generateId(),
        completed: true,
      });
      break;
    }

    case 'action_call': {
      const action: ActionCall = {
        action_type: str(event.data.action_type),
        params: obj<Record<string, unknown>>(event.data.params),
      };
      store.executeAction(action);
      const toolLabel = getToolLabel(action.action_type);
      store.addAiStep({
        id: generateStepId(),
        type: 'tool_start',
        label: toolLabel,
        timestamp: generateId(),
        completed: false,
      });
      break;
    }

    case 'action_call_result': {
      const result: ActionCallResult = {
        success: bool(event.data.success),
        result: event.data.result,
      };
      applyActionResult(result);
      break;
    }

    case 'dashboard_patch': {
      const patches = arr<DashboardPatch>(event.data.patches);
      applyPatches(patches);
      break;
    }

    case 'answer': {
      store.appendAnswer(str(event.data.text));
      break;
    }

    case 'conversation_id': {
      store.setConversationId(str(event.data.conversation_id));
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
      store.clearAiSteps();
      return true;
    }

    case 'sql_generated': {
      store.addAiStep({
        id: generateStepId(),
        type: 'sql_ready',
        label: 'SQL 已生成',
        timestamp: generateId(),
        completed: true,
      });
      const sql = str(event.data.sql);
      if (sql) {
        useDashboardStore.setState((state) => {
          const lastAssistant = [...state.messages].reverse().find(m => m.role === 'assistant');
          if (lastAssistant) {
            if (!lastAssistant.metadata) lastAssistant.metadata = {};
            lastAssistant.metadata.sql = sql;
          }
        });
      }
      break;
    }

    case 'data_preview': {
      store.addAiStep({
        id: generateStepId(),
        type: 'data_ready',
        label: '数据已加载',
        timestamp: generateId(),
        completed: true,
      });
      break;
    }

    default: {
      // Unknown event types are silently ignored
      break;
    }
  }

  return false;
}

// ---------------------------------------------------------------------------
// SSE data validation
// ---------------------------------------------------------------------------

function validateWidget(data: unknown): data is Widget {
  if (typeof data !== 'object' || data === null) return false;
  const d = data as Record<string, unknown>;
  // Accept either `id` (canonical) or `widget_id` (Python service convention).
  const id = d.id ?? d.widget_id;
  if (typeof id !== 'string' || id.length === 0) return false;
  // g2_spec must be a non-null object — empty object is allowed (LazyG2Renderer
  // will render the skeleton until the spec arrives via a follow-up event).
  if (typeof d.g2_spec !== 'object' || d.g2_spec === null) return false;
  return true;
}

// ---------------------------------------------------------------------------
// Deep-path utilities
// ---------------------------------------------------------------------------

/**
 * Build a partial widget update that sets a value at an arbitrary depth.
 *
 * @param widget  Current widget state (read-only — not mutated)
 * @param segments  Full JSON Pointer segments: ['widgets', id, ...fieldPath]
 * @param value   Value to set at the leaf
 * @returns A Partial<Widget> suitable for store.updateWidget(), or null on error.
 *
 * For `segments = ['widgets','w1','g2_spec','encode','x']` and `value = "month"`:
 *   → reads current widget.g2_spec, deep-clones it, sets .encode.x = "month"
 *   → returns { g2_spec: <cloned-and-updated> }
 *
 * For `segments = ['widgets','w1','title']` (fieldPath length 1):
 *   → returns { title: value } (backward-compatible shallow path)
 */
function deepSetByPath(
  widget: Widget,
  segments: string[],
  value: unknown,
): Partial<Widget> | null {
  const fieldPath = segments.slice(2); // e.g. ['g2_spec','encode','x']
  if (fieldPath.length === 0) return null;

  const topKey = fieldPath[0];

  // Shallow path — same as the old `segments[2]` behaviour
  if (fieldPath.length === 1) {
    return { [topKey]: value } as unknown as Partial<Widget>;
  }

  // Deep path: clone the current subtree, then deep-set the leaf
  const widgetRec = widget as unknown as Record<string, unknown>;
  const current = widgetRec[topKey];
  // Guard: cannot deep-set inside a non-null primitive
  if (current !== null && current !== undefined && typeof current !== 'object') {
    return null;
  }
  const cloned: Record<string, unknown> =
    current !== null && current !== undefined
      ? JSON.parse(JSON.stringify(current))
      : {};

  // Walk to the parent of the leaf, creating missing intermediate nodes
  let target: Record<string, unknown> = cloned;
  for (let i = 1; i < fieldPath.length - 1; i++) {
    const key = fieldPath[i];
    if (
      target[key] === undefined ||
      target[key] === null
    ) {
      // "mkdir -p": next segment is numeric → array, else → object
      const nextKey = fieldPath[i + 1];
      target[key] = /^\d+$/.test(nextKey) ? [] : {};
    } else if (typeof target[key] === 'object') {
      // Shallow-clone at each level to avoid mutating siblings
      target[key] = Array.isArray(target[key])
        ? [...(target[key] as unknown[])]
        : { ...(target[key] as Record<string, unknown>) };
    } else {
      // Encountered a primitive where we need a container — cannot traverse
      return null;
    }
    target = target[key] as Record<string, unknown>;
  }

  // Final guard: target must be a writable container
  if (typeof target !== 'object' || target === null) return null;

  // Set the leaf value
  const lastKey = fieldPath[fieldPath.length - 1];
  target[lastKey] = value;

  return { [topKey]: cloned } as unknown as Partial<Widget>;
}

/**
 * Build a partial widget update that deletes a leaf at an arbitrary depth.
 * Returns null if the path does not exist or cannot be traversed.
 */
function deepDeleteByPath(
  widget: Widget,
  segments: string[],
): Partial<Widget> | null {
  const fieldPath = segments.slice(2);
  if (fieldPath.length === 0) return null;

  const topKey = fieldPath[0];

  // Deleting a top-level widget field is not supported via updateWidget
  // (Object.assign cannot remove keys). Return null — the caller should
  // handle this case directly using Immer draft if needed.
  if (fieldPath.length === 1) {
    return null;
  }

  const widgetRec = widget as unknown as Record<string, unknown>;
  const current = widgetRec[topKey];
  if (
    current === null ||
    current === undefined ||
    typeof current !== 'object'
  ) {
    return null;
  }

  const cloned: Record<string, unknown> = JSON.parse(
    JSON.stringify(current),
  );

  // Walk to the parent of the leaf
  let target: Record<string, unknown> = cloned;
  for (let i = 1; i < fieldPath.length - 1; i++) {
    const key = fieldPath[i];
    const child = target[key];
    if (
      child === undefined ||
      child === null ||
      typeof child !== 'object'
    ) {
      return null; // Path doesn't exist — nothing to delete
    }
    target = child as Record<string, unknown>;
  }

  const lastKey = fieldPath[fieldPath.length - 1];
  if (Array.isArray(target)) {
    const idx = parseInt(lastKey, 10);
    if (!isNaN(idx) && idx >= 0 && idx < target.length) {
      target.splice(idx, 1);
    }
  } else {
    delete target[lastKey];
  }

  return { [topKey]: cloned } as unknown as Partial<Widget>;
}

// ---------------------------------------------------------------------------
// Patch application
// ---------------------------------------------------------------------------

function applyPatches(patches: DashboardPatch[]): void {
  if (patches.length === 0) return;

  const store = useDashboardStore.getState();

  for (const patch of patches) {
    // path format: "/widgets/{id}/field" or "/widgets/{id}/g2_spec/encode/x" etc.
    const segments = patch.path.split('/').filter(Boolean);

    if (segments[0] === 'widgets' && segments.length >= 2) {
      const widgetId = segments[1];

      if (patch.op === 'remove') {
        if (segments.length === 2) {
          // Remove entire widget
          store.removePanel(widgetId);
        } else {
          // Deep remove: delete only the leaf node
          const widget = store.widgets[widgetId];
          if (widget) {
            const partial = deepDeleteByPath(widget, segments);
            if (partial) store.updateWidget(widgetId, partial);
          }
        }
        continue;
      }

      if (patch.op === 'add' || patch.op === 'replace') {
        if (segments.length === 2) {
          if (patch.op === 'replace' && patch.value) {
            store.updateWidget(widgetId, patch.value as unknown as Partial<Widget>);
          } else if (patch.op === 'add' && patch.value) {
            if (!validateWidget(patch.value)) continue;
            store.addPanel(patch.value as unknown as Widget);
          }
        } else {
          // segments.length >= 3 — shallow or deep path
          const widget = store.widgets[widgetId];
          if (widget) {
            const partial = deepSetByPath(widget, segments, patch.value);
            if (partial) store.updateWidget(widgetId, partial);
          }
        }
      }
    } else if (segments[0] === 'title' && patch.value !== undefined) {
      useDashboardStore.setState({ title: String(patch.value) });
    }
  }
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
};

function getToolLabel(actionType: string): string {
  return TOOL_LABELS[actionType] || '执行操作...';
}
