import type { DashboardDSL, WidgetSpec, QuerySpec, LayoutSlot } from '../types/dsl';

/**
 * Serialize the in-memory DSL into the persistence payload
 * (`dashboards.current_schema`, contract §5).
 *
 * Contract duties:
 *  - **Slim data:null** (§5): every widget's `data` is forced to null. The
 *    store keeps render data in `widgetDataCache`; the persisted schema never
 *    carries runtime data.
 *  - **Orphan query cleanup** (§5 line 325): drop any query referenced by
 *    neither `widgets[*].query_id` nor `slicers[*].options_query_id`. This
 *    preserves options queries (e.g. `q_opt_s1`) that have a slicer reference
 *    but no widget reference, while removing truly orphaned queries.
 *  - User-editable scope (layoutOverrides) is limited to slot coordinates +
 *    widget.title — runtime fields (status/error_msg/total/truncated/data) are
 *    stripped so the persisted schema is a clean topology description.
 */
export function buildDslSchema(
  dsl: DashboardDSL | null,
  layoutOverrides?: Record<string, Partial<Pick<LayoutSlot, 'x' | 'y' | 'w' | 'h'>>>,
): Record<string, unknown> {
  if (!dsl) {
    return { version: '3.0.0', layout: { regions: [], slots: [] }, queries: {}, widgets: {} };
  }

  // Apply user layout overrides (edit page drag/resize) onto slots.
  const overrides = layoutOverrides ?? {};
  const slots: LayoutSlot[] = (dsl.layout.slots ?? []).map((slot) => {
    const ov = overrides[slot.widget_id];
    if (!ov) return slot;
    return {
      ...slot,
      ...(typeof ov.x === 'number' ? { x: ov.x } : {}),
      ...(typeof ov.y === 'number' ? { y: ov.y } : {}),
      ...(typeof ov.w === 'number' ? { w: ov.w } : {}),
      ...(typeof ov.h === 'number' ? { h: ov.h } : {}),
    };
  });

  // Slim widgets: data:null + strip runtime-only fields, keep title.
  const slimWidgets: Record<string, WidgetSpec> = {};
  for (const [id, w] of Object.entries(dsl.widgets)) {
    slimWidgets[id] = {
      widget_id: w.widget_id,
      plugin_type: w.plugin_type,
      plugin_spec: w.plugin_spec,
      query_id: w.query_id,
      region: w.region,
      ...(typeof w.title === 'string' ? { title: w.title } : {}),
      ...(typeof w.full_width === 'boolean' ? { full_width: w.full_width } : {}),
      data: null,
      status: 'success',
    };
  }

  // Orphan query cleanup (§5): keep only queries referenced by widgets or slicers.
  const referencedQueryIds = new Set<string>();
  for (const w of Object.values(slimWidgets)) {
    if (typeof w.query_id === 'string' && w.query_id !== '') {
      referencedQueryIds.add(w.query_id);
    }
  }
  for (const s of dsl.slicers ?? []) {
    if (typeof s.options_query_id === 'string' && s.options_query_id !== '') {
      referencedQueryIds.add(s.options_query_id);
    }
  }
  const slimQueries: Record<string, QuerySpec> = {};
  for (const [qid, q] of Object.entries(dsl.queries)) {
    if (referencedQueryIds.has(qid)) {
      slimQueries[qid] = q;
    }
  }

  return {
    version: dsl.version,
    ...(typeof dsl.title === 'string' ? { title: dsl.title } : {}),
    layout: { regions: dsl.layout.regions, slots },
    queries: slimQueries,
    widgets: slimWidgets,
    slicers: dsl.slicers ?? [],
    interactions: dsl.interactions ?? [],
  };
}
