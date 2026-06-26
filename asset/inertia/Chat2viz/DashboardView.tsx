import { useMemo, useState } from 'react';
import { Button, Empty, Alert, Spin, Typography, Collapse } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery, QueryClient, QueryClientProvider } from '@tanstack/react-query';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import { getPageProps, navigate } from './adapters';
import { ADMIN_BASE, PUBLIC_BASE } from './utils/routes';
import LazyG2Renderer from './components/LazyG2Renderer';
import WidgetTable from './components/WidgetTable';
import { hasChartSpec } from './store/dashboardStore';
import type { WidgetLayout } from './store/dashboardStore';

// ---------------------------------------------------------------------------
// WidthProvider wraps RGL to auto-track container width
// ---------------------------------------------------------------------------

const ResponsiveGridLayout = WidthProvider(RGL);

// ---------------------------------------------------------------------------
// QueryClient — scoped to this view page
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
    status: number;
    dashboard_status: string;
  };
  schema: {
    widgets?: SchemaWidget[];
  };
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  show_sql?: boolean;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const COLS = 24;
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
  showSql?: boolean;
  /** fix-draft-view-restore: when true, fetch from the admin preview data
   *  endpoint (current_schema, no ownership gate) instead of the public
   *  endpoint (which rejects non-published dashboards). */
  isAdminPreview?: boolean;
}

