import { useState, useEffect, useCallback, useRef } from 'react';
import { Button, Input, Modal, Tag, Tooltip, message } from 'antd';
import { ArrowLeftOutlined, CloudOutlined, CloudSyncOutlined, CloudUploadOutlined, MessageOutlined } from '@ant-design/icons';
import { useQueries, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { getPageProps, navigate } from './adapters';
import { ADMIN_BASE } from './utils/routes';
import { useDashboardStore, normalizeRows } from './store/dashboardStore';
import type { ChatMessage, MessageStatus } from './store/dashboardStore';
import type { DashboardDSL, WidgetSpec, SSEDashboardReplaceV3 } from './types/dsl';
import { isDashboardReplaceV3 } from './types/dsl';
import { suggestHeight } from './utils/suggestHeight';
import { useDashboardDraft } from './hooks/useDashboardDraft';
import ChatPanel from './components/ChatPanel';
import PreviewPanel from './components/PreviewPanel';
import PublishDialog from './components/PublishDialog';
import { createSemaphore } from './utils/concurrency';
import './plugins'; // register the five widget plugins at module load

// ---------------------------------------------------------------------------
// QueryClient — scoped to the edit page (mirrors DashboardView). Used only for
// the two pure-HTTP scenarios: re-fetching widget data when reopening a saved
// dashboard, and single-widget manual refresh. SSE remains the single source
// of truth during a live stream; query results write back to the store via
// onSuccess, so there is never a dual-source conflict.
// ---------------------------------------------------------------------------

// QueryClient created per-mount via useState factory (task 7.5: module-scope
// QueryClient leaks cache across SPA navigations / different dashboards).
function useQueryClient() {
  return useState(() => new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 5 * 60 * 1000, // 5 minutes
        retry: 1,
        refetchOnWindowFocus: false,
      },
    },
  }))[0];
}

/**
 * Shared concurrency cap (6) wrapping every widget-data HTTP request on this
 * page. react-query already de-dupes by key and honors the 5min staleTime; this
 * semaphore is a defensive backstop that protects the backend DB connection
 * pool and aligns with the browser's HTTP/1.1 per-origin connection ceiling.
 */
const limitWidgetFetch = createSemaphore(6);

