import { useRef, useCallback } from 'react';
import { useDashboardStore, generateId } from '../store/dashboardStore';
import type {
  Widget,
  WidgetLayout,
  ActionCall,
  ActionCallResult,
  DashboardPatch,
} from '../store/dashboardStore';
import { parseSseEvent, type SseEvent } from '../sse-parser';
import { planActionCall, findInProgressToolStep } from '../utils/aiSteps';
// contract-driven-foundation Phase 2: SSE event types from contract SSOT (codegen).
import type { SSEWidgetUpdate, SSEWidgetRemove } from '../types/sse-events';
import { isWidgetUpdateData, isWidgetRemoveData } from '../types/sse-events';

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

function num(val: unknown, fallback = 0): number {
  return typeof val === 'number' && !Number.isNaN(val) ? val : fallback;
}

// ---------------------------------------------------------------------------
// Per-widget timeout watchdog (time-driven, 30s threshold)
//
// Tracks when each widget entered the 'loading' state. On every dispatched
// event the watchdog checks whether any loading widget has exceeded the
// threshold and, if so, falls that single widget back to 'error' — WITHOUT
// aborting the fetch (the rest of the multi-widget stream keeps flowing).
// ---------------------------------------------------------------------------

const widgetLoadingSince: Map<string, number> = new Map();

