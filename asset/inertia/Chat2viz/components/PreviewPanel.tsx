import { useCallback, useMemo, useState } from 'react';
import { Empty, Spin, Typography } from 'antd';
import { LayoutOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import type { Layout } from 'react-grid-layout';
import DashboardGrid from './DashboardGrid';
import WidgetCard from './WidgetCard';
import { useDashboardStore } from '../store/dashboardStore';
import { ADMIN_BASE } from '../utils/routes';
import type { Widget, WidgetLayout } from '../store/dashboardStore';

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

  // ---- Layout change handler (writes back to the store) ----
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
    <div style={styles.viewportWrap}>
      <div style={{ ...styles.container, overflow: dragging ? 'hidden' : 'auto' }}>
        {/* 常驻布局提示:有图表时引导用户手动操作(拖拽/缩放/删除),
            不让这些空间类操作走 LLM 对话。极低视觉权重(灰字小号),
            始终可见——空状态有自己的引导,故仅此处显示。 */}
        <div style={{ padding: '4px 12px', borderBottom: '1px solid #f0f0f0' }}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            ✋ 拖拽移动 · 边角缩放 · 🗑️ 删除
          </Typography.Text>
        </div>
        <DashboardGrid
          widgets={widgetList}
          readonly={streamingState !== 'idle'}
          onLayoutChange={handleLayoutChange}
          onDragStart={() => setDragging(true)}
          onDragStop={() => setDragging(false)}
          onResizeStart={() => setDragging(true)}
          onResizeStop={(oldItem) => {
            // User manually resized → freeze auto-height for this widget so its
            // chosen size isn't recomputed on the next data refresh.
            markWidgetUserSized(oldItem.i);
          }}
          renderCard={(w) => (
            <WidgetCard
              widget={w as Widget}
              onTitleChange={handleTitleChange}
              onRemove={handleRemove}
              onRefresh={handleRefresh}
              onRegenerate={handleRegenerate}
              refreshing={!!refreshing[w.id]}
              showSql={showSql}
              editable={streamingState === 'idle'}
            />
          )}
        />
      </div>
      {/*
        流式生成期间整个图表区域显示 loading 遮罩。遮罩挂在 viewportWrap(不可滚动,
        高度=视口可视区) 而非内部的滚动 container 上 —— 旧实现 absolute 锚定到可
        滚动 container 的 bottom:0, 多图表时 container 实际高度远超视口, 遮罩要么
        只盖住初始视口、滚出去的图表露在外面仍可交互, 要么居中 Spin 跑到滚动区中段
        看不见。现在遮罩覆盖 viewportWrap 的整个可视区, 滚动内容在其下方独立滚动,
        遮罩始终钉在视口上。
          - 主防线(功能层): RGL isDraggable/isResizable={false} + WidgetCard
            editable={false} 禁用所有交互, 从根上阻止拖拽/缩放/删除/编辑.
          - 视觉+交互层(本遮罩): 半透明蒙层 + 居中大 Spin + 文案, 且 pointer-events
            拦截指针作为双重防线.
      */}
      {streamingState !== 'idle' && hasWidgets && (
        <div style={{
          position: 'absolute',
          top: 0, left: 0, right: 0, bottom: 0,
          background: 'rgba(255, 255, 255, 0.75)',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          gap: 12,
          zIndex: 20,
        }}>
          <Spin size="large" />
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>
            AI 正在生成图表，请稍候…
          </Typography.Text>
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  // viewportWrap: 不可滚动的定位上下文, 高度填满父级(previewPane)。loading 遮罩
  // 以它为 absolute 锚点, 因此遮罩永远覆盖整个可视区, 不随内部滚动内容移动。
  // overflow:hidden 防止内部 container 的滚动溢出影响遮罩定位。
  viewportWrap: {
    position: 'relative' as const,
    height: '100%',
    width: '100%',
    overflow: 'hidden' as const,
    display: 'flex',
    flexDirection: 'column' as const,
  },
  // container: 实际的滚动容器, flex:1 填满 viewportWrap 的剩余空间并独立滚动。
  container: {
    flex: 1,
    minHeight: 0,
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
};
