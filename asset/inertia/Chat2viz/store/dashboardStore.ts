// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { create } from 'zustand';
import { immer } from 'zustand/middleware/immer';
import { temporal } from 'zundo';
import type { Draft } from 'immer';
import { buildSchema } from '../utils/buildSchema';
import { ADMIN_BASE } from '../utils/routes';
import { suggestHeight } from '../utils/suggestHeight';

// ---------------------------------------------------------------------------
// Utility — safe UUID generation with fallback for non-secure contexts
// ---------------------------------------------------------------------------

export function generateId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    try {
      return crypto.randomUUID();
    } catch {
      // fallback below
    }
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.random() * 16 | 0;
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
  });
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface WidgetLayout {
  x: number;
  y: number;
  w: number;
  h: number;
  /**
   * True once the user has manually dragged/resized this widget. While false,
   * updateWidgetData may auto-tune `h` to fit the rendered content (tables grow
   * with rows, KPI cards shrink, etc.). Once true, the user's chosen size is
   * honored and never overridden by the auto-height logic.
   */
  userSized?: boolean;
}

/**
 * Render-level widget status (distinct from the contract's event-level status).
 * Event-level: pending | success | error → mapped to render-level before store write:
 *   pending→loading, success→chart, error→error.
 * Legacy widgets without a status field default to 'chart' (zero-regression).
 */
export type WidgetStatus = 'loading' | 'error' | 'chart';

export interface Widget {
  id: string;
  title: string;
  g2_spec: Record<string, unknown>;
  /** Canonical bare rows array (Row[]). Envelopes are never stored here;
   *  updateWidgetData is the sole normalization boundary. */
  data: Record<string, unknown>[];
  sql?: string;
  /** Render-level status driving PreviewPanel's three render branches. */
  status?: WidgetStatus;
  /** When true, the widget's data was truncated by the dispatcher row cap. */
  truncated?: boolean;
  /** Total row count reported by Python (read-only display, never recomputed). */
  total?: number;
  refreshInterval?: number;
  /** Monotonically increasing counter set to Date.now() on manual refresh. */
  refreshKey?: number;
  layout: WidgetLayout;
}

export interface ActionCall {
  action_type: string;
  params: Record<string, unknown>;
}

export interface ActionCallResult {
  success: boolean;
  result: unknown;
  /** DEF-07: stable failure code (e.g. "EDIT_FAILED"). Absent on success. */
  error_code?: string;
  /** DEF-07: human-readable failure detail. Absent on success. */
  error?: string;
}

/**
 * Layout entry carried by DASHBOARD_REPLACE (contract §2). `i` == widget_id.
 */
export interface DashboardLayoutSlot {
  i: string;
  x: number;
  y: number;
  w: number;
  h: number;
}

/**
 * Raw widget payload as it arrives on DASHBOARD_REPLACE (contract §2). Field
 * names mirror the cross-repo contract (widget_id / chart_type / data|null /
 * status / error_msg). replaceDashboard normalizes this into the store Widget
 * shape (id / g2_spec / data / status / layout).
 *
 * `data: null` is the slim signal — the widget's SQL did not change
 * (MergeScheduler REUSE/RE_SPEC), so the frontend reuses its cached data.
 */
export interface DashboardReplaceWidget {
  widget_id?: string;
  id?: string;
  title?: string;
  chart_type?: string;
  status?: 'success' | 'error';
  sql?: string;
  g2_spec?: Record<string, unknown>;
  data?: Record<string, unknown>[] | null;
  error_msg?: string;
  truncated?: boolean;
  total?: number;
}

export type MessageStatus = 'streaming' | 'complete' | 'interrupted' | 'failed';

export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp: string;
  message_status?: MessageStatus;
  thought?: string;
  metadata?: {
    sql?: string;
    g2_spec?: Record<string, unknown>;
    widgetId?: string;
    actionCalls?: ActionCall[];
    actionResults?: ActionCallResult[];
  };
}

