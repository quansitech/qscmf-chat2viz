import { useQuery } from '@tanstack/react-query';
import { ADMIN_BASE, PUBLIC_BASE } from '../utils/routes';
import { createSemaphore } from '../utils/concurrency';
import { mapSlicerOptions, type SlicerOption } from '../utils/dslHelpers';
import type { DashboardDSL } from '../types/dsl';

// Re-export for existing importers; contract tests import from utils/dslHelpers
// to avoid the react-query dependency.
export { mapSlicerOptions, type SlicerOption };

// ---------------------------------------------------------------------------
// Shared concurrency cap (6-wide), mirroring the legacy ViewWidgetCard.
// ---------------------------------------------------------------------------

const limitWidgetFetch = createSemaphore(6);

// ---------------------------------------------------------------------------
// RateLimitError — typed so the retry function can honor Retry-After.
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
// Response mapping (contract §3.2 line 240)
// ---------------------------------------------------------------------------

export interface WidgetDataResponse {
  rows: Record<string, unknown>[];
  columns?: string[];
  total?: number;
  truncated?: boolean;
}

/**
 * Map the consumption endpoint response onto the widget data fields
 * (contract §3.2): `rows`/`columns` → widget.data; `total`/`truncated` →
 * widget top-level (overwriting stale values).
 */
export interface MappedWidgetData {
  rows: Record<string, unknown>[];
  columns: string[];
  total?: number;
  truncated?: boolean;
}

function mapResponse(resp: WidgetDataResponse): MappedWidgetData {
  const rows = Array.isArray(resp.rows) ? resp.rows : [];
  const columns = Array.isArray(resp.columns) ? resp.columns : [];
  return {
    rows,
    columns,
    ...(typeof resp.total === 'number' ? { total: resp.total } : {}),
    ...(typeof resp.truncated === 'boolean' ? { truncated: resp.truncated } : {}),
  };
}

// ---------------------------------------------------------------------------
// Hook options
// ---------------------------------------------------------------------------

export interface UseWidgetDataOptions {
  /** Dashboard uid (form A — backend resolves current_schema by uid). */
  uid?: string;
  /** Slicer values (drives the query key for automatic refetch). */
  slicerValues?: Record<string, unknown>;
  /** In-memory DSL (form B — edit-page linkage / MCP). Omit for form A. */
  dsl?: DashboardDSL | null;
  /** Published-version id (optional cache-busting dimension). */
  versionId?: string;
  /** Admin preview path (current_schema, no ownership gate). */
  isAdminPreview?: boolean;
  /** Optional auto-refresh interval (seconds). */
  refreshInterval?: number;
  /** Enable the query (default true). */
  enabled?: boolean;
}

// ---------------------------------------------------------------------------
// Hook
//
// Forms A/B (contract §3):
//  - Form A (published view): body omits `dsl`, includes `uid`. The backend
//    resolves current_schema by uid (PHP form-A→form-B forwarding bridge, §3.4).
//  - Form B (edit linkage / MCP): body includes the full `dsl` AST.
// The body NEVER contains a SQL string (SQL lives in dsl.queries[query_id].raw_sql,
// bound server-side by prepared statement, §6 Q5).
// ---------------------------------------------------------------------------