function ViewWidgetCard({ uid, widget, showSql = false, isAdminPreview = false }: ViewWidgetCardProps) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['widget-data', uid, widget.id, isAdminPreview ? 'preview' : 'public'],
    queryFn: async () => {
      // task 7.6: encode uid/widgetId defensively. PHP validates UUID
      // server-side, but encoding here prevents any value with special chars
      // from injecting path segments.
      // fix-draft-view-restore: admin preview fetches draft data from
      // current_schema via the admin endpoint (any logged-in admin; no
      // ownership gate). The public endpoint rejects non-published dashboards.
      // Query-string params match the edit page's api_draft_widget_data call
      // (DashboardEdit.tsx) — the admin route parses them as ?uid=&widgetId=.
      const safeUid = encodeURIComponent(uid);
      const safeWidgetId = encodeURIComponent(widget.id);
      const endpoint = isAdminPreview
        ? `${ADMIN_BASE}/api_preview_widget_data?uid=${safeUid}&widgetId=${safeWidgetId}`
        : `${PUBLIC_BASE}/api_widget_data/uid/${safeUid}/widgetId/${safeWidgetId}`;
      const resp = await fetch(endpoint, { credentials: 'same-origin' });
      const result = await resp.json();
      if (result.status !== 1) {
        throw new Error(result.info || '加载图表数据失败');
      }
      return result.data as Record<string, unknown>[];
    },
    staleTime: 5 * 60 * 1000,
    retry: 1,
    ...(widget.refreshInterval && widget.refreshInterval > 0
      ? { refetchInterval: widget.refreshInterval * 1000 }
      : {}),
  });

  // Unified chart-existence judgment (DESIGN_BASIS #7): type OR mark OR children.
  const hasSpec = hasChartSpec(widget.g2_spec);

  // G2 v5 has no `composition.table` mark — route table specs to the native
  // renderer to avoid "Unknown Component" + a blank widget (mirrors WidgetCard).
  const isTable = widget.g2_spec?.type === 'table';

  return (
    <div style={styles.widgetCard}>
      {/* Header */}
      <div style={styles.widgetHeader}>
        <Typography.Text strong>{widget.title || '未命名图表'}</Typography.Text>
      </div>

      {/* Chart Area */}
      <div style={styles.chartArea}>
        {isError && (
          <Alert
            type="error"
            message={error instanceof Error ? error.message : '数据加载错误'}
            showIcon
            style={{ margin: 12 }}
          />
        )}
        {!isError && isLoading && (
          <div style={styles.loading}>
            <Spin tip="加载中..." />
          </div>
        )}
        {!isError && !isLoading && hasSpec && widget.g2_spec && isTable && (
          <WidgetTable
            spec={widget.g2_spec}
            data={Array.isArray(data) ? data : []}
          />
        )}
        {!isError && !isLoading && hasSpec && widget.g2_spec && !isTable && (
          <LazyG2Renderer
            spec={widget.g2_spec}
            data={data}
          />
        )}
        {!isError && !isLoading && !hasSpec && (
          <div style={styles.emptyChart}>
            <Empty description="暂无图表规格" image={Empty.PRESENTED_IMAGE_SIMPLE} />
          </div>
        )}
      </div>

      {/* Footer */}
      {showSql && widget.sql && (
        <div style={styles.footer}>
          <Collapse
            ghost
            size="small"
            items={[{
              key: 'sql',
              label: <Typography.Text type="secondary" style={{ fontSize: 11 }}>查询语句</Typography.Text>,
              children: (
                <pre style={{ background: '#fff', padding: 6, borderRadius: 4, fontSize: 11, overflow: 'auto', margin: 0, maxHeight: 120 }}>
                  {widget.sql}
                </pre>
              ),
            }]}
          />
        </div>
      )}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Inner Component (uses hooks, wrapped by QueryClientProvider)
// ---------------------------------------------------------------------------

function DashboardViewInner() {
  const { dashboard, schema, show_sql = false } = getPageProps<DashboardViewPageProps>();

  const widgets = useMemo(() => schema?.widgets ?? [], [schema]);
  const hasWidgets = widgets.length > 0;

  // fix-public-view-draft-exposure §2.2: belt-and-suspenders guard. PHP
  // rejects non-published dashboards at the view action (§1.1) so this branch
  // is theoretically unreachable. Kept as a defense-in-depth layer: if the
  // PHP gate ever fails (misconfigured status, stale cache), the frontend
  // still renders a friendly Empty instead of issuing api_widget_data calls
  // that would 404 / redirect. An "unpublished" state is not an error — it
  // is the absence of content — so we use Empty, not Alert.
  const isPublished = (dashboard?.dashboard_status ?? '') === 'published';
  // fix-draft-view-restore: admin preview (/admin/.../preview) sets the
  // __is_preview marker on the dashboard row. Under admin preview the React
  // app renders draft/archived schemas (current_schema) instead of the
  // "该仪表盘暂未发布" empty state. The public view path never sets this, so
  // its §2.2 defense-in-depth draft gate stays intact.
  const isAdminPreview = (dashboard as { __is_preview?: boolean } | null)?.__is_preview === true;

  // Build static layout from schema — all items are static (not draggable/resizable)
  const layout: Layout[] = useMemo(
    () =>
      widgets.map((w) => ({
        i: w.id,
        x: w.layout?.x ?? 0,
        y: w.layout?.y ?? 0,
        w: w.layout?.w ?? 12,
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
            onClick={() => navigate(`${ADMIN_BASE}/index`)}
          />
          <Typography.Title level={3} style={{ margin: 0 }}>
            {dashboard?.title || '仪表盘'}
          </Typography.Title>
        </div>
      </div>

      {/* Content */}
      <div style={styles.content}>
        {!isPublished && !isAdminPreview ? (
          // §2.2 defense-in-depth: friendly empty state for non-published
          // dashboards on the PUBLIC route. No widget data is fetched here.
          // (admin preview bypasses this gate — see isAdminPreview above.)
          <div style={styles.empty}>
            <Empty description="该仪表盘暂未发布" />
          </div>
        ) : hasWidgets ? (
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
                  <ViewWidgetCard uid={dashboard.uid} widget={w} showSql={show_sql} isAdminPreview={isAdminPreview} />
                </div>
              ))}
            </ResponsiveGridLayout>
          </div>
        ) : (
          <div style={styles.empty}>
            <Empty description="该仪表盘暂无图表" />
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
  const queryClient = useQueryClient();
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