/**
 * Conversation streaming status. Drives AI-step indicators and auto-save edges.
 *
 * - 'idle'      — ready, no request in flight
 * - 'submitted' — request sent, awaiting the FIRST SSE frame (the "thinking"
 *                 phase before any token/tool arrives). Semantically part of a
 *                 stream; auto-save suppression treats both 'submitted' and
 *                 'streaming' as active (see useDashboardDraft, which keys off
 *                 `!== 'idle'`).
 * - 'streaming' — SSE frames are arriving (answer text and/or tool calls).
 * - 'error'     — the request failed.
 *
 * NOTE: the literal 'streaming' MUST be preserved verbatim — useDashboardDraft's
 * stream-end edge detection depends on it. 'submitted' is purely additive.
 */
export type StreamingState = 'idle' | 'submitted' | 'streaming' | 'error';

/**
 * Session-level history hydration status — orthogonal to StreamingState (which
 * tracks a single ask). Drives the initial "loading history" gate so the chat
 * panel can disable send + show a spinner until the conversation is loaded.
 * Not part of the temporal partialize whitelist, so it never enters undo state.
 */
export type HistoryStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface AiStep {
  id: string;
  /**
   * declarative-frontend-adapter: the 'sql_ready'/'data_ready' variants were
   * removed (sql_generated/data_preview events are gone). tool_start drives
   * the AI-step indicator; 'thinking' is the pre-tool placeholder.
   */
  type: 'tool_start' | 'thinking';
  label: string;
  timestamp: string;
  completed: boolean;
}

export interface DashboardState {
  uid: string;
  title: string;
  widgets: Record<string, Widget>;
  conversationId: string;
  messages: ChatMessage[];
  isLoading: boolean;
  streamingState: StreamingState;
  /** Session-level history hydration status (orthogonal to streamingState). */
  historyStatus: HistoryStatus;
  aiSteps: AiStep[];
  error: string;
  autoSaveEnabled: boolean;
  isDirty: boolean;
  lastSavedAt: string | null;
}

// ---------------------------------------------------------------------------
// Actions interface
// ---------------------------------------------------------------------------

export interface DashboardActions {
  startConversation: (question: string) => void;
  addPanel: (widget: Widget) => void;
  removePanel: (widgetId: string) => void;
  updateWidget: (widgetId: string, partial: Partial<Widget>) => void;
  /**
   * Whole-tree replace (declarative-frontend-adapter, contract §2).
   *
   * Clears the existing widgets map and rebuilds it from `newWidgets`,
   * updating `layout` from `layoutSlots`. Widgets absent from the new tree
   * are cleared. For a widget whose payload `data` is null (slim — its SQL
   * did not change), the prior cached data is reused instead of being wiped.
   *
   * `layoutSlots` (from DASHBOARD_REPLACE.layout) overrides each widget's
   * embedded layout slot; missing slots fall back to the embedded/default slot.
   */
  replaceDashboard: (
    layoutSlots: DashboardLayoutSlot[],
    newWidgets: Record<string, DashboardReplaceWidget>,
  ) => void;
  /** Create a widget placeholder (status=loading, no g2_spec required). */
  createWidgetPlaceholder: (widgetId: string, partial: Partial<Widget>) => void;
  /** Inject data for a widget. Status is DERIVED from data + spec (not hardcoded).
   *  `data` is normalized to bare Row[] at this boundary (envelope {rows} unwrapped). */
  updateWidgetData: (widgetId: string, data: unknown, meta?: { truncated?: boolean; total?: number; g2_spec?: Record<string, unknown>; sql?: string }) => void;
  /** Mark a single widget as errored (local degradation; MUST NOT touch store.error). */
  setWidgetError: (widgetId: string) => void;
  /** Fallback: set any widget still loading to error (e.g. on stream done). */
  fallbackLoadingWidgetsToError: () => void;
  updateLayout: (widgetId: string, layout: WidgetLayout) => void;
  /** Mark a widget as manually resized by the user — disables content
   *  auto-height so the user's chosen size is preserved. */
  markWidgetUserSized: (widgetId: string) => void;
  executeAction: (action: ActionCall) => void;
  appendAnswer: (text: string) => void;
  appendThought: (text: string) => void;
  setSql: (widgetId: string, sql: string) => void;
  /** Set the dashboard page title (update_page_title / WIDGET_UPDATE set_title). */
  setTitle: (title: string) => void;
  setError: (error: string) => void;
  completeConversation: () => void;
  /** Transition from 'submitted' to 'streaming' on the first real SSE frame. */
  markStreaming: () => void;
  saveDraft: () => Promise<void>;
  setConversationId: (id: string) => void;
  resetConversation: () => void;
  toggleAutoSave: () => void;
  getDashboardContext: () => object;
  addAiStep: (step: AiStep) => void;
  completeAiStep: (stepId: string) => void;
  /** Bump an in-progress step's timestamp so its spinner restarts, without
   *  completing it (no ghost row) and without adding a new row. */
  refreshAiStep: (stepId: string) => void;
  clearAiSteps: () => void;
}