function trackWidgetLoading(widgetId: string): void {
  if (!widgetLoadingSince.has(widgetId)) {
    widgetLoadingSince.set(widgetId, Date.now());
  }
}

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
    case 'DASHBOARD_INIT': {
      // widgets is a map<widget_id, WidgetMeta> per contract §2. Tolerate an
      // array shape too (some emitters send a list). Placeholders go through
      // the store action directly (NOT validateWidget) — they legitimately
      // carry no g2_spec yet (skeleton frame, contract §1/§7).
      const widgetsField = event.data.widgets;
      const widgetList: Record<string, unknown>[] = Array.isArray(widgetsField)
        ? widgetsField as Record<string, unknown>[]
        : Object.values(obj<Record<string, Record<string, unknown>>>(widgetsField));

      // layout is an array<{i,x,y,w,h}> with i == widget_id (contract §2).
      // Build a lookup so each placeholder inherits its grid slot.
      const layoutById = new Map<string, WidgetLayout>();
      for (const item of arr<Record<string, unknown>>(event.data.layout)) {
        const i = str(item.i);
        if (i) {
          layoutById.set(i, {
            x: num(item.x, 0),
            y: num(item.y, 0),
            w: num(item.w, 12),
            h: num(item.h, 6),
          });
        }
      }

      for (const w of widgetList) {
        const validPlaceholder = validateWidgetPlaceholder(w);
        const widgetId = str(w.widget_id) || str((w as { id?: string }).id);
        if (!validPlaceholder) continue;
        const layout = layoutById.get(widgetId);
        useDashboardStore.getState().createWidgetPlaceholder(widgetId, {
          title: str(w.title) || undefined,
          ...(layout ? { layout } : {}),
        });
        trackWidgetLoading(widgetId);
      }
      // Event-level status mapping is implicit here: DASHBOARD_INIT (pending)
      // → placeholder status=loading (handled inside createWidgetPlaceholder).
      // MUST NOT set global store.error.
      runWatchdog();
      break;
    }

    case 'WIDGET_DATA_UPDATE': {
      const widgetId = str(event.data.widget_id) || str((event.data as { id?: string }).id);
      if (widgetId) {
        // g2_spec is the widget's config, delivered via this event (contract §3/§7).
        // Forward it so the widget transitions loading→chart with its real spec.
        const g2Spec = obj<Record<string, unknown>>(event.data.g2_spec);
        // sql is widget config (DESIGN_BASIS #4/#5). The SSE WIDGET_DATA_UPDATE
        // envelope carries this widget's sql (contract §3); bind it to the widget
        // so buildSchema persists it for HTTP re-fetch and publish. Without this,
        // store.widget.sql stays undefined → schema omits sql → api_widget_data
        // returns "组件不存在或未配置数据查询" on view/reload.
        const sql = str(event.data.sql);
        useDashboardStore.getState().updateWidgetData(widgetId, event.data.data, {
          truncated: bool(event.data.truncated, undefined),
          total: typeof event.data.total === 'number' ? event.data.total : undefined,
          ...(Object.keys(g2Spec).length > 0 ? { g2_spec: g2Spec } : {}),
          ...(sql ? { sql } : {}),
        });
        clearWidgetLoading(widgetId);
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

    case 'action_call': {
      const action: ActionCall = {
        action_type: str(event.data.action_type),
        params: obj<Record<string, unknown>>(event.data.params),
      };
      store.executeAction(action);
      const toolLabel = getToolLabel(action.action_type);
      // First real frame — flip 'submitted' → 'streaming'.
      useDashboardStore.getState().markStreaming();
      // De-duplicate tool steps so the indicator shows a single processing row
      // instead of appending a new badge per action_call event (the
      // "不断追加工具执行过程" anti-pattern). Same-type repeat calls collapse
      // onto one row; a different tool completes the prior one first.
      const decision = planActionCall(
        useDashboardStore.getState().aiSteps,
        action.action_type,
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
        // Same-type repeat: refresh the EXISTING in-progress step in place.
        // Do NOT complete+re-add — that leaves completed ghost rows behind and
        // causes the step list to grow with every repeat call.
        st.refreshAiStep(decision.id);
      } else {
        if (decision.kind === 'completeAndAdd' && decision.completeId) {
          st.completeAiStep(decision.completeId);
        }
        st.addAiStep(decision.step);
      }
      break;
    }

    case 'action_call_result': {
      const result: ActionCallResult = {
        success: bool(event.data.success),
        result: event.data.result,
      };
      applyActionResult(result);
      // Complete the most recent in-progress tool step so its spinner stops and
      // it settles into the (green) completed state. Previously this never
      // happened, so tool steps stayed "processing" forever and accumulated.
      const s = useDashboardStore.getState();
      const inProgress = findInProgressToolStep(s.aiSteps);
      if (inProgress) {
        s.completeAiStep(inProgress.id);
      }
      break;
    }

    case 'dashboard_patch': {
      const patches = arr<DashboardPatch>(event.data.patches);
      applyPatches(patches);
      break;
    }

    case 'WIDGET_UPDATE': {
      // edit-widget-snapshot-contract: field-level declaration delivery.
      // The backend declares {widget_id, action, field?, value?}; we apply a
      // deep dotted-path set on widgets[id] (NOT RFC6902 applyPatches).
      // contract-driven-foundation Phase 2: data is now narrowed via the
      // SSEWidgetUpdate type guard from types/sse-events.ts (codegen SSOT).
      const data = isWidgetUpdateData(event.data) ? event.data : event.data as unknown as SSEWidgetUpdate;
      const wid = str(data.widget_id);
      const action = str(data.action);
      const field = data.field as string | undefined;
      const value = data.value;
      if (!wid) break;
      if (action === 'remove') {
        store.removePanel(wid);
        break;
      }
      if (action === 'add') {
        // add_widget declares the full initial config under event.data.widget
        const initial = obj<Record<string, unknown>>(event.data.widget);
        if (initial) store.createWidgetPlaceholder(wid, initial as Partial<Widget>);
        break;
      }
      if (action === 'set_title') {
        if (typeof value === 'string') store.setTitle(value);
        break;
      }
      // set_field / set_type / set_sql / set_layout → dotted-path set
      if (field && value !== undefined) {
        store.updateWidgetPath(wid, field, value);
      }
      break;
    }

    case 'WIDGET_REMOVE': {
      // Frontend deletes the widget; layout is embedded per-widget so removePanel
      // suffices (no separate layout array to filter).
      // contract-driven-foundation Phase 2: narrowed via SSEWidgetRemove guard.
      const data = isWidgetRemoveData(event.data) ? event.data : event.data as unknown as SSEWidgetRemove;
      const wid = str(data.widget_id);
      if (wid) store.removePanel(wid);
      break;
    }

    case 'dashboard_rollback': {
      const rollbackWidgetId = str(event.data.widget_id);
      const rollbackSnapshot = obj<{
        sql?: string;
        g2_spec?: Record<string, unknown>;
        title?: string;
      }>(event.data.snapshot);
      // Per design: skip rollback if snapshot is empty (no error thrown).
      if (!rollbackWidgetId || !rollbackSnapshot || Object.keys(rollbackSnapshot).length === 0) {
        break;
      }
      // Preserve current layout — rollback restores sql/g2_spec/title only.
      const currentWidget = store.widgets[rollbackWidgetId];
      const preservedLayout = currentWidget?.layout
        ? { layout: currentWidget.layout }
        : {};
      store.updateWidget(rollbackWidgetId, {
        ...rollbackSnapshot,
        ...preservedLayout,
      } as Partial<Widget>);
      break;
    }

    case 'answer': {
      // First real frame — flip 'submitted' → 'streaming'.
      useDashboardStore.getState().markStreaming();
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
      // Event-driven cleanup: widgets still loading at stream end lost their
      // WIDGET_DATA_UPDATE/WIDGET_ERROR frames — fall them back to error so
      // the skeleton does not hang forever. No time threshold; done triggers it.
      useDashboardStore.getState().fallbackLoadingWidgetsToError();
      resetWatchdog();
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
        // DESIGN_BASIS #4/#5 (defensive): sql_generated is conversation-level.
        // WIDGET_DATA_UPDATE.sql is the primary binding path (case above), but
        // when the agent emits sql_generated WITHOUT a follow-up
        // WIDGET_DATA_UPDATE (observed on multi-widget planning prompts), the
        // widget's sql would stay undefined → buildSchema omits sql → HTTP
        // re-fetch / publish fail with "组件不存在或未配置数据查询".
        //
        // Bind to the SOLE widget lacking sql. Only when exactly one candidate
        // exists (the common single-chart J0 case) — never guess across a
        // multi-widget dashboard where attribution is ambiguous.
        const cur = useDashboardStore.getState();
        const widgets = Object.values(cur.widgets) as Widget[];
        const sqlLess = widgets.filter((w) => typeof w.sql !== 'string' || w.sql === '');
        if (sqlLess.length === 1) {
          cur.setSql(sqlLess[0].id, sql);
        }
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

/**
 * Lenient validator for DASHBOARD_INIT placeholder widgets (design D5).
 *
 * DASHBOARD_INIT placeholders legitimately carry only title/type and NO
 * g2_spec (skeleton frame) — the strict validator would discard them.
 * This path only requires a valid id/widget_id and a non-null object root.
 */
function validateWidgetPlaceholder(data: unknown): data is Record<string, unknown> {
  if (typeof data !== 'object' || data === null) return false;
  const d = data as Record<string, unknown>;
  const id = d.id ?? d.widget_id;
  return typeof id === 'string' && id.length > 0;
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

  for (const patch of patches) {
    // Re-read store state each iteration so sequential patches see
    // the result of the previous patch (Requirement: Sequential application).
    const store = useDashboardStore.getState();

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
