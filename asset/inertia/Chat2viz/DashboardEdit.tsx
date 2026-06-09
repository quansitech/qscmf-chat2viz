import { useState, useEffect, useCallback, useRef } from 'react';
import { Button, Input, Tag, Tooltip } from 'antd';
import { ArrowLeftOutlined, CloudOutlined, CloudSyncOutlined, CloudUploadOutlined } from '@ant-design/icons';
import { getPageProps, navigate } from './adapters';
import { useDashboardStore } from './store/dashboardStore';
import type { ChatMessage } from './store/dashboardStore';
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
  conversation_id?: string;
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
  const error = useDashboardStore((s) => s.error);

  // Initialize store from server props — re-hydrate when navigating to a
  // different dashboard (Inertia client-side navigation in v14/v15) or on
  // first mount (v13 full page load).
  useEffect(() => {
    if (!dashboard) return;
    if (dashboard.uid === useDashboardStore.getState().uid) return;

    useDashboardStore.setState({
      uid: dashboard.uid || '',
      title: dashboard.title || '',
      conversationId: dashboard.conversation_id || '',
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
        const msgs: ChatMessage[] = result.data.messages
          .filter((m: any) => m.role === 'user' || m.role === 'assistant')
          .map((m: any) => ({
            id: String(m.id ?? Math.random().toString(36).slice(2)),
            role: m.role,
            content: m.content || '',
            timestamp: m.created_at || new Date().toISOString(),
            metadata: m.metadata
              ? typeof m.metadata === 'string'
                ? JSON.parse(m.metadata)
                : m.metadata
              : undefined,
          }));
        if (msgs.length > 0) {
          useDashboardStore.setState({ messages: msgs });
        }
      })
      .catch(() => {
        // Non-critical: conversation history is best-effort
      });
  }, [dashboard?.uid]);

  // ---- Draft auto-save hook ----
  useDashboardDraft();

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
              onClick={() => navigate('/extends/Chat2VizDashboard/index')}
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
          {error && <Tag color="error">{error}</Tag>}
          <Button type="primary" icon={<CloudUploadOutlined />} onClick={() => setPublishVisible(true)}>
            发布
          </Button>
        </div>
      </div>

      {/* ---- Main Content: Dual-pane ---- */}
      <div className="dashboard-edit-content" style={styles.content}>
        <div className="dashboard-edit-chat-pane" style={styles.chatPane}>
          <ChatPanel />
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