// ---------------------------------------------------------------------------
// Full store type (state + actions)
// ---------------------------------------------------------------------------

type StoreType = DashboardState & DashboardActions;

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------

const initialState: DashboardState = {
  uid: '',
  title: '',
  widgets: {},
  conversationId: '',
  messages: [],
  isLoading: false,
  streamingState: 'idle' as StreamingState,
  historyStatus: 'idle' as HistoryStatus,
  aiSteps: [] as AiStep[],
  error: '',
  autoSaveEnabled: true,
  isDirty: false,
  lastSavedAt: null,
};

// ---------------------------------------------------------------------------
// Store
//
// The middleware composition (immer inside temporal) produces complex generics
// that confuse TypeScript's inference.  We type the store factory body
// explicitly and cast the final result to the public interface so consumers
// get clean types without exposing the middleware internals.
// ---------------------------------------------------------------------------

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const _create = create as any;

// Typed setter/ungetter — narrow the any to Draft<DashboardState> inside callbacks.
type SetFn = (fn: (draft: Draft<DashboardState>) => void) => void;
type GetFn = () => DashboardState;

// ---------------------------------------------------------------------------
// Unified chart-existence helper (DESIGN_BASIS #7)
//
// Single source of truth for "does this spec render a chart". Shared by
// updateWidgetData (status derivation), getDashboardContext (chart type
// inference), G2Renderer, and WidgetCard. A spec is a valid chart iff it has
// `type` OR `mark` (G2 v5 mark style) OR a non-empty `children` array.
// ---------------------------------------------------------------------------

export function hasChartSpec(spec: unknown): boolean {
  if (!spec || typeof spec !== 'object') return false;
  const s = spec as Record<string, unknown>;
  if (typeof s.type === 'string' && s.type !== '') return true;
  if (typeof s.mark === 'string' && s.mark !== '') return true;
  if (Array.isArray(s.children) && s.children.length > 0) return true;
  return false;
}

/**
 * Normalize widget data to a bare Row[] at the store write boundary
 * (DESIGN_BASIS #2 / D2). Envelope `{rows, columns}` → `.rows`; bare array
 * passthrough; anything else → `[]` (defensive, never throws).
 * `columns` is metadata and dropped (G2 infers columns from rows).
 */
export function normalizeRows(data: unknown): Record<string, unknown>[] {
  if (Array.isArray(data)) return data as Record<string, unknown>[];
  if (data !== null && typeof data === 'object' && !Array.isArray(data)) {
    const maybeRows = (data as Record<string, unknown>).rows;
    if (Array.isArray(maybeRows)) return maybeRows as Record<string, unknown>[];
  }
  return [];
}



