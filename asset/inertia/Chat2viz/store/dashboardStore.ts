// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { create } from 'zustand';
import { immer } from 'zustand/middleware/immer';
import { temporal } from 'zundo';
import type { Draft } from 'immer';
import { buildDslSchema } from '../utils/buildSchema';
import { ADMIN_BASE } from '../utils/routes';
import { suggestHeight } from '../utils/suggestHeight';
import type {
  DashboardDSL,
  WidgetSpec,
  QuerySpec,
  SlicerSpec,
  InteractionSpec,
  LayoutSlot,
  SSEDashboardReplaceV3,
} from '../types/dsl';

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
// Types — re-exported DSL types + render-level widget cache + chat concerns
// ---------------------------------------------------------------------------

export type {
  DashboardDSL,
  WidgetSpec,
  QuerySpec,
  SlicerSpec,
  InteractionSpec,
  LayoutSlot,
  SSEDashboardReplaceV3,
} from '../types/dsl';

/**
 * Render-level widget status (distinct from the contract's event-level status).
 * Event-level: success | error | removed → mapped to render-level before store write:
 *   success→chart, error→error, removed→empty. Widgets without a status field
 * default to 'chart' (zero-regression). 'empty' = SQL returned 0 rows.
 */
export type WidgetStatus = 'loading' | 'error' | 'chart' | 'empty';

/**
 * Per-widget data cache entry. `dsl.widgets[id].data` carries the wire payload;
 * this cache is the render-side mirror that survives slim `data:null` reuses
 * (contract §4.1) and is overwritten idempotently by WIDGET_READY frames (§4.2).
 *
 * `lastReadyRowsRef` tracks the rows array reference last delivered by
 * WIDGET_READY so the §4.2 no-flicker check can shallow-compare it against the
 * whole-tree DASHBOARD_REPLACE payload (skip store write when identical).
 */
