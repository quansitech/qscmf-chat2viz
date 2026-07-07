import { useCallback, useEffect, useMemo, useState } from 'react';
import { Button, DatePicker, Empty, InputNumber, Select, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { getPageProps, navigate } from './adapters';
import { ADMIN_BASE } from './utils/routes';
import DashboardGrid from './components/DashboardGrid';
import ViewWidgetCard from './components/ViewWidgetCard';
import type { GridWidget } from './components/DashboardGrid';
import type { DashboardDSL, SSEDashboardReplaceV3 } from './types/dsl';
import { isDashboardReplaceV3 } from './types/dsl';
import { useSlicerOptions } from './hooks/useWidgetData';
import './plugins'; // register the five widget plugins at module load

// ---------------------------------------------------------------------------
// QueryClient — scoped to this view page
// ---------------------------------------------------------------------------

function useQueryClient() {
  return useState(() => new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 5 * 60 * 1000,
        retry: 1,
        refetchOnWindowFocus: false,
      },
    },
  }))[0];
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface DashboardViewPageProps {
  dashboard: {
    uid: string;
    title: string;
    status: number;
    dashboard_status: string;
  };
  schema: unknown;
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  show_sql?: boolean;
}

// ---------------------------------------------------------------------------
// Inner Component
// ---------------------------------------------------------------------------