function extractEncodeSummary(
  encode: Record<string, unknown>,
): Record<string, string> | null {
  const result: Record<string, string> = {};
  for (const [channel, value] of Object.entries(encode)) {
    if (typeof value === 'object' && value !== null && 'field' in (value as Record<string, unknown>)) {
      const fieldName = (value as Record<string, unknown>).field;
      if (typeof fieldName === 'string') {
        result[channel] = fieldName;
      }
    } else if (Array.isArray(value)) {
      // Multi-field encode (rare), take first field
      for (const item of value) {
        if (typeof item === 'object' && item !== null && 'field' in item) {
          const fieldName = (item as Record<string, unknown>).field;
          if (typeof fieldName === 'string') {
            result[channel] = fieldName;
            break;
          }
        }
      }
    }
  }
  return Object.keys(result).length > 0 ? result : null;
}

// ---------------------------------------------------------------------------
// Raw store (any-typed for middleware composition compatibility)
// ---------------------------------------------------------------------------

const _store = _create()(
  temporal(
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    immer((_set: any, _get: any) => {
      const set = _set as SetFn;
      const get = _get as GetFn;

      return {
        ...initialState,

        // ------- Conversation -------

        startConversation: (question: string) => {
          set((state) => {
            state.isLoading = true;
            // 'submitted' = request sent, awaiting first frame. Transitions to
            // 'streaming' on the first answer/action frame (markStreaming).
            state.streamingState = 'submitted';
            state.aiSteps = [];
            state.error = '';
            state.messages.push({
              id: generateId(),
              role: 'user',
              content: question,
              timestamp: new Date().toISOString(),
            });
            state.messages.push({
              id: generateId(),
              role: 'assistant',
              content: '',
              timestamp: new Date().toISOString(),
            });
          });
        },

        appendAnswer: (text: string) => {
          set((state) => {
            const lastAssistant = [...state.messages]
              .reverse()
              .find((m) => m.role === 'assistant');
            if (lastAssistant) {
              lastAssistant.content += text;
            }
            state.isDirty = true;
          });
        },

        appendThought: (text: string) => {
          set((state) => {
            const lastAssistant = [...state.messages]
              .reverse()
              .find((m) => m.role === 'assistant');
            if (lastAssistant) {
              lastAssistant.thought = (lastAssistant.thought || '') + text;
            }
            state.isDirty = true;
          });
        },

        completeConversation: () => {
          set((state) => {
            state.isLoading = false;
            state.streamingState = 'idle';
            state.aiSteps = [];
          });
        },

        markStreaming: () => {
          set((state) => {
            // Only flip 'submitted' → 'streaming'; never override 'error'/'idle'.
            if (state.streamingState === 'submitted') {
              state.streamingState = 'streaming';
            }
          });
        },

        // ------- Widget CRUD -------

        addPanel: (widget: Widget) => {
          set((state) => {
            state.widgets[widget.id] = {
              ...widget,
              layout: widget.layout || { x: 0, y: 0, w: 12, h: 6 },
            };
            state.isDirty = true;
          });
        },

        removePanel: (widgetId: string) => {
          set((state) => {
            delete state.widgets[widgetId];
            state.isDirty = true;
          });
        },

        updateWidget: (widgetId: string, partial: Partial<Widget>) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            Object.assign(widget, partial);
            state.isDirty = true;
          });
        },

        // declarative-frontend-adapter: whole-tree replace (contract §2). The
        // store is a render-only cache — it never participates in state
        // computation (no reducer / applyPatches). data:null slim widgets reuse
        // the prior cached data (the frontend is the cache-reuse boundary).
        replaceDashboard: (
          layoutSlots: DashboardLayoutSlot[],
          newWidgets: Record<string, DashboardReplaceWidget>,
        ) => {
          set((state) => {
            const prior = state.widgets;
            const slotById = new Map<string, DashboardLayoutSlot>();
            for (const slot of layoutSlots ?? []) {
              if (slot && typeof slot.i === 'string') {
                slotById.set(slot.i, slot);
              }
            }

            const next: Record<string, Widget> = {};
            for (const [id, w] of Object.entries(newWidgets)) {
              const widgetId = (w && typeof w.widget_id === 'string' && w.widget_id) || id;
              // data:null slim → reuse the prior cached data (the widget's SQL
              // did not change, MergeScheduler REUSE/RE_SPEC). Anything else
              // (array data, or absent with no cache) resolves to [].
              const cached = prior[widgetId];
              const priorData = cached?.data ?? [];
              const payloadData = (w && w.data === null) ? priorData : normalizeRows(w?.data);

              // event-level status → render-level status (mirrors the WIDGET_*
              // status mapping: success→chart, error→error, else keep cached).
              let status: WidgetStatus;
              if (w?.status === 'error') {
                status = 'error';
              } else if (w?.status === 'success') {
                status = 'chart';
              } else {
                status = cached?.status ?? 'loading';
              }

              const slot = slotById.get(widgetId);
              const layout: WidgetLayout = slot
                ? { x: slot.x, y: slot.y, w: slot.w, h: slot.h }
                : (cached?.layout ?? { x: 0, y: 0, w: 12, h: suggestHeight({ spec: w?.g2_spec ?? {}, data: payloadData }) });

              next[widgetId] = {
                id: widgetId,
                title: w?.title ?? cached?.title ?? '',
                g2_spec: w?.g2_spec ?? cached?.g2_spec ?? {},
                data: payloadData,
                sql: typeof w?.sql === 'string' && w.sql !== '' ? w.sql : cached?.sql,
                status,
                layout,
                ...(typeof w?.truncated === 'boolean' ? { truncated: w.truncated } : (cached?.truncated !== undefined ? { truncated: cached.truncated } : {})),
                ...(typeof w?.total === 'number' ? { total: w.total } : (cached?.total !== undefined ? { total: cached.total } : {})),
                ...(cached?.refreshInterval !== undefined ? { refreshInterval: cached.refreshInterval } : {}),
              };
            }

            state.widgets = next;
            state.isDirty = true;
          });
        },

        // Multi-widget lifecycle actions (D5: placeholder path bypasses
        // validateWidget since placeholders legitimately carry no g2_spec).
        createWidgetPlaceholder: (widgetId: string, partial: Partial<Widget>) => {
          set((state) => {
            // Preserve any pre-existing fields (e.g. title from a prior partial),
            // default status to 'loading', assign a layout slot.
            const existing = state.widgets[widgetId];
            const baseLayout = partial.layout ?? existing?.layout;
            const spec = partial.g2_spec ?? existing?.g2_spec ?? {};
            const data = partial.data ?? existing?.data ?? [];
            // Content-aware default height: when no layout was provided, derive
            // a sensible h from the spec/data instead of the flat 6. Existing
            // persisted layouts are honored as-is.
            const layout = baseLayout ?? {
              x: 0,
              y: 0,
              w: 12,
              h: suggestHeight({ spec, data }),
            };
            state.widgets[widgetId] = {
              id: widgetId,
              title: partial.title ?? existing?.title ?? '',
              g2_spec: partial.g2_spec ?? existing?.g2_spec ?? {},
              data: partial.data ?? existing?.data ?? [],
              sql: partial.sql ?? existing?.sql,
              status: 'loading',
              layout,
              ...(partial.refreshInterval !== undefined ? { refreshInterval: partial.refreshInterval } : {}),
            };
            state.isDirty = true;
          });
        },

        updateWidgetData: (widgetId: string, data: unknown, meta?: { truncated?: boolean; total?: number; g2_spec?: Record<string, unknown>; sql?: string }) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            // DESIGN_BASIS #2: this is the SOLE normalization boundary. Envelope
            // {rows,columns} → bare Row[]; bare array passthrough; else []. The
            // canonical store form is always a bare array (never an envelope).
            const rows = normalizeRows(data);
            widget.data = rows;
            // g2_spec is the widget config, delivered by WIDGET_DATA_UPDATE
            // (contract §3/§7). When present it transitions loading→chart with
            // the real spec; when absent the placeholder's spec is preserved.
            if (meta?.g2_spec !== undefined) widget.g2_spec = meta.g2_spec;
            // DESIGN_BASIS #4/#5: sql is widget config (must persist). SSE
            // WIDGET_DATA_UPDATE carries the widget's sql in its envelope; bind
            // it here so buildSchema serializes it for HTTP re-fetch / publish.
            if (typeof meta?.sql === 'string' && meta.sql !== '') widget.sql = meta.sql;
            // DESIGN_BASIS #7: status is DERIVED from data + spec, not hardcoded.
            // Empty rows or no valid spec → stay 'loading' (watchdog falls back
            // to error if no real frame ever arrives); real data + valid spec →
            // 'chart'. setWidgetError explicitly sets 'error' independently.
            if (rows.length > 0 && hasChartSpec(widget.g2_spec)) {
              widget.status = 'chart';
              // Content-aware auto-height: when real data + spec arrive and the
              // user has NOT manually resized this widget, recompute `h` to fit
              // the content (tables scale with rows, dense charts breathe, KPI
              // value cards stay compact). This makes suggestHeight's logic
              // actually take effect on data load instead of every widget
              // staying at its placeholder height. User-resized widgets are
              // left alone (their userSized flag is set in updateLayout).
              if (!widget.layout?.userSized) {
                const suggested = suggestHeight({
                  spec: widget.g2_spec,
                  data: rows,
                  currentH: widget.layout?.h,
                });
                // Only grow or shrink toward the suggestion; never enlarge a
                // widget the data says should be small beyond a sane cap.
                widget.layout = { ...(widget.layout as WidgetLayout), h: suggested };
              }
            } else {
              widget.status = 'loading';
            }
            if (meta?.truncated !== undefined) widget.truncated = meta.truncated;
            if (meta?.total !== undefined) widget.total = meta.total;
            state.isDirty = true;
          });
        },

        setWidgetError: (widgetId: string) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            widget.status = 'error';
            state.isDirty = true;
          });
        },

        fallbackLoadingWidgetsToError: () => {
          set((state) => {
            let changed = false;
            for (const w of Object.values(state.widgets)) {
              // Event-driven cleanup: flip only true loading placeholders (lost
              // their WIDGET_DATA_UPDATE/WIDGET_ERROR frames) to error. Fully
              // rendered (chart) or already-errored widgets are left untouched.
              if (w.status === 'loading') {
                w.status = 'error';
                changed = true;
              }
            }
            if (changed) state.isDirty = true;
          });
        },

        updateLayout: (widgetId: string, layout: WidgetLayout) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            widget.layout = { ...layout };
            state.isDirty = true;
          });
        },

        markWidgetUserSized: (widgetId: string) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget || !widget.layout) return;
            widget.layout = { ...widget.layout, userSized: true };
          });
        },

        setSql: (widgetId: string, sql: string) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            widget.sql = sql;
            state.isDirty = true;
          });
        },

        setTitle: (title: string) => {
          set((state) => {
            state.title = title;
            state.isDirty = true;
          });
        },

        // ------- Actions -------

        executeAction: (action: ActionCall) => {
          set((state) => {
            const lastAssistant = [...state.messages]
              .reverse()
              .find((m) => m.role === 'assistant');
            if (lastAssistant) {
              if (!lastAssistant.metadata) {
                lastAssistant.metadata = {};
              }
              if (!lastAssistant.metadata.actionCalls) {
                lastAssistant.metadata.actionCalls = [];
              }
              lastAssistant.metadata.actionCalls.push(action);
            }
            state.isDirty = true;
          });
        },

        // ------- Error -------

        setError: (error: string) => {
          set((state) => {
            state.error = error;
            state.isLoading = false;
            state.streamingState = 'error';
            state.aiSteps = [];
          });
        },

        // ------- Conversation ID -------

        setConversationId: (id: string) => {
          set((state) => {
            state.conversationId = id;
          });
        },

        resetConversation: () => {
          set((state) => {
            state.conversationId = '';
            state.messages = [];
            state.aiSteps = [];
            state.error = '';
            state.streamingState = 'idle';
            state.isLoading = false;
          });
        },

        // ------- Persistence -------

        saveDraft: async () => {
          await saveDashboardDraft();
        },

        toggleAutoSave: () => {
          set((state) => {
            state.autoSaveEnabled = !state.autoSaveEnabled;
          });
        },

        // ------- Context for AI -------

        getDashboardContext: () => {
          const { widgets, conversationId, uid } = get();
          // Python NL2SQL service expects widgets as a dict keyed by id, not an array.
          const widgetDict: Record<string, unknown> = {};
          for (const w of Object.values(widgets) as Widget[]) {
            // DESIGN_BASIS #7: chart type inference MUST use the same validity
            // judgment as the render layer (hasChartSpec). Invalid spec →
            // 'unknown'; valid spec → mark ?? type.
            const validSpec = hasChartSpec(w.g2_spec);
            const mark = (w.g2_spec?.mark ?? w.g2_spec?.type) as string | undefined;
            const chartType = validSpec ? (mark || 'unknown') : 'unknown';
            // Extract encode channel summary: {channel: field_name}
            const encodeSpec = w.g2_spec?.encode as Record<string, unknown> | undefined;
            const encodeSummary: Record<string, string> | null = encodeSpec
              ? extractEncodeSummary(encodeSpec)
              : null;
            // Strip data array from g2_spec before sending to backend (token budget).
            // The snapshot only needs spec structure, not query results.
            const g2SpecNoData: Record<string, unknown> | null = w.g2_spec
              ? (() => {
                  const clone = JSON.parse(JSON.stringify(w.g2_spec as Record<string, unknown>));
                  delete clone.data;
                  return clone;
                })()
              : null;
            widgetDict[w.id] = {
              id: w.id,
              type: chartType,
              title: w.title,
              sql: w.sql ?? null,
              layout: w.layout,
              ...(encodeSummary ? { encode: encodeSummary } : {}),
              ...(g2SpecNoData ? { g2_spec: g2SpecNoData } : {}),
            };
          }
          return {
            dashboard_uid: uid,
            conversation_id: conversationId,
            widgets: widgetDict,
          };
        },

        // ------- AI Steps -------

        addAiStep: (step: AiStep) => {
          set((state) => {
            state.aiSteps.push(step);
          });
        },

        completeAiStep: (stepId: string) => {
          set((state) => {
            const step = state.aiSteps.find((s) => s.id === stepId);
            if (step) step.completed = true;
          });
        },

        refreshAiStep: (stepId: string) => {
          set((state) => {
            const step = state.aiSteps.find((s) => s.id === stepId);
            if (step) step.timestamp = new Date().toISOString();
          });
        },
        clearAiSteps: () => {
          set((state) => {
            state.aiSteps = [];
          });
        },
      };
    }),
    {
      limit: 50,
      partialize: (state: any) => {
        const { widgets, title, uid, isDirty } = state;
        return { widgets, title, uid, isDirty };
      },
    },
  ),
);

