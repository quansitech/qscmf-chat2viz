import { useMemo } from 'react';
import { Button, Empty, Alert, Spin, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import { getPageProps, navigate } from './adapters';
import LazyG2Renderer from './components/LazyG2Renderer';
import type { WidgetLayout } from './store/dashboardStore';

// ---------------------------------------------------------------------------
// WidthProvider wraps RGL to auto-track container width
// ---------------------------------------------------------------------------

const ResponsiveGridLayout = WidthProvider(RGL);

// ---------------------------------------------------------------------------
// QueryClient — scoped to this view page
// ---------------------------------------------------------------------------

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 minutes
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface SchemaWidget {
  id: string;
  title?: string;
  g2_spec?: Record<string, unknown>;
  layout?: WidgetLayout;
  sql?: string;
  refreshInterval?: number;
}

interface DashboardViewPageProps {
  dashboard: {
    uid: string;
    title: string;
    status: string;
  };
  schema: {
    widgets?: SchemaWidget[];
  };
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const COLS = 12;
const ROW_HEIGHT = 60;

// ---------------------------------------------------------------------------
// Widget Data Fetcher Component
//
// Each widget gets its own useQuery for error isolation — a failure in one
// widget does not affect others.
// ---------------------------------------------------------------------------

interface ViewWidgetCardProps {
  uid: string;
  widget: SchemaWidget;
}

function ViewWidgetCard({ uid, widget }: ViewWidgetCardProps) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['widget-data', uid, widget.id],
    queryFn: async () => {
      const resp = await fetch(
        `/extends/Chat2VizDashboard/api_widget_data/uid/${uid}/widgetId/${widget.id}`,
        { credentials: 'same-origin' },
      );
      const result = await resp.json();
      if (result.status !== 1) {
        throw new Error(result.info || 'Failed to load widget data');
      }
      return result.data as Record<string, unknown>[];
    },
    staleTime: 5 * 60 * 1000,
    retry: 1,
    ...(widget.refreshInterval && widget.refreshInterval > 0
      ? { refetchInterval: widget.refreshInterval * 1000 }
      : {}),
  });

  const hasSpec =
    widget.g2_spec &&
    (widget.g2_spec.type ||
      (Array.isArray((widget.g2_spec as Record<string, unknown>).children) &&
        ((widget.g2_spec as Record<string, unknown>).children as unknown[]).length > 0));

  return (
    <div style={styles.widgetCard}>
      {/* Header */}
      <div style={styles.widgetHeader}>
        <Typography.Text strong>{widget.title || 'Untitled Widget'}</Typography.Text>
      </div>

      {/* Chart Area */}
      <div style={styles.chartArea}>
        {isError && (
          <Alert
            type="error"
            message={error instanceof Error ? error.message : 'Data load error'}
            showIcon
            style={{ margin: 12 }}
          />
        )}
        {!isError && isLoading && (
          <div style={styles.loading}>
            <Spin tip="Loading..." />
          </div>
        )}
        {!isError && !isLoading && hasSpec && widget.g2_spec && (
          <LazyG2Renderer
            spec={widget.g2_spec}
            data={data}
          />
        )}
        {!isError && !isLoading && !hasSpec && (
          <div style={styles.emptyChart}>
            <Empty description="No chart specification" image={Empty.PRESENTED_IMAGE_SIMPLE} />
          </div>
        )}
      </div>

      {/* Footer */}
      {widget.sql && (
        <div style={styles.footer}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            SQL: {widget.sql.length > 80 ? widget.sql.slice(0, 80) + '...' : widget.sql}
          </Typography.Text>
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Inner Component (uses hooks, wrapped by QueryClientProvider)
// ---------------------------------------------------------------------------

function DashboardViewInner() {
  const { dashboard, schema } = getPageProps<DashboardViewPageProps>();

  const widgets = useMemo(() => schema?.widgets ?? [], [schema]);
  const hasWidgets = widgets.length > 0;

  // Build static layout from schema — all items are static (not draggable/resizable)
  const layout: Layout[] = useMemo(
    () =>
      widgets.map((w) => ({
        i: w.id,
        x: w.layout?.x ?? 0,
        y: w.layout?.y ?? 0,
        w: w.layout?.w ?? 6,
        h: w.layout?.h ?? 6,
        static: true,
      })),
    [widgets],
  );

  return (
    <div style={styles.root}>
      {/* Header */}
      <div style={styles.header}>
        <div style={styles.headerLeft}>
          <Button
            type="text"
            icon={<ArrowLeftOutlined />}
            onClick={() => navigate('/extends/Chat2VizDashboard/index')}
          />
          <Typography.Title level={3} style={{ margin: 0 }}>
            {dashboard?.title || 'Dashboard'}
          </Typography.Title>
        </div>
      </div>

      {/* Content */}
      <div style={styles.content}>
        {hasWidgets ? (
          <div style={styles.gridWrapper}>
            <ResponsiveGridLayout
              layout={layout}
              cols={COLS}
              rowHeight={ROW_HEIGHT}
              isDraggable={false}
              isResizable={false}
              margin={[12, 12]}
              useCSSTransforms={true}
            >
              {widgets.map((w) => (
                <div key={w.id}>
                  <ViewWidgetCard uid={dashboard.uid} widget={w} />
                </div>
              ))}
            </ResponsiveGridLayout>
          </div>
        ) : (
          <div style={styles.empty}>
            <Empty description="This dashboard has no widgets." />
          </div>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Exported Component — wraps inner with QueryClientProvider
// ---------------------------------------------------------------------------

export default function DashboardView() {
  return (
    <QueryClientProvider client={queryClient}>
      <DashboardViewInner />
    </QueryClientProvider>
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
  header: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '8px 16px',
    background: '#fff',
    borderBottom: '1px solid #f0f0f0',
    flexShrink: 0,
  },
  headerLeft: {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
  },
  content: {
    flex: 1,
    overflow: 'auto',
    padding: 16,
  },
  gridWrapper: {
    maxWidth: 1400,
    margin: '0 auto',
    width: '100%',
  },
  widgetCard: {
    background: '#fff',
    borderRadius: 8,
    border: '1px solid #f0f0f0',
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    overflow: 'hidden',
  },
  widgetHeader: {
    display: 'flex',
    alignItems: 'center',
    padding: '8px 12px',
    borderBottom: '1px solid #f5f5f5',
  },
  chartArea: {
    flex: 1,
    minHeight: 200,
    position: 'relative',
  },
  loading: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: 200,
  },
  emptyChart: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: 200,
  },
  footer: {
    borderTop: '1px solid #f5f5f5',
    padding: '4px 12px',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap' as const,
  },
  empty: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
  },
};
