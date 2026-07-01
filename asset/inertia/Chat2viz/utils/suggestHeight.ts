/**
 * Content-aware default grid height suggestion for a widget.
 *
 * react-grid-layout lays widgets out in `h` units of ROW_HEIGHT (60px). The
 * AI emits widgets with a flat default of h=6; this helper derives a more
 * fitting initial height from the spec/data so tables and dense charts aren't
 * cramped while single-value/small charts don't waste space.
 *
 * Returned values always respect `minH` (the grid's minimum) and never shrink
 * an explicitly-large persisted layout below its current height — callers
 * merge the suggestion only when the widget has no meaningful layout yet.
 */

/** Grid constants mirrored from PreviewPanel/DashboardView. */
export const MIN_H = 3;
const DEFAULT_H = 6;

export interface SuggestHeightInput {
  /** g2_spec object (may be empty during placeholder phase). */
  spec?: Record<string, unknown> | null;
  /** Current data rows (may be empty pre-fetch). */
  data?: unknown[] | null;
  /** Existing persisted height, if any (caller decides whether to honor it). */
  currentH?: number;
}

/**
 * Suggest a default `h` (in grid units) for a widget.
 *
 * Heuristics:
 *  - `type === 'table'`: scale with row count — header + ~1 unit per 4 rows,
 *    capped so very large tables still scroll internally.
 *  - other chart types with many data points: slightly taller to breathe.
 *  - empty spec/data (placeholder): the neutral DEFAULT_H.
 */
export function suggestHeight(input: SuggestHeightInput): number {
  const spec = input.spec ?? null;
  const data = Array.isArray(input.data) ? input.data : [];

  const type = typeof spec?.type === 'string' ? (spec.type as string) : '';
  const hasSpec =
    spec !== null &&
    (type !== '' ||
      (typeof spec?.mark === 'string' && (spec.mark as string) !== '') ||
      (Array.isArray(spec?.children) && (spec.children as unknown[]).length > 0));

  // No spec yet (DASHBOARD_INIT placeholder) — neutral default.
  if (!hasSpec) return DEFAULT_H;

  if (type === 'table') {
    // Table: header (~1) + 1 unit per ~4 visible rows, clamped to [5, 16].
    const rows = data.length;
    const h = Math.min(16, Math.max(5, 2 + Math.ceil(rows / 4)));
    return h;
  }

  // Charts with many records benefit from a bit more vertical room.
  if (data.length > 50) return 8;
  if (data.length > 0) return DEFAULT_H;

  // Spec present but no data yet — keep the neutral default.
  return DEFAULT_H;
}
