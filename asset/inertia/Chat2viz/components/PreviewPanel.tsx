import { useCallback, useMemo, useState } from 'react';
import { Empty, Spin, Typography } from 'antd';
import { LayoutOutlined } from '@ant-design/icons';
import type { Layout } from 'react-grid-layout';
import DashboardGrid, { type GridWidget } from './DashboardGrid';
import WidgetCard from './WidgetCard';
import SlicerPanel from './SlicerPanel';
import { useDashboardStore } from '../store/dashboardStore';

// Edit-mode single-widget refresh: re-fetch from the draft API and update the
// in-memory cache. ViewWidgetCard (published page) uses react-query's refetch;
// edit mode has no react-query layer so this hand-rolled fetch + store update
// is the manual-refresh path (recovery for network/g2-load timing failures).
const ADMIN_BASE = (typeof window !== 'undefined' && (window as any).__ADMIN_BASE__) || '';
async function refreshDraftWidget(uid: string, widgetId: string): Promise<{ rows: Record<string, unknown>[]; total?: number }> {
  const resp = await fetch(
    `${ADMIN_BASE}/api_draft_widget_data?uid=${encodeURIComponent(uid)}&widgetId=${encodeURIComponent(widgetId)}`,
    { credentials: 'same-origin' },
  );
  const result = await resp.json();
  if (result.status !== 1) {
    throw new Error(result.info || '图表数据刷新失败');
  }
  const rows = Array.isArray(result.data) ? (result.data as Record<string, unknown>[]) : [];
  return { rows, total: typeof result.total === 'number' ? result.total : undefined };
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

interface PreviewPanelProps {
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
}

export default function PreviewPanel({ showSql = false }: PreviewPanelProps) {
  const dsl = useDashboardStore((s) => s.dsl);
  const widgetDataCache = useDashboardStore((s) => s.widgetDataCache);
  const updateWidget = useDashboardStore((s) => s.updateWidget);
  const updateLayout = useDashboardStore((s) => s.updateLayout);
  const markWidgetUserSized = useDashboardStore((s) => s.markWidgetUserSized);
  const removeWidget = useDashboardStore((s) => s.removeWidget);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const uid = useDashboardStore((s) => s.uid);
  const updateWidgetDataCache = useDashboardStore((s) => s.updateWidgetDataCache);
  // Track which widget is currently being manually refreshed (for the spin icon).
  const [refreshingId, setRefreshingId] = useState<string | null>(null);

  // Build GridWidget[] from the DSL + cache.
  const gridWidgets = useMemo<GridWidget[]>(() => {
    if (!dsl) return [];
    return Object.values(dsl.widgets).map((widget) => ({
      widget,
      cache: widgetDataCache[widget.widget_id],
      sql: dsl.queries[widget.query_id]?.raw_sql,
    }));
  }, [dsl, widgetDataCache]);

  const hasWidgets = gridWidgets.length > 0;

  const handleLayoutChange = useCallback(
    (newLayout: Layout[]) => {
      for (const item of newLayout) {
        updateLayout(item.i, item.x, item.y, item.w, item.h);
      }
    },
    [updateLayout],
  );

  const handleTitleChange = useCallback(
    (widgetId: string, title: string) => {
      updateWidget(widgetId, { title });
    },
    [updateWidget],
  );

  const handleRemove = useCallback(
    (widgetId: string) => {
      removeWidget(widgetId);
    },
    [removeWidget],
  );

  const handleRegenerate = useCallback((_widgetId: string) => {
    updateWidget(_widgetId, { status: 'success' });
  }, [updateWidget]);

  const handleRefresh = useCallback(
    async (widgetId: string) => {
      if (!uid) return;
      setRefreshingId(widgetId);
      try {
        const { rows, total } = await refreshDraftWidget(uid, widgetId);
        updateWidgetDataCache(widgetId, { rows, columns: [] }, { total, status: rows.length > 0 ? 'chart' : 'empty' });
      } catch {
        updateWidgetDataCache(widgetId, null, { status: 'error', error_msg: '刷新失败，请重试' });
      } finally {
        setRefreshingId(null);
      }
    },
    [uid, updateWidgetDataCache],
  );

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
      <div style={{ ...styles.container, overflow: streamingState !== 'idle' ? 'hidden' : 'auto' }}>
        <div style={{ padding: '4px 12px', borderBottom: '1px solid #f0f0f0' }}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            ✋ 拖拽移动 · 边角缩放 · 🗑️ 删除
          </Typography.Text>
        </div>
        {dsl && dsl.slicers && dsl.slicers.length > 0 && (
          <div style={{ padding: '8px 12px', borderBottom: '1px solid #f0f0f0', display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
            <Typography.Text type="secondary" style={{ fontSize: 12, flexShrink: 0 }}>筛选：</Typography.Text>
            <SlicerPanel uid={uid || ''} />
          </div>
        )}
        <DashboardGrid
          widgets={gridWidgets}
          regions={dsl?.layout.regions}
          readonly={streamingState !== 'idle'}
          onLayoutChange={handleLayoutChange}
          onDragStart={() => {}}
          onDragStop={() => {}}
          onResizeStart={() => {}}
          onResizeStop={(oldItem) => {
            markWidgetUserSized(oldItem.i);
          }}
          renderCard={(gw) => (
            <WidgetCard
              widget={gw.widget}
              cache={gw.cache}
              onTitleChange={handleTitleChange}
              onRemove={handleRemove}
              onRegenerate={handleRegenerate}
              onRefresh={handleRefresh}
              refreshing={refreshingId === gw.widget.widget_id}
              showSql={showSql}
              sql={gw.sql}
              editable={streamingState === 'idle'}
            />
          )}
        />
      </div>
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
  viewportWrap: {
    position: 'relative' as const,
    height: '100%',
    width: '100%',
    overflow: 'hidden' as const,
    display: 'flex',
    flexDirection: 'column' as const,
  },
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
