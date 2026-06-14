import { useState, useEffect, useCallback, useRef } from 'react';
import { Button, Input, Modal, Tag, Tooltip, message } from 'antd';
import { ArrowLeftOutlined, CloudOutlined, CloudSyncOutlined, CloudUploadOutlined } from '@ant-design/icons';
import { getPageProps, navigate } from './adapters';
import { ADMIN_BASE } from './utils/routes';
import { useDashboardStore } from './store/dashboardStore';
import type { ChatMessage, MessageStatus } from './store/dashboardStore';
import { useDashboardDraft } from './hooks/useDashboardDraft';
import ChatPanel from './components/ChatPanel';
import PreviewPanel from './components/PreviewPanel';
import PublishDialog from './components/PublishDialog';

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
  const { dashboard } = getPageProps<DashboardEditProps>();

  const title = useDashboardStore((s) => s.title);
  const uid = useDashboardStore((s) => s.uid);
  const isDirty = useDashboardStore((s) => s.isDirty);
  const lastSavedAt = useDashboardStore((s) => s.lastSavedAt);
  // Endpoint-level store.error is rendered exclusively by ChatPanel's global
  // Alert (design D3) — the duplicate top-bar Tag has been removed so the two
  // never coexist. This component intentionally does not subscribe to error.
  const streamingState = useDashboardStore((s) => s.streamingState);

  // Initialize store from server props — re-hydrate when navigating to a
  // different dashboard (Inertia client-side navigation in v14/v15) or on
  // first mount (v13 full page load).
  useEffect(() => {
    if (!dashboard) return;
    if (dashboard.uid === useDashboardStore.getState().uid) return;

    useDashboardStore.setState({
      uid: dashboard.uid || '',
      title: dashboard.title || '',
    });

    // Hydrate widgets from current_schema if present
    // NOTE: ThinkPHP M()->find() returns current_schema as a JSON string.
    // The server-side renderer (SmartyRenderer) should parse it, but we add
    // a client-side fallback for robustness.
    let schema = dashboard.current_schema as { widgets?: any[] } | string | null;
    if (typeof schema === 'string') {
      try { schema = JSON.parse(schema); } catch { schema = null; }
    }
    if (schema && typeof schema === 'object' && Array.isArray(schema.widgets)) {
      const widgetsMap: Record<string, any> = {};
      for (const w of schema.widgets) {
        if (w.id) {
          widgetsMap[w.id] = {
            id: w.id,
            title: w.title || '未命名',
            g2_spec: w.g2_spec || {},
            data: w.data || {},
            sql: w.sql,
            refreshInterval: w.refreshInterval,
            layout: w.layout || { x: 0, y: 0, w: 12, h: 6 },
          };
        }
      }
      useDashboardStore.setState({ widgets: widgetsMap });

      // Fetch data for widgets that have SQL but no data
      const uid = dashboard.uid;
      for (const w of schema.widgets) {
        if (w.id && w.sql && (!w.data || Object.keys(w.data || {}).length === 0)) {
          fetch(`${ADMIN_BASE}/api_draft_widget_data?uid=${encodeURIComponent(uid)}&widgetId=${encodeURIComponent(w.id)}`, {
            credentials: 'same-origin',
          })
            .then((r) => r.json())
            .then((result) => {
              if (result.status === 1 && Array.isArray(result.data) && result.data.length > 0) {
                useDashboardStore.getState().updateWidget(w.id, { data: { rows: result.data as Record<string, unknown>[] } });
              }
            })
            .catch(() => { /* non-critical */ });
        }
      }
    }
  }, [dashboard?.uid]);

  // ---- Load conversation history from server ----
  useEffect(() => {
    if (!dashboard?.uid) return;

    const store = useDashboardStore.getState();
    // Skip if messages already loaded for this conversation
    if (store.conversationId && store.messages.length > 0) return;

    fetch(`/extends/Chat2Viz/api_conversation_history?uid=${encodeURIComponent(dashboard.uid)}`, {
      credentials: 'same-origin',
    })
      .then((r) => r.json())
      .then((result) => {
        if (result.status !== 1 || !result.data?.messages?.length) return;

        const conversationId = result.data.conversation_id
          ? String(result.data.conversation_id)
          : '';

        const msgs: ChatMessage[] = result.data.messages
          .filter((m: any) => m.role === 'user' || m.role === 'assistant')
          .map((m: any) => {
            let metadata: any;
            if (!m.metadata) {
              metadata = undefined;
            } else if (typeof m.metadata !== 'string') {
              metadata = m.metadata;
            } else {
              try { metadata = JSON.parse(m.metadata); } catch { metadata = undefined; }
            }

            return {
              id: String(m.id ?? Math.random().toString(36).slice(2)),
              role: m.role,
              content: m.content || '',
              timestamp: m.created_at || new Date().toISOString(),
              message_status: (['streaming', 'complete', 'interrupted', 'failed'].includes(m.message_status)
                ? m.message_status : 'complete') as MessageStatus | undefined,
              metadata,
            };
          });

        const update: Partial<import('./store/dashboardStore').DashboardState> = { messages: msgs };
        if (conversationId) {
          update.conversationId = conversationId;
        }
        useDashboardStore.setState(update);
      })
      .catch(() => {
        // Non-critical: conversation history is best-effort
      });
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
  const handlePublish = useCallback(async () => {
    if (isDirty) {
      try {
        await saveNow();
      } catch {
        message.error('保存失败，请重试');
        return;
      }
      // Re-check error state after save — saveNow may have set store.error
      const storeError = useDashboardStore.getState().error;
      if (storeError) {
        message.error('保存失败，请重试');
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
          <Button type="primary" icon={<CloudUploadOutlined />} onClick={handlePublish} loading={isSaving && publishVisible}>
            发布
          </Button>
        </div>
      </div>

      {/* ---- Main Content: Dual-pane ---- */}
      <div className="dashboard-edit-content" style={styles.content}>
        <div className="dashboard-edit-chat-pane" style={styles.chatPane}>
          <ChatPanel disabled={!socketAvailable} />
        </div>
        <div className="dashboard-edit-preview-pane" style={styles.previewPane}>
          <PreviewPanel />
        </div>
      </div>

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
  },
  chatPane: {
    width: '30%',
    minWidth: 320,
    maxWidth: 480,
    flexShrink: 0,
  },
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
}
`;