export function useWidgetData(widgetId: string, options: UseWidgetDataOptions = {}) {
  const { uid, slicerValues, dsl, versionId, isAdminPreview = false, refreshInterval, enabled = true } = options;

  return useQuery<MappedWidgetData>({
    queryKey: ['widget-data', uid ?? null, widgetId, slicerValues ?? {}, versionId ?? null, isAdminPreview ? 'preview' : 'public'],
    queryFn: async () => {
      return limitWidgetFetch(async () => {
        // Form B (dsl present) → POST with body. Form A → GET by uid.
        if (dsl) {
          const body: Record<string, unknown> = {
            widget_id: widgetId,
            dsl,
            ...(slicerValues && Object.keys(slicerValues).length > 0 ? { slicer_values: slicerValues } : {}),
          };
          const resp = await fetch(`${PUBLIC_BASE}/api_widget_data`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
          });
          if (resp.status === 429) {
            throw new RateLimitError(parseInt(resp.headers.get('Retry-After') || '60', 10));
          }
          const result = await resp.json();
          if (result.status !== 1) {
            throw new Error(result.info || '加载图表数据失败');
          }
          return mapResponse(result.data as WidgetDataResponse);
        }

        // Form A — uid-based GET.
        if (!uid) {
          throw new Error('缺少 uid（形态 A 需要 uid 定位 current_schema）');
        }
        const safeUid = encodeURIComponent(uid);
        const safeWidgetId = encodeURIComponent(widgetId);
        const endpoint = isAdminPreview
          ? `${ADMIN_BASE}/api_preview_widget_data?uid=${safeUid}&widgetId=${safeWidgetId}`
          : `${PUBLIC_BASE}/api_widget_data/uid/${safeUid}/widgetId/${safeWidgetId}`;
        const resp = await fetch(endpoint, { credentials: 'same-origin' });
        if (resp.status === 429) {
          throw new RateLimitError(parseInt(resp.headers.get('Retry-After') || '60', 10));
        }
        const result = await resp.json();
        if (result.status !== 1) {
          throw new Error(result.info || '加载图表数据失败');
        }
        return mapResponse(result.data as WidgetDataResponse);
      });
    },
    enabled,
    staleTime: 5 * 60 * 1000,
    retry: (failureCount, error) => {
      if (error instanceof RateLimitError) return failureCount < 3;
      return failureCount < 1;
    },
    retryDelay: (failureCount, error) => {
      if (error instanceof RateLimitError) {
        return Math.min((error.retryAfter || 60) * 1000, 10000) * (failureCount + 1);
      }
      return Math.min(1000 * 2 ** failureCount, 5000);
    },
    ...(refreshInterval && refreshInterval > 0 ? { refetchInterval: refreshInterval * 1000 } : {}),
  });
}

// ---------------------------------------------------------------------------
// Slicer options hook (contract §3.3)
//
// get_slicer_options(slicer_id, dsl?, uid?) → {value,label}[]
// Column-mapping rule (§3.3 line 244): first column → value, second → label,
// absent second → label = value. The mapSlicerOptions helper lives in
// utils/dslHelpers (pure, no react-query dep) so contract tests can exercise
// it directly.
// ---------------------------------------------------------------------------

export function useSlicerOptions(
  slicerId: string,
  options: { optionsQueryId?: string; uid?: string; dsl?: DashboardDSL | null; enabled?: boolean } = {},
) {
  const { optionsQueryId, uid, dsl, enabled = true } = options;
  return useQuery<SlicerOption[]>({
    queryKey: ['slicer-options', slicerId, uid ?? null, optionsQueryId ?? null],
    queryFn: async () => {
      // No options_query_id → free input, no fetch.
      if (!optionsQueryId) return [];
      return limitWidgetFetch(async () => {
        if (dsl) {
          const resp = await fetch(`${PUBLIC_BASE}/api_slicer_options`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slicer_id: slicerId, dsl }),
          });
          const result = await resp.json();
          if (result.status !== 1) return [];
          return mapSlicerOptions((result.data ?? []) as Record<string, unknown>[]);
        }
        if (!uid) return [];
        const resp = await fetch(
          `${PUBLIC_BASE}/api_slicer_options/uid/${encodeURIComponent(uid)}/slicerId/${encodeURIComponent(slicerId)}`,
          { credentials: 'same-origin' },
        );
        const result = await resp.json();
        if (result.status !== 1) return [];
        return mapSlicerOptions((result.data ?? []) as Record<string, unknown>[]);
      });
    },
    enabled: enabled && !!optionsQueryId,
    staleTime: 5 * 60 * 1000,
  });
}
