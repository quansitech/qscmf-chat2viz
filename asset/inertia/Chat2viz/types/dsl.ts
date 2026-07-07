/**
 * DashboardDSL v3.0.0 — TypeScript AST mirror of
 * `docs/contracts/dashboard-dsl-contract.md` §2.
 *
 * AUTHORITATIVE plugin_spec sub-schemas (contract §2.7 — written back to the
 * contract from this file). The five widget plugin_types:
 *
 *   g2_chart    — plugin_spec = a G2 v5 spec (object with `type`/`mark`/
 *                 `encode`; NO inline `data`). Rendered by G2ChartPlugin
 *                 via LazyG2Renderer.
 *   stat_card   — plugin_spec = { field: string, label?: string,
 *                 formatter?: 'currency' | 'percent' | 'number' | 'compact'
 *                 | 'none' }. Reads the first data row's `field` value.
 *                 NEVER uses G2 gauge (extension not installed).
 *   data_table  — plugin_spec = { columns?: string[] }. Column discovery:
 *                 plugin_spec.columns → data keys fallback.
 *   map         — plugin_spec = { regionField: string, valueField: string,
 *                 mapKey: string }. Rendered as a markdown stub placeholder
 *                 this change (no map library imported).
 *   markdown    — plugin_spec = { content: string }. Rendered by react-markdown
 *                 + rehype-sanitize; also the universal fallback.
 *
 * `filter` is NOT a widget plugin_type (contract §2.7 line 199): it has no
 * query_id/data (it is a parameter source, not a data display) and lives in
 * `slicers[]` (§2.5), rendered by <SlicerPanel>.
 *
 * `formatter` vocabulary (stat_card, authoritative): currency | percent |
 * number | compact | none. Unknown values default to `none`.
 */

// ---------------------------------------------------------------------------
// §2.7 PluginType — the five widget plugin_types (filter deliberately absent)
// ---------------------------------------------------------------------------

export type PluginType = 'g2_chart' | 'stat_card' | 'data_table' | 'map' | 'markdown';

/**
 * Region enum (contract §2.2). A dashboard may use any subset, in any order;
 * the literal union documents the conventional regions.
 */
export type Region = 'header' | 'content' | 'footer';

// ---------------------------------------------------------------------------
// §2.2 LayoutConfig
// ---------------------------------------------------------------------------

export interface LayoutSlot {
  widget_id: string;
  region: string;
  x: number;
  y: number;
  w: number;
  h: number;
}

export interface LayoutConfig {
  regions: string[];
  slots: LayoutSlot[];
}

// ---------------------------------------------------------------------------
// §2.3 QuerySpec
// ---------------------------------------------------------------------------

export type QueryParamType = 'string' | 'number' | 'date' | 'datetime';

export interface QueryParam {
  name: string;
  type: QueryParamType;
  required?: boolean;
  default?: unknown;
}

export interface QuerySpec {
  query_id: string;
  raw_sql: string;
  params?: QueryParam[];
  masked_columns?: string[];
}

// ---------------------------------------------------------------------------
// §2.4 WidgetSpec
// ---------------------------------------------------------------------------

/** Lifecycle status carried on the wire (contract §2.4). */
export type WidgetLifecycleStatus = 'success' | 'error' | 'removed';

/**
 * Runtime widget data envelope. `data:null` is the slim signal (the widget's
 * SQL did not change → the frontend reuses its cached data, contract §4.1).
 */
export interface WidgetData {
  rows: Record<string, unknown>[];
  columns: string[];
}

export interface WidgetSpec {
  widget_id: string;
  plugin_type: PluginType | string; // string widened — unknown types degrade
  plugin_spec: Record<string, unknown>;
  query_id: string;
  region: string;
  title?: string;
  full_width?: boolean;
  data?: WidgetData | null;
  total?: number;
  truncated?: boolean;
  status?: WidgetLifecycleStatus;
  error_msg?: string;
  /** §4 line 274: aligned to Python errors.py `.code` (QUERY_TIMEOUT etc.). */
  error_code?: string;
}

// ---------------------------------------------------------------------------
// §2.5 SlicerSpec
// ---------------------------------------------------------------------------

export interface SlicerSpec {
  slicer_id: string;
  field: string;
  label?: string;
  options_query_id?: string;
  /** map<query_id, param_name> — the linkage topology edge source. */
  target_query_params: Record<string, string>;
}

// ---------------------------------------------------------------------------
// §2.6 InteractionSpec
// ---------------------------------------------------------------------------

export type InteractionEventType = 'click' | 'change';
export type InteractionAction = 'filter' | 'drill' | 'detail';

export interface InteractionSpec {
  source_id: string;
  event_type: InteractionEventType;
  target_id: string;
  action: InteractionAction;
  params?: Record<string, unknown>;
}

// ---------------------------------------------------------------------------
// §2.1 DashboardDSL
// ---------------------------------------------------------------------------

export interface DashboardDSL {
  version: string;
  title?: string;
  layout: LayoutConfig;
  queries: Record<string, QuerySpec>;
  widgets: Record<string, WidgetSpec>;
  slicers?: SlicerSpec[];
  interactions?: InteractionSpec[];
}

// ---------------------------------------------------------------------------
// §4.1 SSE DASHBOARD_REPLACE payload (MAJOR v3.0.0)
//
// The payload is the DashboardDSL AST plus two streaming-only fields
// (`answer`, `suggested_followups`). The runtime guard `isDashboardReplaceV3`
// narrows untyped SSE data to this shape.
// ---------------------------------------------------------------------------

export interface SSEDashboardReplaceV3 extends DashboardDSL {
  /** Streamed LLM answer text. `""` if already streamed token-by-token. */
  answer?: string;
  /** ≤3 derived follow-up questions for the chat panel. */
  suggested_followups?: string[];
}

/**
 * Runtime type guard: narrows untyped SSE `data` to the v3 DSL payload.
 *
 * Validates the top-level shape (version + the four mandatory sections). Does
 * NOT deep-validate every widget — replaceDSL tolerates per-widget bad fields
 * and degrades them individually (design D6). Returns false (not throw) so the
 * SSE dispatcher can route malformed payloads to `store.error` without an
 * exception unwinding the stream consumer.
 */
export function isDashboardReplaceV3(data: unknown): data is SSEDashboardReplaceV3 {
  if (data === null || typeof data !== 'object' || Array.isArray(data)) return false;
  const d = data as Record<string, unknown>;
  // Stricter version match: "3." followed by a digit (rejects "30.x", "3xyz").
  if (typeof d.version !== 'string' || !/^3\.\d+/.test(d.version)) return false;
  // layout must be an object with array regions + slots (prevents downstream
  // TypeError when updateLayout reads layout.slots.find).
  if (d.layout === null || typeof d.layout !== 'object' || Array.isArray(d.layout)) return false;
  const layout = d.layout as Record<string, unknown>;
  if (!Array.isArray(layout.regions) || !Array.isArray(layout.slots)) return false;
  if (d.queries === null || typeof d.queries !== 'object' || Array.isArray(d.queries)) return false;
  if (d.widgets === null || typeof d.widgets !== 'object' || Array.isArray(d.widgets)) return false;
  return true;
}

// ---------------------------------------------------------------------------
// §4 SSE WIDGET_READY / WIDGET_ERROR payload types
// ---------------------------------------------------------------------------

export interface SseWidgetReady {
  widget_id: string;
  data: WidgetData;
  total?: number;
  truncated?: boolean;
}

export interface SseWidgetError {
  widget_id: string;
  error_msg?: string;
  /** §4 line 274: aligned to Python errors.py `.code`. */
  error_code?: string;
}