function DashboardViewInner() {
  const { dashboard, schema, show_sql = false } = getPageProps<DashboardViewPageProps>();

  // Debug fixture injection (?__dsl_fixture=<name>):
  // Loads a golden DSL payload from the published asset dir instead of the
  // server schema. Safe because: (1) fixtureName is validated against a strict
  // allowlist regex (no path traversal), (2) fixtures only exist when the
  // `fixtures-dsl-v3/` dir is explicitly published (a real production deploy
  // does not ship it — webpack clean removes it on each build unless copied).
  // Maps to asset/chat2viz-dashboard/fixtures-dsl-v3/<name>.replace.json.
  const rawFixture = typeof window !== 'undefined'
    ? new URLSearchParams(window.location.search).get('__dsl_fixture')
    : null;
  const safeFixtureName =
    typeof rawFixture === 'string' && /^[A-Za-z0-9_-]+$/.test(rawFixture)
      ? rawFixture
      : null;

  // Parse the schema as a v3 DSL AST (defensive: tolerate string or object).
  // When a fixture is requested, the schema is ignored in favor of the fixture
  // payload (loaded asynchronously below).
  const [fixtureDsl, setFixtureDsl] = useState<DashboardDSL | null>(null);
  useEffect(() => {
    if (!safeFixtureName) return;
    // Resolve the fixtures path relative to the bundle. The dev server serves
    // the package root; production builds do not ship fixtures (the gate below
    // only activates when a fixture name is present in the URL).
    // Fixtures are symlinked into the published asset dir so the same URL
    // works on the test site (asset/chat2viz-dashboard/fixtures-dsl-v3 →
    // frontend/fixtures/dsl-v3). Only activates when the query param is set.
    const url = `/Public/chat2viz-dashboard/fixtures-dsl-v3/${encodeURIComponent(safeFixtureName)}.replace.json`;
    fetch(url, { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((data) => {
        if (isDashboardReplaceV3(data)) setFixtureDsl(data as SSEDashboardReplaceV3);
      })
      .catch(() => { /* fixture missing — fall back to schema */ });
  }, [safeFixtureName]);

  const dsl = useMemo<DashboardDSL | null>(() => {
    if (fixtureDsl) return fixtureDsl;
    let s = schema;
    if (typeof s === 'string') {
      try { s = JSON.parse(s); } catch { return null; }
    }
    if (s && typeof s === 'object' && isDashboardReplaceV3(s)) {
      return s as SSEDashboardReplaceV3;
    }
    return null;
  }, [schema, fixtureDsl]);

  const gridWidgets = useMemo<GridWidget[]>(() => {
    if (!dsl) return [];
    return Object.values(dsl.widgets).map((widget) => {
      // Fixture mode: the widget carries its own data (mock) OR is a plugin
      // type that doesn't need query data (markdown). Pre-fill the cache so
      // ViewWidgetCard renders directly without an HTTP fetch.
      const widgetData = widget.data;
      const hasMockData = fixtureDsl && widgetData !== null && widgetData !== undefined;
      const noDataNeeded = fixtureDsl && widget.plugin_type === 'markdown';
      const cacheEntry = (hasMockData || noDataNeeded) ? {
        rows: hasMockData && widgetData && typeof widgetData === 'object' && Array.isArray((widgetData as { rows?: unknown[] }).rows)
          ? (widgetData as { rows: Record<string, unknown>[] }).rows
          : [],
        status: 'chart' as const,
      } : undefined;
      return {
        widget,
        ...(cacheEntry ? { cache: cacheEntry } : {}),
        sql: dsl.queries[widget.query_id]?.raw_sql,
      };
    });
  }, [dsl, fixtureDsl]);

  const hasWidgets = gridWidgets.length > 0;

  const isPublished = (dashboard?.dashboard_status ?? '') === 'published';
  const isAdminPreview = (dashboard as { __is_preview?: boolean } | null)?.__is_preview === true;

  // Slicer values state lives at the view page level (the view page has no
  // global store — it's a read-only mount). Pass to ViewWidgetCard for refetch.
  const [slicerValues, setSlicerValues] = useState<Record<string, unknown>>({});
  // Merge a single slicer change into the existing values (so multi-slicer
  // dashboards retain all selections, not just the last-changed one).
  const handleSlicerChange = useCallback((sid: string, value: unknown) => {
    setSlicerValues((prev) => ({ ...prev, [sid]: value }));
  }, []);

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
          <div style={styles.empty}>
            <Empty description="该仪表盘暂未发布" />
          </div>
        ) : hasWidgets && dsl ? (
          <div style={styles.gridWrapper}>
            {/* Slicer panel (slicers[] from the DSL, contract §2.5). */}
            <ViewSlicerPanel dsl={dsl} uid={dashboard.uid} onChange={handleSlicerChange} />
            <DashboardGrid
              widgets={gridWidgets}
              regions={dsl.layout.regions}
              readonly
              renderCard={(gw) => (
                <ViewWidgetCard
                  uid={dashboard.uid}
                  widget={gw.widget}
                  cache={gw.cache}
                  showSql={show_sql}
                  isAdminPreview={isAdminPreview}
                  slicerValues={slicerValues}
                  sql={gw.sql}
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

/**
 * Local SlicerPanel wrapper for the view page. The view page has no global
 * store, so we route slicer changes through a local useState setter that
 * feeds ViewWidgetCard's slicerValues prop.
 */
function ViewSlicerPanel({
  dsl,
  uid,
  onChange,
}: {
  dsl: DashboardDSL;
  uid: string;
  /** Merge a single slicer value (sid → value) into the parent state. */
  onChange: (sid: string, value: unknown) => void;
}) {
  if (!dsl.slicers || dsl.slicers.length === 0) return null;
  // The SlicerPanel reads slicerValues from the store; for the view page we
  // render a lightweight inline control set and route changes to the parent.
  return (
    <ViewSlicerControls dsl={dsl} uid={uid} onChange={onChange} />
  );
}

function ViewSlicerControls({
  dsl,
  uid,
  onChange,
}: {
  dsl: DashboardDSL;
  uid: string;
  /** Merge a single slicer value (sid → value) into the parent state. */
  onChange: (sid: string, value: unknown) => void;
}) {
  return (
    <div style={viewSlicerStyles.wrap}>
      {dsl.slicers!.map((slicer) => {
        const sid = slicer.slicer_id;
        const label = slicer.label ?? slicer.field ?? sid;
        const targetQueryIds = Object.keys(slicer.target_query_params ?? {});
        const queryId = targetQueryIds[0];
        const paramName = queryId ? slicer.target_query_params[queryId] : undefined;
        const param = paramName ? (dsl.queries[queryId]?.params ?? []).find((p) => p.name === paramName) : undefined;
        const paramType = param?.type ?? 'string';

        if (paramType === 'string' && slicer.options_query_id) {
          return (
            <ViewStringSelect key={sid} slicer={slicer} uid={uid} label={label} onChange={(v) => onChange(sid, v)} />
          );
        }
        if (paramType === 'number') {
          return (
            <div key={sid} style={viewSlicerStyles.control}>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>{label}</Typography.Text>
              <InputNumber style={{ width: '100%' }} placeholder={`输入${label}`} onChange={(v) => onChange(sid, v ?? null)} />
            </div>
          );
        }
        return (
          <div key={sid} style={viewSlicerStyles.control}>
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>{label}</Typography.Text>
            <DatePicker
              {...(paramType === 'datetime' ? { showTime: true } : {})}
              style={{ width: '100%' }}
              onChange={(_d, dateStr) => onChange(sid, typeof dateStr === 'string' ? dateStr : null)}
            />
          </div>
        );
      })}
    </div>
  );
}

function ViewStringSelect({ slicer, uid, label, onChange }: { slicer: DashboardDSL['slicers'] extends (infer S)[] | undefined ? S : never; uid: string; label: string; onChange: (v: string) => void }) {
  const { data: options } = useSlicerOptions(slicer.slicer_id, { optionsQueryId: slicer.options_query_id, uid });
  return (
    <div style={viewSlicerStyles.control}>
      <Typography.Text type="secondary" style={{ fontSize: 12 }}>{label}</Typography.Text>
      <Select
        allowClear
        showSearch
        placeholder="请选择"
        style={{ width: '100%' }}
        options={(options ?? []).map((o) => ({ value: o.value, label: o.label }))}
        onChange={(v) => onChange(v as string)}
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// Exported Component
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
  root: { display: 'flex', flexDirection: 'column', height: '100vh', background: '#f0f2f5' },
  header: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '8px 16px', background: '#fff', borderBottom: '1px solid #f0f0f0', flexShrink: 0 },
  headerLeft: { display: 'flex', alignItems: 'center', gap: 8 },
  content: { flex: 1, overflow: 'auto', padding: 16 },
  gridWrapper: { maxWidth: 1400, margin: '0 auto', width: '100%' },
  empty: { display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100%' },
};

const viewSlicerStyles: Record<string, React.CSSProperties> = {
  wrap: { display: 'flex', flexWrap: 'wrap', gap: 12, padding: '8px 12px', marginBottom: 12, background: '#fff', borderRadius: 8 },
  control: { minWidth: 180, flex: '1 1 180px', maxWidth: 280 },
};
