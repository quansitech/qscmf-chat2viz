/**
 * Content-aware default grid height suggestion for a widget.
 *
 * react-grid-layout lays widgets out in `h` units of ROW_HEIGHT (60px). This
 * helper derives a fitting initial height from the plugin_type/data so tables
 * and dense charts aren't cramped while single-value/small widgets don't waste
 * space.
 *
 * Dispatch is by `plugin_type` (contract §2.7): data_table scales with row
 * count; stat_card/markdown use a compact fixed height; others (g2_chart/map)
 * use the neutral default. The shared MIN_H = 3 constant is preserved.
 */

/** Grid constants mirrored from DashboardGrid. */
export const MIN_H = 3;
const DEFAULT_H = 6;
const COMPACT_H = 3;
const TABLE_MIN_H = 5;
const TABLE_MAX_H = 16;

export interface SuggestHeightInput {
  /** Widget plugin_type (contract §2.7). Drives the dispatch. */
  pluginType?: string;
  /** Legacy: g2_spec object (still accepted for the g2_chart path). */
  spec?: Record<string, unknown> | null;
  /** Current data rows (may be empty pre-fetch). */
  data?: unknown[] | null;
  /** Existing persisted height, if any (caller decides whether to honor it). */
  currentH?: number;
}

/**
 * Suggest a default `h` (in grid units) for a widget by plugin_type.
 *
 * Heuristics:
 *  - data_table: scale with row count — header + ~1 unit per 4 rows, clamped
 *    to [TABLE_MIN_H, TABLE_MAX_H] so large tables scroll internally.
 *  - stat_card / markdown: compact fixed height.
 *  - g2_chart / map / unknown: neutral DEFAULT_H (charts with many rows breathe).
 */
export function suggestHeight(input: SuggestHeightInput): number {
  const data = Array.isArray(input.data) ? input.data : [];
  const pluginType = input.pluginType ?? inferPluginTypeFromSpec(input.spec);

  if (pluginType === 'data_table') {
    const rows = data.length;
    return Math.min(TABLE_MAX_H, Math.max(TABLE_MIN_H, 2 + Math.ceil(rows / 4)));
  }

  if (pluginType === 'stat_card' || pluginType === 'markdown') {
    return COMPACT_H;
  }

  // g2_chart / map / unknown — neutral default with a slight bump for dense data.
  if (data.length > 50) return 8;
  return DEFAULT_H;
}

/**
 * Legacy compat: infer a plugin_type from a g2_spec when pluginType is absent.
 * `type === 'table'` → data_table (the v2 table spec convention); any other
 * valid spec → g2_chart; no spec → unknown (neutral default).
 */
function inferPluginTypeFromSpec(spec: Record<string, unknown> | null | undefined): string | undefined {
  if (!spec || typeof spec !== 'object') return undefined;
  const type = typeof spec.type === 'string' ? (spec.type as string) : '';
  if (type === 'table') return 'data_table';
  const hasSpec =
    type !== '' ||
    (typeof spec.mark === 'string' && (spec.mark as string) !== '') ||
    (Array.isArray(spec.children) && (spec.children as unknown[]).length > 0);
  return hasSpec ? 'g2_chart' : undefined;
}