// ---------------------------------------------------------------------------
// Typed export — IIFE creates a callable function with Zustand static methods.
// The type annotation on the const provides overload resolution for selectors.
// ---------------------------------------------------------------------------

type UseDashboardStore = {
  (): StoreType;
  <U>(selector: (state: StoreType) => U): U;
  getState: () => StoreType;
  setState: (
    next: StoreType | Partial<StoreType> | ((state: StoreType) => StoreType | Partial<StoreType> | void),
    replace?: boolean,
  ) => void;
  subscribe: (listener: (state: StoreType, prev: StoreType) => void) => () => void;
  temporal: {
    getState: () => { pastStates: DashboardState[]; futureStates: DashboardState[] };
    undo: () => void;
    redo: () => void;
    clear: () => void;
  };
};

export const useDashboardStore: UseDashboardStore = (() => {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const fn: any = (selector?: (state: StoreType) => unknown): unknown => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const s: any = _store;
    return selector ? s(selector) : s();
  };
  fn.getState = _store.getState;
  fn.setState = _store.setState;
  fn.subscribe = _store.subscribe;
  fn.temporal = _store.temporal;
  return fn;
})();

// ---------------------------------------------------------------------------
// Async persistence — lives outside the store to keep actions synchronous.
// Reads via getState(), writes via setState() — no immer draft context.
// ---------------------------------------------------------------------------

