import { useCallback, useMemo } from 'react';
import { Empty, Typography } from 'antd';
import { LayoutOutlined } from '@ant-design/icons';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import WidgetCard from './WidgetCard';
import { useDashboardStore } from '../store/dashboardStore';
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

export default function PreviewPanel() {
  const widgets = useDashboardStore((s) => s.widgets);
  const updateWidget = useDashboardStore((s) => s.updateWidget);
  const updateLayout = useDashboardStore((s) => s.updateLayout);
  const removePanel = useDashboardStore((s) => s.removePanel);

  const widgetList = useMemo(() => Object.values(widgets) as Widget[], [widgets]);
  const hasWidgets = widgetList.length > 0;

  // ---- Build react-grid-layout layout array from store widgets ----
  const layout: Layout[] = useMemo(
    () =>
      widgetList.map((w) => ({
        i: w.id,
        x: w.layout.x,
        y: w.layout.y,
        w: w.layout.w,
        h: w.layout.h,
        minW: 4,
        minH: 3,
      })),
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
    (widgetId: string) => {
      // Use a dedicated refreshKey counter to trigger data re-fetch without
      // corrupting the refreshInterval field (which stores seconds, not timestamps).
      updateWidget(widgetId, { refreshKey: Date.now() });
    },
    [updateWidget],
  );

  // ---- Empty state ----
  if (!hasWidgets) {
    return (
      <div style={styles.emptyContainer}>
        <Empty
          image={<LayoutOutlined style={{ fontSize: 48, color: '#bfbfbf' }} />}
          description={
            <Typography.Text type="secondary">
              Start a conversation to generate charts
            </Typography.Text>
          }
        />
      </div>
    );
  }

  return (
    <div style={styles.container}>
      <ResponsiveGridLayout
        layout={layout}
        cols={COLS}
        rowHeight={ROW_HEIGHT}
        onLayoutChange={handleLayoutChange}
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
            />
          </div>
        ))}
      </ResponsiveGridLayout>
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
