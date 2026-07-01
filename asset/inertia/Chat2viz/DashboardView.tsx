import { useMemo, useState } from 'react';
import { Button, Empty, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { getPageProps, navigate } from './adapters';
import { ADMIN_BASE } from './utils/routes';
import DashboardGrid from './components/DashboardGrid';
import ViewWidgetCard from './components/ViewWidgetCard';
import type { GridWidget } from './components/DashboardGrid';

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
  layout?: { x: number; y: number; w: number; h: number; userSized?: boolean };
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
// Inner Component (uses hooks, wrapped by QueryClientProvider)
// ---------------------------------------------------------------------------

function DashboardViewInner() {
  const { dashboard, schema, show_sql = false } = getPageProps<DashboardViewPageProps>();

  const widgets = useMemo<GridWidget[]>(
    () => (schema?.widgets ?? []).map((w) => ({
      id: w.id,
      title: w.title,
      g2_spec: w.g2_spec,
      sql: w.sql,
      refreshInterval: w.refreshInterval,
      layout: w.layout,
    })),
    [schema],
  );
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
            {/*
              Shared grid — identical layout math (suggestHeight, compactType,
              minW/minH, margins) as the edit page's PreviewPanel. This is the
              single reuse point guaranteeing a 1:1 visual match between edit
              preview and published view. renderCard injects ViewWidgetCard
              (react-query data fetching + WidgetCard readonly rendering).
            */}
            <DashboardGrid
              widgets={widgets}
              readonly
              renderCard={(w) => (
                <ViewWidgetCard
                  uid={dashboard.uid}
                  widget={w}
                  showSql={show_sql}
                  isAdminPreview={isAdminPreview}
                />
              )}
            />
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
  empty: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
  },
};
