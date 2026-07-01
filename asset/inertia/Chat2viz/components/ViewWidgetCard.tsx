import { useQuery } from '@tanstack/react-query';
import WidgetCard from './WidgetCard';
import { ADMIN_BASE, PUBLIC_BASE } from '../utils/routes';
import { createSemaphore } from '../utils/concurrency';
import type { Widget, WidgetStatus } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// Shared concurrency cap. The public endpoint enforces a per-IP rate limit
// (30-60 req/min depending on APCu). A 6-wide cap matches the browser's
// HTTP/1.1 per-origin connection ceiling; the 429 retry logic below absorbs
// the residual burst on multi-widget dashboards — widgets that hit 429 wait
// and retry, showing skeleton in the meantime (slow but eventually complete).
// ---------------------------------------------------------------------------

const limitWidgetFetch = createSemaphore(6);

// ---------------------------------------------------------------------------
// RateLimitError — typed error so the retry function can distinguish HTTP 429
// from other failures and apply a Retry-After-aware backoff instead of the
// default exponential delay.
// ---------------------------------------------------------------------------

class RateLimitError extends Error {
  readonly retryAfter: number;
  constructor(retryAfter: number) {
    super('请求过于频繁，请稍后再试');
    this.name = 'RateLimitError';
    this.retryAfter = retryAfter;
  }
}

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface ViewWidgetCardProps {
  uid: string;
  /** Widget shape from the published schema (no inline data — fetched at runtime). */
  widget: {
    id: string;
    title?: string;
    g2_spec?: Record<string, unknown>;
    sql?: string;
    refreshInterval?: number;
  };
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
  /** fix-draft-view-restore: when true, fetch from the admin preview data
   *  endpoint (current_schema, no ownership gate) instead of the public
   *  endpoint (which rejects non-published dashboards). */
  isAdminPreview?: boolean;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

/**
 * Read-only widget card for the published view page.
 *
 * Fetches widget data via react-query (per-widget key, bounded concurrency),
 * then delegates rendering to `WidgetCard` in `editable={false}` mode. This is
 * the key reuse point: the view page gets the exact same chart rendering, four
 * status branches (loading/error/empty/chart), and mergedSpec data injection as
 * the edit page — no duplicated render logic.
 *
 * Status is derived from the query state:
 *  - loading → 'loading' (WidgetCard shows skeleton + "加载中…")
 *  - error   → 'error'   (WidgetCard shows error card)
 *  - success + 0 rows    → 'empty'  (WidgetCard shows "未查询到数据")
 *  - success + rows      → 'chart'
 *
 * The fetched rows are the sole data source — the published schema has no
 * inline data (stripped at publish time by SchemaStripTrait). WidgetCard merges
 * the rows into the spec at render time via `mergedSpec`.
 */
export default function ViewWidgetCard({
  uid,
  widget,
  showSql = false,
  isAdminPreview = false,
}: ViewWidgetCardProps) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['widget-data', uid, widget.id, isAdminPreview ? 'preview' : 'public'],
    queryFn: async () => {
      // Encode defensively — PHP validates UUID server-side, but encoding here
      // prevents any value with special chars from injecting path segments.
      return limitWidgetFetch(async () => {
        const safeUid = encodeURIComponent(uid);
        const safeWidgetId = encodeURIComponent(widget.id);
        const endpoint = isAdminPreview
          ? `${ADMIN_BASE}/api_preview_widget_data?uid=${safeUid}&widgetId=${safeWidgetId}`
          : `${PUBLIC_BASE}/api_widget_data/uid/${safeUid}/widgetId/${safeWidgetId}`;
        const resp = await fetch(endpoint, { credentials: 'same-origin' });
        // HTTP 429 rate-limit: surface as a typed error so the retry function
        // can honor Retry-After. The skeleton stays visible while waiting —
        // slow but eventually renders every widget.
        if (resp.status === 429) {
          const retryAfter = parseInt(resp.headers.get('Retry-After') || '60', 10);
          throw new RateLimitError(retryAfter);
        }
        const result = await resp.json();
        if (result.status !== 1) {
          throw new Error(result.info || '加载图表数据失败');
        }
        return result.data as Record<string, unknown>[];
      });
    },
    staleTime: 5 * 60 * 1000,
    // Retry up to 3 times for 429 (wait for the rate window to free up),
    // 1 time for other errors. The skeleton is shown during the wait.
    retry: (failureCount, error) => {
      if (error instanceof RateLimitError) return failureCount < 3;
      return failureCount < 1;
    },
    retryDelay: (failureCount, error) => {
      if (error instanceof RateLimitError) {
        // Honor the server's Retry-After hint but cap at 10s so a multi-widget
        // dashboard doesn't freeze for a full minute. The semaphore already
        // throttles concurrency; this staggered backoff lets later widgets
        // slip through as earlier ones drain the rate window.
        return Math.min((error.retryAfter || 60) * 1000, 10000) * (failureCount + 1);
      }
      return Math.min(1000 * 2 ** failureCount, 5000);
    },
    ...(widget.refreshInterval && widget.refreshInterval > 0
      ? { refetchInterval: widget.refreshInterval * 1000 }
      : {}),
  });

  // Derive render status from query state — mirrors how the store sets status
  // during streaming, but here driven by react-query.
  let status: WidgetStatus;
  if (isLoading) {
    status = 'loading';
  } else if (isError) {
    status = 'error';
  } else if (Array.isArray(data) && data.length === 0) {
    status = 'empty';
  } else {
    status = 'chart';
  }

  // Build a Widget-shaped object for WidgetCard. The fetched `data` is the sole
  // data source. WidgetCard merges it into the spec via mergedSpec at render.
  const widgetForCard: Widget = {
    id: widget.id,
    title: widget.title || '未命名图表',
    g2_spec: widget.g2_spec || {},
    data: Array.isArray(data) ? data : [],
    sql: widget.sql,
    status,
    refreshInterval: widget.refreshInterval,
    layout: { x: 0, y: 0, w: 12, h: 6 }, // layout is owned by DashboardGrid
  };

  // Delegate entirely to WidgetCard in read-only mode. The no-op callbacks are
  // required by WidgetCard's props but never invoked (editable={false} disables
  // all interaction affordances).
  return (
    <WidgetCard
      widget={widgetForCard}
      editable={false}
      showSql={showSql}
      onTitleChange={() => {}}
      onRemove={() => {}}
    />
  );
}