export interface WidgetCacheEntry {
  rows: Record<string, unknown>[];
  total?: number;
  truncated?: boolean;
  /** §4 line 274: aligned to Python errors.py `.code`. */
  error_code?: string;
  /** User-readable error message (when status='error'). */
  error_msg?: string;
  /** Render-level status driving the WidgetCard shell branches. */
  status?: WidgetStatus;
  /**
   * Last rows array reference received via WIDGET_READY, for the §4.2
   * READY→REPLACE idempotency shallow-compare (no-flicker). Cleared on change.
   */
  lastReadyRowsRef?: Record<string, unknown>[] | null;
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
 * - 'submitted' — request sent, awaiting the FIRST SSE frame
 * - 'streaming' — SSE frames are arriving (answer text and/or tool calls)
 * - 'error'     — the request failed
 */
export type StreamingState = 'idle' | 'submitted' | 'streaming' | 'error';

export type HistoryStatus = 'idle' | 'loading' | 'ready' | 'error';

export interface AiStep {
  id: string;
  type: 'tool_start' | 'thinking';
  label: string;
  timestamp: string;
  completed: boolean;
}

export interface DashboardState {
  uid: string;
  title: string;
  /** DSL AST (contract §2.1) — the single widget/query/slicer source of truth. */
  dsl: DashboardDSL | null;
  /** slicer_id → value (drives react-query keys via useWidgetData). */
  slicerValues: Record<string, unknown>;
  /** widget_id → render-side cache (rows + status + error_code + lastReadyRef). */
  widgetDataCache: Record<string, WidgetCacheEntry>;
  conversationId: string;
  messages: ChatMessage[];
  isLoading: boolean;
  streamingState: StreamingState;
  historyStatus: HistoryStatus;
  aiSteps: AiStep[];
  error: string;
  autoSaveEnabled: boolean;
  isDirty: boolean;
  lastSavedAt: string | null;
  /** P0-B: suggested follow-ups from the last DASHBOARD_REPLACE (≤3 strings). */
  lastSuggestedFollowups: string[];
}

// ---------------------------------------------------------------------------
// Actions interface
// ---------------------------------------------------------------------------

export interface DashboardActions {
  startConversation: (question: string) => void;
  /**
   * Whole-tree DSL replace (contract §4.1). Clears the old DSL and rebuilds
   * the entire AST (queries/widgets/slicers/interactions/layout). `data:null`
   * slim widgets reuse the prior `widgetDataCache` entry. Bad fields degrade
   * (missing query_id → widget status='error'; missing slot → default layout)
   * rather than mid-tree throwing. Applies the §4.2 no-flicker rule (skip the
   * store write for widgets whose payload data is reference-identical to the
   * last WIDGET_READY rows).
   */
  replaceDSL: (payload: SSEDashboardReplaceV3) => void;
  /** Progressive per-widget pre-render (contract §4 WIDGET_READY). */
  updateWidgetDataCache: (
    widgetId: string,
    data: { rows?: Record<string, unknown>[]; columns?: string[] } | null,
    meta?: { total?: number; truncated?: boolean; error_code?: string; error_msg?: string; status?: WidgetStatus },
  ) => void;
  /** Mark a single widget as errored (local degradation; MUST NOT touch store.error). */
  setWidgetError: (widgetId: string, errorCode?: string, errorMsg?: string) => void;
  /** Fallback: set any widget still loading to error (e.g. on stream done). */
  fallbackLoadingWidgetsToError: () => void;
  /** Update a single widget's plugin_spec/title (edit-page interactions). */
  updateWidget: (widgetId: string, partial: Partial<WidgetSpec>) => void;
  /** Update a widget's layout slot (drag/resize). */
  updateLayout: (widgetId: string, x: number, y: number, w: number, h: number) => void;
  /** Freeze a widget's auto-height after a manual resize. */
  markWidgetUserSized: (widgetId: string) => void;
  /** Remove a widget from the DSL (edit-page delete). */
  removeWidget: (widgetId: string) => void;
  /** Set a slicer value (drives useWidgetData key invalidation). */
  setSlicerValue: (slicerId: string, value: unknown) => void;
  /** Reset the DSL + caches (new conversation / clear). */
  resetDSL: () => void;
  executeAction: (action: ActionCall) => void;
  appendAnswer: (text: string) => void;
  appendThought: (text: string) => void;
  setTitle: (title: string) => void;
  setError: (error: string) => void;
  completeConversation: () => void;
  markStreaming: () => void;
  setSuggestedFollowups: (followups: string[]) => void;
  saveDraft: () => Promise<void>;
  setConversationId: (id: string) => void;
  resetConversation: () => void;
  toggleAutoSave: () => void;
  getDashboardContext: () => object;
  addAiStep: (step: AiStep) => void;
  completeAiStep: (stepId: string) => void;
  refreshAiStep: (stepId: string) => void;
  clearAiSteps: () => void;
}

// ---------------------------------------------------------------------------
// Full store type
// ---------------------------------------------------------------------------

type StoreType = DashboardState & DashboardActions;

// ---------------------------------------------------------------------------
// Initial state
// ---------------------------------------------------------------------------

const initialState: DashboardState = {
  uid: '',
  title: '',
  dsl: null,
  slicerValues: {},
  widgetDataCache: {},
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
  lastSuggestedFollowups: [] as string[],
};

// ---------------------------------------------------------------------------
// Store factory (immer inside temporal)
// ---------------------------------------------------------------------------

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const _create = create as any;

type SetFn = (fn: (draft: Draft<DashboardState>) => void) => void;
type GetFn = () => DashboardState;

// ---------------------------------------------------------------------------
// Chart-spec validity helper (DESIGN_BASIS #7)
//
// Single source of truth for "does this spec render a G2 chart". Used by the
// g2_chart plugin and G2Renderer. A spec is a valid chart iff it has `type` OR
// `mark` (G2 v5 mark style) OR a non-empty `children` array.
// ---------------------------------------------------------------------------

export function hasChartSpec(spec: unknown): boolean {
  if (!spec || typeof spec !== 'object') return false;
  const s = spec as Record<string, unknown>;
  if (typeof s.type === 'string' && s.type !== '') return true;
  if (typeof s.mark === 'string' && s.mark !== '') return true;
  if (Array.isArray(s.children) && s.children.length > 0) return true;
  return false;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Normalize wire data ({rows,columns} envelope or bare array) to bare Row[].
 * Defensive: never throws. Mirrors the legacy normalizeRows philosophy.
 */
export function normalizeRows(data: unknown): Record<string, unknown>[] {
  if (Array.isArray(data)) return data as Record<string, unknown>[];
  if (data !== null && typeof data === 'object' && !Array.isArray(data)) {
    const maybeRows = (data as Record<string, unknown>).rows;
    if (Array.isArray(maybeRows)) return maybeRows as Record<string, unknown>[];
  }
  return [];
}

/**
 * Map event-level lifecycle status (contract §2.4: success|error|removed) to
 * the render-level status driving the WidgetCard shell. Defaults to 'chart'
 * for any unknown/absent value (zero-regression for legacy widgets).
 */
function mapStatus(wireStatus: string | undefined, cachedStatus: WidgetStatus | undefined): WidgetStatus {
  if (wireStatus === 'error') return 'error';
  if (wireStatus === 'removed') return 'empty';
  if (wireStatus === 'success') return 'chart';
  return cachedStatus ?? 'loading';
}

// ---------------------------------------------------------------------------
// Store
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
            if (state.streamingState === 'submitted') {
              state.streamingState = 'streaming';
            }
          });
        },

        setSuggestedFollowups: (followups: string[]) => {
          set((state) => {
            state.lastSuggestedFollowups = Array.isArray(followups)
              ? followups.filter((f) => typeof f === 'string' && f.trim() !== '').slice(0, 3)
              : [];
          });
        },

        // ------- DSL whole-tree replace (contract §4.1) -------

        replaceDSL: (payload: SSEDashboardReplaceV3) => {
          set((state) => {
            const priorCache = state.widgetDataCache;
            const widgetEntries = Object.entries(payload.widgets ?? {});
            const slotById = new Map<string, LayoutSlot>();
            for (const slot of payload.layout?.slots ?? []) {
              if (slot && typeof slot.widget_id === 'string') {
                slotById.set(slot.widget_id, slot);
              }
            }
            const queriesPresent = new Set(Object.keys(payload.queries ?? {}));

            const nextWidgets: Record<string, WidgetSpec> = {};
            const nextCache: Record<string, WidgetCacheEntry> = {};

            for (const [id, w] of widgetEntries) {
              const widgetId = (w && typeof w.widget_id === 'string' && w.widget_id) || id;

              // §4.2 no-flicker: if payload.data.rows is reference-identical to
              // the last WIDGET_READY rows for this widget, skip the store write
              // entirely (reuse DOM key, avoid READY→REPLACE flicker). Only the
              // rows reference identity matters; other fields are updated.
              const priorEntry = priorCache[widgetId];
              const payloadRows = w?.data ? normalizeRows(w.data) : null;

              // §4.1 slim: data:null → reuse prior cached rows
              let resolvedRows: Record<string, unknown>[];
              if (w?.data === null || w?.data === undefined) {
                resolvedRows = priorEntry?.rows ?? [];
              } else {
                resolvedRows = payloadRows ?? [];
              }

              // Determine render-level status. If the widget references a
              // missing query_id, force status='error' (degrade, don't throw).
              const queryMissing =
                typeof w?.query_id === 'string' &&
                w.query_id !== '' &&
                !queriesPresent.has(w.query_id);

              const wireStatus = queryMissing ? 'error' : w?.status;
              const status = queryMissing
                ? 'error'
                : mapStatus(wireStatus, priorEntry?.status);

              // Layout: payload slot wins; else prior slot; else content-aware default.
              const slot = slotById.get(widgetId);
              // build the merged WidgetSpec (carrying a layout slot for the grid)
              const widgetRegion = w?.region ?? slot?.region ?? 'content';
              const mergedWidget: WidgetSpec = {
                widget_id: widgetId,
                plugin_type: w?.plugin_type ?? 'markdown',
                plugin_spec: w?.plugin_spec ?? {},
                query_id: w?.query_id ?? '',
                region: widgetRegion,
                ...(typeof w?.title === 'string' ? { title: w.title } : {}),
                ...(typeof w?.full_width === 'boolean' ? { full_width: w.full_width } : {}),
                // DSL store form: data is the wire payload (may be null slim).
                // The render cache below holds the resolved rows.
                data: w?.data ?? null,
                ...(typeof w?.total === 'number' ? { total: w.total } : {}),
                ...(typeof w?.truncated === 'boolean' ? { truncated: w.truncated } : {}),
                status: wireStatus === 'error' || queryMissing ? 'error' : (w?.status ?? 'success'),
                ...(typeof w?.error_msg === 'string' && w.error_msg ? { error_msg: w.error_msg } : {}),
                ...(typeof w?.error_code === 'string' && w.error_code ? { error_code: w.error_code } : {}),
              };
              // Embed the resolved slot coordinates on the widget for the grid.
              (mergedWidget as WidgetSpec & { layout?: LayoutSlot }).layout = slot ?? {
                widget_id: widgetId,
                region: widgetRegion,
                x: 0,
                y: 0,
                w: 12,
                h: suggestHeight({
                  pluginType: mergedWidget.plugin_type,
                  data: resolvedRows,
                }),
              };
              nextWidgets[widgetId] = mergedWidget;

              // §4.2 no-flicker idempotency: when the payload data is
              // reference-identical to the last WIDGET_READY rows for this
              // widget, reuse the prior cache entry verbatim (skip the store
              // write so React reuses the DOM key — no READY→REPLACE flash).
              // Reference identity is achievable when updateWidgetDataCache
              // (WIDGET_READY) and replaceDSL share the same parsed object
              // (e.g. when the SSE layer hands the same rows reference through,
              // or when a slim `data:null` reuse keeps the prior ref). For
              // independently-parsed frames this falls through to a normal write.
              const priorRef = priorEntry?.lastReadyRowsRef;
              const isReadyIdentical =
                payloadRows !== null &&
                priorRef !== null &&
                priorRef !== undefined &&
                payloadRows === priorRef;
              if (isReadyIdentical && priorEntry) {
                nextCache[widgetId] = priorEntry;
                continue;
              }

              // Cache entry: resolved rows + status + §4.2 ref tracking.
              const lastRef =
                w?.data === null || w?.data === undefined
                  ? priorEntry?.lastReadyRowsRef ?? null
                  : payloadRows;
              nextCache[widgetId] = {
                rows: resolvedRows,
                ...(typeof w?.total === 'number' ? { total: w.total } : (priorEntry?.total !== undefined ? { total: priorEntry.total } : {})),
                ...(typeof w?.truncated === 'boolean' ? { truncated: w.truncated } : (priorEntry?.truncated !== undefined ? { truncated: priorEntry.truncated } : {})),
                status,
                ...(queryMissing || w?.error_msg ? { error_msg: w?.error_msg || '该图表引用了不存在的查询，请重新生成' } : {}),
                ...(w?.error_code || queryMissing ? { error_code: w?.error_code || 'WIDGET_QUERY_MISSING' } : {}),
                lastReadyRowsRef: lastRef ?? null,
              };
            }

            // Build the new DSL AST.
            const nextDsl: DashboardDSL = {
              version: payload.version,
              ...(typeof payload.title === 'string' ? { title: payload.title } : {}),
              layout: payload.layout ?? { regions: ['content'], slots: [] },
              queries: payload.queries ?? {},
              widgets: nextWidgets,
              slicers: payload.slicers ?? [],
              interactions: payload.interactions ?? [],
            };

            state.dsl = nextDsl;
            state.widgetDataCache = nextCache;
            state.isDirty = true;
          });
        },

        // ------- Progressive WIDGET_READY (contract §4) -------

        updateWidgetDataCache: (
          widgetId: string,
          data: { rows?: Record<string, unknown>[]; columns?: string[] } | null,
          meta?: { total?: number; truncated?: boolean; error_code?: string; error_msg?: string; status?: WidgetStatus },
        ) => {
          set((state) => {
            const rows = data ? normalizeRows(data) : [];
            const prior = state.widgetDataCache[widgetId];
            // Status derivation: explicit meta.status wins; else rows+presence.
            const status: WidgetStatus = meta?.status ?? (rows.length > 0 ? 'chart' : 'empty');
            state.widgetDataCache[widgetId] = {
              rows,
              ...(meta?.total !== undefined ? { total: meta.total } : (prior?.total !== undefined ? { total: prior.total } : {})),
              ...(meta?.truncated !== undefined ? { truncated: meta.truncated } : (prior?.truncated !== undefined ? { truncated: prior.truncated } : {})),
              status,
              ...(meta?.error_code ? { error_code: meta.error_code } : {}),
              ...(meta?.error_msg ? { error_msg: meta.error_msg } : {}),
              // §4.2: track this rows reference for the no-flicker compare.
              lastReadyRowsRef: rows,
            };
            state.isDirty = true;
          });
        },

        setWidgetError: (widgetId: string, errorCode?: string, errorMsg?: string) => {
          set((state) => {
            const prior = state.widgetDataCache[widgetId];
            state.widgetDataCache[widgetId] = {
              rows: prior?.rows ?? [],
              ...(prior?.total !== undefined ? { total: prior.total } : {}),
              ...(prior?.truncated !== undefined ? { truncated: prior.truncated } : {}),
              status: 'error',
              ...(errorCode ? { error_code: errorCode } : {}),
              ...(errorMsg ? { error_msg: errorMsg } : {}),
            };
            state.isDirty = true;
          });
        },

        fallbackLoadingWidgetsToError: () => {
          set((state) => {
            let changed = false;
            for (const [id, entry] of Object.entries(state.widgetDataCache)) {
              if (entry.status === 'loading') {
                state.widgetDataCache[id] = { ...entry, status: 'error' };
                changed = true;
              }
            }
            // Also cover widgets declared in the DSL but not yet cached.
            if (state.dsl) {
              for (const widgetId of Object.keys(state.dsl.widgets)) {
                if (!state.widgetDataCache[widgetId] || state.widgetDataCache[widgetId].status === 'loading') {
                  state.widgetDataCache[widgetId] = {
                    rows: [],
                    status: 'error',
                  };
                  changed = true;
                }
              }
            }
            if (changed) state.isDirty = true;
          });
        },

        // ------- Widget CRUD (edit page) -------

        updateWidget: (widgetId: string, partial: Partial<WidgetSpec>) => {
          set((state) => {
            if (!state.dsl) return;
            const w = state.dsl.widgets[widgetId];
            if (!w) return;
            Object.assign(w, partial);
            state.isDirty = true;
          });
        },

        updateLayout: (widgetId: string, x: number, y: number, w: number, h: number) => {
          set((state) => {
            if (!state.dsl) return;
            const slot = state.dsl.layout.slots.find((s) => s.widget_id === widgetId);
            if (slot) {
              slot.x = x;
              slot.y = y;
              slot.w = w;
              slot.h = h;
            }
            // Also reflect on the widget's embedded layout for the grid.
            const widget = state.dsl.widgets[widgetId];
            if (widget) {
              const wl = (widget as WidgetSpec & { layout?: LayoutSlot }).layout;
              if (wl) {
                wl.x = x; wl.y = y; wl.w = w; wl.h = h;
              }
            }
            state.isDirty = true;
          });
        },

        markWidgetUserSized: (widgetId: string) => {
          set((state) => {
            if (!state.dsl) return;
            const slot = state.dsl.layout.slots.find((s) => s.widget_id === widgetId);
            if (slot) {
              (slot as LayoutSlot & { userSized?: boolean }).userSized = true;
            }
            const widget = state.dsl.widgets[widgetId];
            if (widget) {
              const wl = (widget as WidgetSpec & { layout?: LayoutSlot & { userSized?: boolean } }).layout;
              if (wl) wl.userSized = true;
            }
          });
        },

        removeWidget: (widgetId: string) => {
          set((state) => {
            if (!state.dsl) return;
            delete state.dsl.widgets[widgetId];
            state.dsl.layout.slots = state.dsl.layout.slots.filter((s) => s.widget_id !== widgetId);
            delete state.widgetDataCache[widgetId];
            state.isDirty = true;
          });
        },

        // ------- Slicers -------

        setSlicerValue: (slicerId: string, value: unknown) => {
          set((state) => {
            state.slicerValues[slicerId] = value;
            state.isDirty = true;
          });
        },

        resetDSL: () => {
          set((state) => {
            state.dsl = null;
            state.slicerValues = {};
            state.widgetDataCache = {};
          });
        },

        // ------- Actions -------

        executeAction: (action: ActionCall) => {
          set((state) => {
            const lastAssistant = [...state.messages]
              .reverse()
              .find((m) => m.role === 'assistant');
            if (lastAssistant) {
              if (!lastAssistant.metadata) lastAssistant.metadata = {};
              if (!lastAssistant.metadata.actionCalls) lastAssistant.metadata.actionCalls = [];
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

        // ------- Title -------

        setTitle: (title: string) => {
          set((state) => {
            state.title = title;
            state.isDirty = true;
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
          const { dsl, conversationId, uid } = get();
          // Serialize the DSL AST with widget data stripped to null (slim,
          // contract §5). Python reads topology + plugin_spec only.
          //
          // The DSL fields (version/layout/queries/widgets/slicers/interactions)
          // are spread to the TOP LEVEL of the context (not nested under `dsl`)
          // so Python's DashboardArtifact.from_dict — which reads
          // data.get("widgets") at the top level — can reconstruct the current
          // dashboard for modify-mode REUSE. The previous `{dsl: {...}}` nesting
          // made from_dict see widgets=None → current.widgets=[] →
          // MergeScheduler dropped every prior widget on the next turn
          // (agent-browser-confirmed data loss). Python also has a defensive
          // unnest now, but keeping the wire shape flat matches the documented
          // contract (design.md §dashboard_context.widgets, schemas.py).
          if (!dsl) {
            return {
              dashboard_uid: uid,
              conversation_id: conversationId,
              version: null,
              layout: [],
              queries: {},
              widgets: {},
              slicers: [],
              interactions: [],
            };
          }
          const slimWidgets: Record<string, WidgetSpec> = {};
          for (const [id, w] of Object.entries(dsl.widgets)) {
            // Strip the render-only `layout` field (embedded by replaceDSL for
            // the grid) so the backend payload matches contract §2.4 exactly.
            // Also force data:null (slim, contract §5).
            const { layout: _omit, data: _omitData, ...rest } = w as WidgetSpec & { layout?: unknown };
            slimWidgets[id] = { ...rest, data: null };
          }
          const slimDsl: DashboardDSL = {
            ...dsl,
            widgets: slimWidgets,
          };
          return {
            dashboard_uid: uid,
            conversation_id: conversationId,
            ...slimDsl,
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
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      partialize: (state: any) => {
        const { dsl, slicerValues, widgetDataCache, title, uid, isDirty } = state;
        return { dsl, slicerValues, widgetDataCache, title, uid, isDirty };
      },
    },
  ),
);

// ---------------------------------------------------------------------------
// Typed export
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
// ---------------------------------------------------------------------------

export async function saveDashboardDraft(): Promise<void> {
  const { uid, title, dsl, isDirty, conversationId } = _store.getState() as DashboardState;
  if (!isDirty) return;

  // conversation-one-to-one: if the backend has already initialized a dashboard
  // but uid is still empty, SKIP api_create — defer until uid is backfilled.
  if (!uid && conversationId) {
    const settledUid = _store.getState().uid;
    if (settledUid) {
      const state2 = _store.getState() as DashboardState;
      return _saveDashboardDraftCore(settledUid, state2.title, state2.dsl, state2.conversationId);
    }
    return;
  }

  return _saveDashboardDraftCore(uid, title, dsl, conversationId);
}

async function _saveDashboardDraftCore(
  uid: string,
  title: string,
  dsl: DashboardDSL | null,
  conversationId: string,
): Promise<void> {
  const schema = buildDslSchema(dsl);

  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 30000);

  try {
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
    const isAbort = e instanceof DOMException && e.name === 'AbortError';
    useDashboardStore.setState({
      error: isAbort ? '保存超时，请重试' : (e instanceof Error ? e.message : '保存失败'),
    });
  } finally {
    clearTimeout(timeoutId);
  }
}
