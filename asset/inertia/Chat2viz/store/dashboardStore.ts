// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { create } from 'zustand';
import { immer } from 'zustand/middleware/immer';
import { temporal } from 'zundo';
import type { Draft } from 'immer';
import { buildSchema } from '../utils/buildSchema';

// ---------------------------------------------------------------------------
// Utility — safe UUID generation with fallback for non-secure contexts
// ---------------------------------------------------------------------------

function generateId(): string {
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
}

export interface Widget {
  id: string;
  title: string;
  g2_spec: Record<string, unknown>;
  data: Record<string, unknown>;
  sql?: string;
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
}

export interface DashboardPatch {
  op: 'add' | 'remove' | 'replace';
  path: string;
  value?: unknown;
}

export interface ChatMessage {
  id: string;
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp: string;
  metadata?: {
    sql?: string;
    g2_spec?: Record<string, unknown>;
    widgetId?: string;
    actionCalls?: ActionCall[];
    actionResults?: ActionCallResult[];
  };
}

export interface DashboardState {
  uid: string;
  title: string;
  widgets: Record<string, Widget>;
  conversationId: string;
  messages: ChatMessage[];
  isLoading: boolean;
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
  updateLayout: (widgetId: string, layout: WidgetLayout) => void;
  executeAction: (action: ActionCall) => void;
  appendAnswer: (text: string) => void;
  setSql: (widgetId: string, sql: string) => void;
  setError: (error: string) => void;
  completeConversation: () => void;
  saveDraft: () => Promise<void>;
  setConversationId: (id: string) => void;
  toggleAutoSave: () => void;
  getDashboardContext: () => object;
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

        completeConversation: () => {
          set((state) => {
            state.isLoading = false;
          });
        },

        // ------- Widget CRUD -------

        addPanel: (widget: Widget) => {
          set((state) => {
            state.widgets[widget.id] = widget;
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

        updateLayout: (widgetId: string, layout: WidgetLayout) => {
          set((state) => {
            const widget = state.widgets[widgetId];
            if (!widget) return;
            widget.layout = { ...layout };
            state.isDirty = true;
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
          });
        },

        // ------- Conversation ID -------

        setConversationId: (id: string) => {
          set((state) => {
            state.conversationId = id;
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
            widgetDict[w.id] = {
              id: w.id,
              title: w.title,
              sql: w.sql ?? null,
              layout: w.layout,
            };
          }
          return {
            dashboard_uid: uid,
            conversation_id: conversationId,
            widgets: widgetDict,
          };
        },
      };
    }),
    {
      limit: 50,
      partialize: (state: any) => {
        const { widgets, title, uid, conversationId } = state;
        return { widgets, title, uid, conversationId };
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
  const { uid, title, widgets, isDirty } = _store.getState() as DashboardState;
  if (!isDirty) return;

  const schema = buildSchema(widgets);

  try {
    const url = uid
      ? `/extends/Chat2VizDashboard/api_update/uid/${uid}`
      : `/extends/Chat2VizDashboard/api_create`;

    const method = uid ? 'PUT' : 'POST';

    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(
        uid ? { current_schema: schema } : { title, current_schema: schema },
      ),
    });

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
    useDashboardStore.setState({
      error: e instanceof Error ? e.message : '保存失败',
    });
  }
}
