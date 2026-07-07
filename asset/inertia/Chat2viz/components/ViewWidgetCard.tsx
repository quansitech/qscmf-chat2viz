import WidgetCard from './WidgetCard';
import { useWidgetData } from '../hooks/useWidgetData';
import type { WidgetCacheEntry } from '../store/dashboardStore';
import type { WidgetSpec } from '../types/dsl';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface ViewWidgetCardProps {
  uid: string;
  /** Widget shape from the published schema (no inline data — fetched at runtime). */
  widget: WidgetSpec;
  cache?: WidgetCacheEntry;
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
  /** fix-draft-view-restore: when true, fetch from the admin preview endpoint. */
  isAdminPreview?: boolean;
  /** Slicer values (drives refetch via the query key). */
  slicerValues?: Record<string, unknown>;
  sql?: string;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

/**
 * Read-only widget card for the published view page.
 *
 * Fetches widget data via useWidgetData (form A — uid only), then delegates
 * rendering to `WidgetCard` in `editable={false}` mode. The slicerValues key
 * dimension triggers automatic refetch when a slicer changes.
 */
export default function ViewWidgetCard({
  uid,
  widget,
  cache: externalCache,
  showSql = false,
  isAdminPreview = false,
  slicerValues,
  sql,
}: ViewWidgetCardProps) {
  // When an external cache is provided (fixture mode — the widget carries its
  // own mock data), skip the HTTP fetch entirely and render directly.
  const skipFetch = externalCache !== undefined;
  const { data, isLoading, isError, refetch, isFetching } = useWidgetData(widget.widget_id, {
    uid,
    slicerValues,
    isAdminPreview,
    enabled: !skipFetch,
    refreshInterval: typeof widget.plugin_spec?.refreshInterval === 'number'
      ? (widget.plugin_spec.refreshInterval as number)
      : undefined,
  });

  // Fixture mode: use the externally-provided cache directly.
  if (skipFetch && externalCache) {
    return (
      <WidgetCard
        widget={widget}
        cache={externalCache}
        editable={false}
        showSql={showSql}
        sql={sql}
        onTitleChange={() => {}}
        onRemove={() => {}}
      />
    );
  }

  // Derive render status from the query state.
  let status: WidgetCacheEntry['status'];
  if (isLoading) {
    status = 'loading';
  } else if (isError) {
    status = 'error';
  } else if (data && Array.isArray(data.rows) && data.rows.length === 0) {
    status = 'empty';
  } else {
    status = 'chart';
  }

  const cache: WidgetCacheEntry = {
    rows: data?.rows ?? [],
    ...(data?.total !== undefined ? { total: data.total } : {}),
    ...(data?.truncated !== undefined ? { truncated: data.truncated } : {}),
    status,
  };

  return (
    <WidgetCard
      widget={widget}
      cache={cache}
      editable={false}
      showSql={showSql}
      sql={sql}
      onRefresh={() => refetch()}
      refreshing={isFetching}
      onTitleChange={() => {}}
      onRemove={() => {}}
    />
  );
}