/** Fetch a single widget's draft data. Returns bare Row[] (throws on API error). */
async function fetchWidgetData(uid: string, widgetId: string): Promise<Record<string, unknown>[]> {
  return limitWidgetFetch(async () => {
    const resp = await fetch(
      `${ADMIN_BASE}/api_draft_widget_data?uid=${encodeURIComponent(uid)}&widgetId=${encodeURIComponent(widgetId)}`,
      { credentials: 'same-origin' },
    );
    const result = await resp.json();
    if (result.status !== 1) {
      throw new Error(result.info || '图表数据加载失败');
    }
    return Array.isArray(result.data) ? (result.data as Record<string, unknown>[]) : [];
  });
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DashboardData {
  uid: string;
  title: string;
  current_schema: Record<string, unknown>;
  status?: string;
}

interface DashboardEditProps {
  dashboard: DashboardData | null;
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  show_sql?: boolean;
}

// ---------------------------------------------------------------------------
// Undo/Redo throttle helper
// ---------------------------------------------------------------------------

function useThrottledAction(action: () => void, delayMs: number) {
  const lastCallRef = useRef(0);
  return useCallback(() => {
    const now = Date.now();
    if (now - lastCallRef.current < delayMs) return;
    lastCallRef.current = now;
    action();
  }, [action, delayMs]);
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function DashboardEdit() {
  const queryClient = useQueryClient();
  return (
    <QueryClientProvider client={queryClient}>
      <DashboardEditInner />
    </QueryClientProvider>
  );
}

function DashboardEditInner() {
  const { dashboard, show_sql = false } = getPageProps<DashboardEditProps>();

  const title = useDashboardStore((s) => s.title);
  const uid = useDashboardStore((s) => s.uid);
  const isDirty = useDashboardStore((s) => s.isDirty);
  const lastSavedAt = useDashboardStore((s) => s.lastSavedAt);
  // Endpoint-level store.error is rendered exclusively by ChatPanel's global
  // Alert (design D3) — the duplicate top-bar Tag has been removed so the two
  // never coexist. This component intentionally does not subscribe to error.
  const streamingState = useDashboardStore((s) => s.streamingState);

  // ---- HTTP widget-data hydration (reopening a saved dashboard) ----
  // The hydrate effect (below) records which widgets need an HTTP data fetch
  // (persisted sql, no inline data) and bumps fetchToken. useQueries then
  // issues them with bounded concurrency (semaphore) + per-key caching. Only
  // the keys present here are ever requested; SSE stays the single source of
  // truth during live streaming and never overlaps these keys.
  const pendingFetchRef = useRef<{ uid: string; widgetId: string }[]>([]);
  const [fetchToken, setFetchToken] = useState(0);
  const fetchTargets = pendingFetchRef.current;

  const widgetQueries = useQueries({
    queries: fetchTargets.map(({ uid: wUid, widgetId }) => ({
      queryKey: ['widget-data', wUid, widgetId, 'draft'],
      // fetchWidgetData is internally throttled by the shared 6-wide semaphore;
      // react-query de-dupes by key + honors the 5min staleTime on top.
      queryFn: () => fetchWidgetData(wUid, widgetId),
      staleTime: 5 * 60 * 1000,
      enabled: fetchToken > 0,
    })),
  });

  // Write fetched rows back into the store's widgetDataCache (one-way query → store).
  const fetchedSignature = widgetQueries.map((q) => q.dataUpdatedAt).join(',');
  useEffect(() => {
    fetchTargets.forEach(({ widgetId }, i) => {
      const q = widgetQueries[i];
      if (q?.isSuccess && Array.isArray(q.data) && q.data.length > 0) {
        useDashboardStore.getState().updateWidgetDataCache(widgetId, {
          rows: q.data as Record<string, unknown>[],
        });
      }
    });
    // widgetQueries identity changes per render; depend on the data-update
    // signature + fetchToken instead.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fetchedSignature, fetchToken]);

  // Initialize store from server props — re-hydrate when navigating to a
  // different dashboard (Inertia client-side navigation in v14/v15) or on
  // first mount (v13 full page load).
  useEffect(() => {
    // DEF-14: for new (unsaved) dashboards, PHP passes dashboard=null. Set a
    // dated default title so the list doesn't accumulate empty-title drafts.
    if (!dashboard) {
      const cur = useDashboardStore.getState();
      if (!cur.uid && !cur.title) {
        const today = new Date().toISOString().slice(0, 10);
        useDashboardStore.setState({ title: '未命名仪表盘 ' + today });
      }
      return;
    }
    if (dashboard.uid === useDashboardStore.getState().uid) return;

    // DEF-14: also cover the case where dashboard exists but has no uid/title.
    let initTitle = dashboard.title || '';
    if (!dashboard.uid && !initTitle) {
      const today = new Date().toISOString().slice(0, 10);
      initTitle = '未命名仪表盘 ' + today;
    }

    useDashboardStore.setState({
      uid: dashboard.uid || '',
      title: initTitle,
    });

    // Hydrate the DSL from current_schema. v3 path: current_schema is a full
    // DSL AST (version/layout/queries/widgets/slicers/interactions) → replaceDSL.
    // NOTE: ThinkPHP M()->find() returns current_schema as a JSON string.
    // The server-side renderer (SmartyRenderer) should parse it, but we add
    // a client-side fallback for robustness.
    let schema = dashboard.current_schema as unknown;
    if (typeof schema === 'string') {
      try { schema = JSON.parse(schema); } catch { schema = null; }
    }
    if (schema && typeof schema === 'object' && isDashboardReplaceV3(schema)) {
      // v3 DSL hydration: feed the AST through replaceDSL so the store builds
      // the full dsl + widgetDataCache. Generated widgets' data is used as-is;
      // widgets whose data is null (slim / persisted) are recorded for an HTTP
      // fetch (form A draft fetch).
      const payload = schema as SSEDashboardReplaceV3;
      useDashboardStore.getState().replaceDSL(payload);

      // Record widgets needing an HTTP data fetch (persisted with data:null).
      const needsFetch: { uid: string; widgetId: string }[] = [];
      const hydratedDsl = useDashboardStore.getState().dsl;
      if (hydratedDsl) {
        for (const widgetId of Object.keys(hydratedDsl.widgets)) {
          const cache = useDashboardStore.getState().widgetDataCache[widgetId];
          // Fetch when there's no inline data (status loading or empty cache).
          if (!cache || cache.rows.length === 0) {
            needsFetch.push({ uid: dashboard.uid, widgetId });
          }
        }
      }
      pendingFetchRef.current = needsFetch;
      setFetchToken((t) => t + 1);
    }
  }, [dashboard?.uid]);

  // ---- Load conversation history from server ----
  useEffect(() => {
    if (!dashboard?.uid) return;

    const store = useDashboardStore.getState();
    // Skip if messages already loaded for this conversation — already hydrated.
    if (store.conversationId && store.messages.length > 0) {
      useDashboardStore.setState({ historyStatus: 'ready' });
      return;
    }

    // AbortController prevents stale-response race: if uid changes before the
    // fetch resolves, the late response is discarded (task 7.4).
    const abortController = new AbortController();

    // Mark history as loading so the chat panel can disable send + show a
    // spinner until the conversation is hydrated.
    useDashboardStore.setState({ historyStatus: 'loading' });

    fetch(`/extends/Chat2Viz/api_conversation_history?uid=${encodeURIComponent(dashboard.uid)}`, {
      credentials: 'same-origin',
      signal: abortController.signal,
    })
      .then((r) => r.json())
      .then((result) => {
        if (result.status !== 1 || !result.data?.messages?.length) {
          // No history (new dashboard) — ready, not error.
          useDashboardStore.setState({ historyStatus: 'ready' });
          return;
        }

        const conversationId = result.data.conversation_id
          ? String(result.data.conversation_id)
          : '';

        const msgs: ChatMessage[] = result.data.messages
          .filter((m: any) => m.role === 'user' || m.role === 'assistant')
          .map((m: any, i: number) => {
            let metadata: any;
            if (!m.metadata) {
              metadata = undefined;
            } else if (typeof m.metadata !== 'string') {
              metadata = m.metadata;
            } else {
              try { metadata = JSON.parse(m.metadata); } catch { metadata = undefined; }
            }

            return {
              // Deterministic id: prefer server id; if missing, derive from
              // index+role (stable across re-renders). Math.random() caused
              // React key instability — every re-render produced new keys.
              id: String(m.id ?? `${i}-${m.role}`),
              role: m.role,
              content: m.content || '',
              // DEFECT_DIAGNOSIS 缺陷5: hydrate reasoning_content → thought so the
              // "💭 AI 思考过程" panel survives page refresh. reasoning_content is
              // written to DB by ConversationService.finalizeStream and returned by
              // api_conversation_history (full-row ->toArray(), no select filter),
              // but this hydration previously omitted it → ChatMessage.thought was
              // always undefined after reload. The streaming path (appendThought →
              // lastAssistant.thought) and the history path now share one field.
              thought: m.reasoning_content || '',
              timestamp: m.created_at || new Date().toISOString(),
              message_status: (['streaming', 'complete', 'interrupted', 'failed'].includes(m.message_status)
                ? m.message_status : 'complete') as MessageStatus | undefined,
              metadata,
            };
          });

        const update: Partial<import('./store/dashboardStore').DashboardState> = {
          messages: msgs,
          historyStatus: 'ready',
        };
        if (conversationId) {
          update.conversationId = conversationId;
        }
        useDashboardStore.setState(update);
      })
      .catch((err) => {
        // AbortController.abort() on uid change/unmount — silent, not an error
        if (abortController.signal.aborted) return;
        // Non-critical: conversation history is best-effort. Surface as ready
        // (not error) so the user can still ask new questions, but tell the
        // user the history failed to load (otherwise the empty chat looks like
        // a bug rather than a network failure).
        useDashboardStore.setState({ historyStatus: 'ready' });
        message.warning('对话历史加载失败，已开始新对话。你仍可以继续提问。', 4);
      });

    // Cleanup: abort in-flight fetch on unmount or uid change (task 7.4 race fix)
    return () => abortController.abort();
  }, [dashboard?.uid]);

  // ---- Draft auto-save hook ----
  const { saveNow, isSaving, isDirty: draftIsDirty, lastSavedAt: draftLastSavedAt } = useDashboardDraft();

  // ---- Undo/Redo keyboard shortcuts (T25) ----
  const throttledUndo = useThrottledAction(() => {
    const temporal = useDashboardStore.temporal?.getState();
    if (temporal?.pastStates && temporal.pastStates.length > 0) {
      useDashboardStore.temporal.undo();
    }
  }, 300);

  const throttledRedo = useThrottledAction(() => {
    const temporal = useDashboardStore.temporal?.getState();
    if (temporal?.futureStates && temporal.futureStates.length > 0) {
      useDashboardStore.temporal.redo();
    }
  }, 300);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      // Ctrl+Z: Undo
      if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
        e.preventDefault();
        throttledUndo();
      }
      // Ctrl+Shift+Z or Ctrl+Y: Redo
      if (
        ((e.ctrlKey || e.metaKey) && e.key === 'z' && e.shiftKey) ||
        ((e.ctrlKey || e.metaKey) && e.key === 'y')
      ) {
        e.preventDefault();
        throttledRedo();
      }
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [throttledUndo, throttledRedo]);

  // ---- Title update ----
  const handleTitleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    useDashboardStore.setState({ title: e.target.value, isDirty: true });
  }, []);

  // ---- Publish dialog state ----
  const [publishVisible, setPublishVisible] = useState(false);

  // ---- Chat pane collapse (lets the preview fill the width, approximating
  // the published view; restored by clicking the floating button) ----
  const [chatCollapsed, setChatCollapsed] = useState(false);

  // ---- Socket health check ----
  const [socketAvailable, setSocketAvailable] = useState(true);
  const healthCheckedRef = useRef(false);

  useEffect(() => {
    if (healthCheckedRef.current) return;
    healthCheckedRef.current = true;

    fetch('/extends/Chat2Viz/api_socket_health', { credentials: 'same-origin' })
      .then((r) => {
        if (r.ok) return;
        throw new Error('unavailable');
      })
      .then(() => setSocketAvailable(true))
      .catch(() => {
        setSocketAvailable(false);
        Modal.error({
          title: '服务不可用',
          content: '分析服务不可用，请检查后端服务状态。对话功能已禁用。',
        });
      });
  }, []);

  // ---- Publish handler: save before opening dialog ----
  // NOTE: the previous version awaited saveNow() with NO visible feedback
  // (the publish button's loading was gated on `publishVisible`, which is only
  // set AFTER the save resolves). If saveNow hung or silently swallowed its
  // error, setPublishVisible(true) never ran → no dialog, no request, the user
  // saw a dead button. Now: show a saving hint while awaiting, and surface
  // save failures clearly.
  const [publishSaving, setPublishSaving] = useState(false);
  const handlePublish = useCallback(async () => {
    if (isDirty) {
      setPublishSaving(true);
      try {
        await saveNow();
      } catch {
        setPublishSaving(false);
        message.error('保存失败，无法发布，请重试');
        return;
      }
      // Re-check error state after save — saveNow may have set store.error
      const storeError = useDashboardStore.getState().error;
      setPublishSaving(false);
      if (storeError) {
        message.error('保存失败，无法发布，请重试');
        return;
      }
    }
    setPublishVisible(true);
  }, [isDirty, saveNow]);

  // ---- Save status indicator ----
  const saveStatusText = isDirty
    ? '未保存'
    : lastSavedAt
      ? `已保存于 ${new Date(lastSavedAt).toLocaleTimeString()}`
      : '无变更';

  const SaveIcon = isDirty ? CloudSyncOutlined : CloudOutlined;

  return (
    <div style={styles.root}>
      {/* ---- Top Bar ---- */}
      <div style={styles.topBar}>
        <div style={styles.topBarLeft}>
          <Tooltip title="返回列表">
            <Button
              type="text"
              icon={<ArrowLeftOutlined />}
              aria-label="返回列表"
              onClick={() => navigate(`${ADMIN_BASE}/index`)}
            />
          </Tooltip>
          <Input
            value={title}
            onChange={handleTitleChange}
            placeholder="仪表盘标题"
            bordered={false}
            style={styles.titleInput}
          />
        </div>
        <div style={styles.topBarRight}>
          <Tooltip title={saveStatusText}>
            <Tag icon={<SaveIcon />} color={isDirty ? 'warning' : 'success'}>
              {saveStatusText}
            </Tag>
          </Tooltip>
          <Button
            onClick={saveNow}
            loading={isSaving}
            disabled={!isDirty}
            style={{ marginRight: 8 }}
          >
            保存
          </Button>
          <Button type="primary" icon={<CloudUploadOutlined />} onClick={handlePublish} loading={publishSaving || (isSaving && publishVisible)}>
            发布
          </Button>
        </div>
      </div>

      {/* ---- Main Content: Dual-pane ---- */}
      <div className="dashboard-edit-content" style={styles.content}>
        {!chatCollapsed && (
          <div className="dashboard-edit-chat-pane" style={styles.chatPane}>
            <ChatPanel disabled={!socketAvailable} />
          </div>
        )}
        {/* Collapse toggle sits on the chat/preview divider line (vertical
            center), so it never overlaps the ChatPanel header's "New" button
            which lives at the top-right of the pane. */}
        {!chatCollapsed && (
          <Tooltip title={streamingState !== 'idle' ? '对话进行中，暂不可收起' : '收起对话区（预览发布效果）'}>
            <Button
              className="chat-collapse-btn"
              shape="circle"
              icon={<MessageOutlined />}
              aria-label="收起对话区"
              onClick={() => setChatCollapsed(true)}
              disabled={streamingState !== 'idle'}
              style={{
                ...styles.collapseBtn,
                width: 36,
                height: 36,
                minWidth: 36,
                opacity: streamingState !== 'idle' ? 0.4 : 1,
                cursor: streamingState !== 'idle' ? 'not-allowed' : 'pointer',
              }}
            />
          </Tooltip>
        )}
        <div className="dashboard-edit-preview-pane" style={styles.previewPane}>
          <PreviewPanel showSql={show_sql} />
        </div>
      </div>

      {/* Floating button to restore the chat pane when collapsed */}
      {chatCollapsed && (
        <Tooltip title="展开对话区" placement="left">
          <Button
            type="primary"
            shape="circle"
            icon={<MessageOutlined />}
            aria-label="展开对话区"
            onClick={() => setChatCollapsed(false)}
            style={{
              ...styles.fab,
              width: 36,
              height: 36,
              minWidth: 36,
            }}
          />
        </Tooltip>
      )}

      {/* ---- Publish Dialog ---- */}
      <PublishDialog
        uid={uid}
        title={title}
        visible={publishVisible}
        onClose={() => setPublishVisible(false)}
      />

      {/* ---- Status Bar ---- */}
      <div style={{
        padding: '4px 16px',
        borderTop: '1px solid #f0f0f0',
        background: '#fafafa',
        fontSize: 12,
        color: '#999',
        display: 'flex',
        justifyContent: 'space-between',
      }}>
        <span>
          {streamingState === 'streaming' ? 'AI 正在处理...' :
           isDirty ? '有未保存的更改 (Ctrl+S 保存)' :
           lastSavedAt ? '已保存于 ' + new Date(lastSavedAt).toLocaleTimeString() :
           '就绪'}
        </span>
      </div>

      {/* ---- Responsive layout: stack vertically on narrow screens ---- */}
      <style>{responsiveCss}</style>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  root: {
    display: 'flex',
    flexDirection: 'column',
    height: '100vh',
    background: '#f0f2f5',
  },
  topBar: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '8px 16px',
    background: '#fff',
    borderBottom: '1px solid #f0f0f0',
    flexShrink: 0,
  },
  topBarLeft: {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    flex: 1,
    minWidth: 0,
  },
  topBarRight: {
    display: 'flex',
    alignItems: 'center',
    gap: 12,
    flexShrink: 0,
  },
  titleInput: {
    fontSize: 16,
    fontWeight: 500,
  },
  content: {
    display: 'flex',
    flex: 1,
    minHeight: 0,
    position: 'relative', // anchor for the collapse toggle on the divider
  },
  chatPane: {
    width: '30%',
    minWidth: 320,
    maxWidth: 480,
    flexShrink: 0,
  },
  // Collapse toggle: pinned to the chat/preview divider, vertically centered.
  // Lives on the content layer (NOT inside ChatPanel) so it never overlaps the
  // "New" button at the pane's top-right. The chat pane width is
  // clamp(320px, 30%, 480px), so its right edge (the divider) sits at exactly
  // that offset from the content's left edge — clamp() keeps the button there.
  collapseBtn: {
    position: 'absolute',
    top: '50%',
    left: 'clamp(320px, 30%, 480px)',
    transform: 'translate(-50%, -50%)',
    zIndex: 20,
    background: '#fff',
    boxShadow: '0 1px 4px rgba(0,0,0,0.15)',
  } as React.CSSProperties,
  fab: {
    position: 'fixed',
    right: 24,
    bottom: 64,
    zIndex: 1000,
    boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
  } as React.CSSProperties,
  previewPane: {
    flex: 1,
    minWidth: 0,
    overflow: 'auto',
  },
};

// ---------------------------------------------------------------------------
// Responsive CSS: stack vertically on narrow screens
// ---------------------------------------------------------------------------

const responsiveCss = `
@media (max-width: 768px) {
  .dashboard-edit-content {
    flex-direction: column !important;
  }
  .dashboard-edit-chat-pane {
    width: 100% !important;
    max-width: none !important;
    min-width: 0 !important;
    height: 45vh;
    border-right: none !important;
    border-bottom: 1px solid #f0f0f0;
  }
  .dashboard-edit-preview-pane {
    height: 55vh;
  }
  /* The collapse/floating-button affordance is desktop-only; on mobile the
     panes already stack so collapsing would hide chat entirely. */
  .chat-collapse-btn {
    display: none !important;
  }
}
`;