export async function saveDashboardDraft(): Promise<void> {
  const { uid, title, widgets, isDirty, conversationId } = _store.getState() as DashboardState;
  if (!isDirty) return;

  // conversation-one-to-one: if the backend has already initialized a dashboard
  // (conversationId is set from the conversation_id SSE frame) but uid is still
  // empty (the frame's uid write is racing), SKIP api_create — it would create
  // a SECOND orphan dashboard. The flush-on-stream-end subscription retries
  // once the uid is backfilled.
  if (!uid && conversationId) {
    // Check if uid arrived in the meantime (race window)
    const settledUid = _store.getState().uid;
    if (settledUid) {
      // uid arrived — re-call with the now-populated uid so the url/method below
      // correctly take the api_update path.
      const state2 = _store.getState() as DashboardState;
      return _saveDashboardDraftCore(settledUid, state2.title, state2.widgets, state2.conversationId);
    }
    // uid not yet settled — defer this save (stream-end flush retries)
    return;
  }

  return _saveDashboardDraftCore(uid, title, widgets, conversationId);
}

// Core save logic, extracted so the race-guard above can call it with the
// settled uid after the conversation_id frame backfills it.
async function _saveDashboardDraftCore(
  uid: string,
  title: string,
  widgets: DashboardState['widgets'],
  conversationId: string,
): Promise<void> {
  const schema = buildSchema(widgets);

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 30000);

  try {
    // task 3.6: uid is validated server-side (UUID v4) but encode here too
    // so any unexpected value (e.g. a stale id with special chars from an
    // older buggy build) cannot inject path segments.
    const url = uid
      ? `${ADMIN_BASE}/api_update/uid/${encodeURIComponent(uid)}`
      : `${ADMIN_BASE}/api_create`;

    const method = uid ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(
        uid ? { current_schema: schema } : { title, current_schema: schema },
      ),
      signal: controller.signal,
    });

    clearTimeout(timeoutId);

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const result = await response.json();
    if (result.status === 1) {
      useDashboardStore.setState((state) => {
        const next: Partial<DashboardState> = {
          isDirty: false,
          lastSavedAt: new Date().toISOString(),
        };
        if (!state.uid && result.data?.uid) {
          next.uid = result.data.uid as string;
        }
        return next;
      });
    } else {
      useDashboardStore.setState({ error: result.info || '保存失败' });
    }
  } catch (e) {
    // task 3.7: AbortController timeout (30s) → user-facing timeout message
    // rather than a raw AbortError. Manual cancellation is not a path here
    // (saveDashboardDraft has no external cancel caller), so abort == timeout.
    const isAbort = e instanceof DOMException && e.name === 'AbortError';
    useDashboardStore.setState({
      error: isAbort ? '保存超时，请重试' : (e instanceof Error ? e.message : '保存失败'),
    });
  } finally {
    clearTimeout(timeoutId);
  }
}
