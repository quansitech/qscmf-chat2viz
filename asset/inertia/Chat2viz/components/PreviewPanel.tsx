import { useCallback, useMemo, useState } from 'react';
import { Empty, Spin, Typography } from 'antd';
import { LayoutOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import WidgetCard from './WidgetCard';
import { useDashboardStore } from '../store/dashboardStore';
import { ADMIN_BASE } from '../utils/routes';
import type { Widget, WidgetLayout } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// WidthProvider wraps RGL to auto-track container width
// ---------------------------------------------------------------------------

const ResponsiveGridLayout = WidthProvider(RGL);

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const COLS = 24;
const ROW_HEIGHT = 60;
const COMPACT_TYPE: ('vertical' | 'horizontal' | null) = 'vertical';

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

interface PreviewPanelProps {
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
}

export default function PreviewPanel({ showSql = false }: PreviewPanelProps) {
  const widgets = useDashboardStore((s) => s.widgets);
  const updateWidget = useDashboardStore((s) => s.updateWidget);
  const updateLayout = useDashboardStore((s) => s.updateLayout);
  const markWidgetUserSized = useDashboardStore((s) => s.markWidgetUserSized);
  const removePanel = useDashboardStore((s) => s.removePanel);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const uid = useDashboardStore((s) => s.uid);
  const queryClient = useQueryClient();
  const [refreshing, setRefreshing] = useState<Record<string, boolean>>({});
  const [dragging, setDragging] = useState(false);

  const widgetList = useMemo(() => Object.values(widgets) as Widget[], [widgets]);
  const hasWidgets = widgetList.length > 0;

  // ---- Build react-grid-layout layout array from store widgets ----
  const layout: Layout[] = useMemo(
    () =>
      widgetList.map((w) => {
        const l = w.layout || { x: 0, y: 0, w: 12, h: 6 };
        return {
          i: w.id,
          x: l.x,
          y: l.y,
          w: l.w,
          h: l.h,
          minW: 4,
          minH: 3,
        };
      }),
    [widgetList],
  );

  // ---- Layout change handler ----
  const handleLayoutChange = useCallback(
    (newLayout: Layout[]) => {
      for (const item of newLayout) {
        const widgetLayout: WidgetLayout = { x: item.x, y: item.y, w: item.w, h: item.h };
        updateLayout(item.i, widgetLayout);
      }
    },
    [updateLayout],
  );

  // ---- Widget actions ----
  const handleTitleChange = useCallback(
    (widgetId: string, title: string) => {
      updateWidget(widgetId, { title });
    },
    [updateWidget],
  );

  const handleRemove = useCallback(
    (widgetId: string) => {
      removePanel(widgetId);
    },
    [removePanel],
  );

  const handleRefresh = useCallback(
    async (widgetId: string) => {
      if (!uid) return;
      // Refresh ONLY this widget — invalidate its single query key and refetch.
      // The backend api_draft_widget_data filters strictly by widgetId, so no
      // other widget is touched (previously refreshKey was set but never read).
      setRefreshing((r) => ({ ...r, [widgetId]: true }));
      try {
        const resp = await fetch(
          `${ADMIN_BASE}/api_draft_widget_data?uid=${encodeURIComponent(uid)}&widgetId=${encodeURIComponent(widgetId)}`,
          { credentials: 'same-origin' },
        );
        const result = await resp.json();
        if (result.status === 1 && Array.isArray(result.data)) {
          updateWidget(widgetId, { data: result.data as Record<string, unknown>[] });
        }
        // Also drop this key from the react-query cache so the next hydration
        // re-fetches fresh data instead of serving the stale 5min entry.
        queryClient.removeQueries({ queryKey: ['widget-data', uid, widgetId, 'draft'] });
      } catch {
        // Non-fatal: leave the existing data in place.
      } finally {
        setRefreshing((r) => {
          const next = { ...r };
          delete next[widgetId];
          return next;
        });
      }
    },
    [uid, updateWidget, queryClient],
  );

  // ---- Regenerate (error-state widget) ----
  const handleRegenerate = useCallback((_widgetId: string) => {
    // Placeholder: regeneration requires a new SSE ask. For now, just clear the
    // error so the skeleton re-shows; a future iteration can re-issue the last
    // question scoped to this widget.
    updateWidget(_widgetId, { status: 'loading' });
  }, [updateWidget]);

  // ---- Empty state ----
  if (!hasWidgets) {
    return (
      <div style={styles.emptyContainer}>
        <Empty
          image={<LayoutOutlined style={{ fontSize: 48, color: '#bfbfbf' }} />}
          description={
            <div style={{ textAlign: 'center' }}>
              <Typography.Text type="secondary" strong>
                还没有图表
              </Typography.Text>
              <div style={{ marginTop: 4 }}>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  在左侧输入你的问题，AI 会自动生成对应的图表。例如：
                </Typography.Text>
              </div>
              <div style={{ marginTop: 6, fontSize: 12, color: '#8c8c8c' }}>
                「查看月度销售趋势」「按产品类别对比收入」
              </div>
            </div>
          }
        />
      </div>
    );
  }

  return (
    <div style={{ ...styles.container, overflow: dragging ? 'hidden' : 'auto' }}>
      <ResponsiveGridLayout
        layout={layout}
        cols={COLS}
        rowHeight={ROW_HEIGHT}
        onLayoutChange={handleLayoutChange}
        onDragStart={() => setDragging(true)}
        onDragStop={() => setDragging(false)}
        onResizeStart={() => setDragging(true)}
        onResizeStop={(_layout, oldItem) => {
          setDragging(false);
          // User manually resized → freeze auto-height for this widget so its
          // chosen size isn't recomputed on the next data refresh.
          if (oldItem) markWidgetUserSized(oldItem.i);
        }}
        compactType={COMPACT_TYPE}
        draggableHandle=".widget-header"
        isResizable={true}
        isDraggable={true}
        margin={[12, 12]}
        useCSSTransforms={true}
      >
        {widgetList.map((w) => (
          <div key={w.id} style={styles.gridItem}>
            <WidgetCard
              widget={w}
              onTitleChange={handleTitleChange}
              onRemove={handleRemove}
              onRefresh={handleRefresh}
              onRegenerate={handleRegenerate}
              refreshing={!!refreshing[w.id]}
              showSql={showSql}
            />
          </div>
        ))}
      </ResponsiveGridLayout>
      {streamingState !== 'idle' && hasWidgets && (
        <div style={{
          position: 'absolute',
          top: 0, left: 0, right: 0, bottom: 0,
          background: 'rgba(255, 255, 255, 0.6)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          cursor: 'not-allowed',
          zIndex: 10,
        }}>
          <Spin tip="AI 正在生成图表..." />
        </div>
      )}
      <style>{widgetFadeInCss}</style>
    </div>
  );
}

// ---------------------------------------------------------------------------
// CSS for entry animation
// ---------------------------------------------------------------------------

const widgetFadeInCss = `
@keyframes widgetFadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
`;

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  container: {
    position: 'relative',
    height: '100%',
    overflow: 'auto',
    padding: 12,
    background: '#fafafa',
  },
  emptyContainer: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    background: '#fafafa',
    borderRadius: 8,
  },
  gridItem: {
    // Ensure the grid item fills the RGL cell so WidgetCard can use height:100%
  },
};
