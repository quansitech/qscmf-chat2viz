/**
 * Pure DSL helper functions (no React / no hooks).
 *
 * Extracted here so the contract pipeline tests can exercise the linkage
 * resolution + column-mapping logic in the node environment without pulling
 * in React (the hooks that re-export these live in `hooks/`).
 */
import type { DashboardDSL, SlicerSpec } from '../types/dsl';

/**
 * Resolve the set of widget_ids affected by a slicer value change (contract
 * §2.5/§3.2). For the slicer's `target_query_params` (map<query_id, param>),
 * find every widget whose `query_id` is a key. Dangling query refs are skipped
 * silently.
 */
export function resolveAffectedWidgets(
  slicer: SlicerSpec,
  dsl: DashboardDSL,
): string[] {
  const affected = new Set<string>();
  const targetQueryIds = Object.keys(slicer.target_query_params ?? {});
  for (const widget of Object.values(dsl.widgets)) {
    if (typeof widget.query_id === 'string' && targetQueryIds.includes(widget.query_id)) {
      affected.add(widget.widget_id);
    }
  }
  return Array.from(affected);
}

export interface SlicerOption {
  value: string;
  label: string;
}

/**
 * Apply the §3.3 column-mapping rule to raw option rows.
 * First column → value, second column → label, absent second → label = value.
 */
export function mapSlicerOptions(rows: Record<string, unknown>[]): SlicerOption[] {
  if (!Array.isArray(rows) || rows.length === 0) return [];
  return rows.map((row) => {
    const keys = Object.keys(row);
    const valueKey = keys[0];
    const labelKey = keys[1];
    const value = row[valueKey];
    const label = labelKey !== undefined ? row[labelKey] : value;
    return {
      value: value == null ? '' : String(value),
      label: label == null ? (value == null ? '' : String(value)) : String(label),
    };
  });
}
