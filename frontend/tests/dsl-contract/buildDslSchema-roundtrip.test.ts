/**
 * §5 persistence: buildDslSchema round-trip + slim data:null + orphan query
 * cleanup (q_opt retained, unreferenced q9 dropped) + idempotency.
 */
import { describe, it, expect } from 'vitest';
import { buildDslSchema } from '@chat2viz/asset/utils/buildSchema';
import type { DashboardDSL } from '@chat2viz/asset/types/dsl';

function makeDsl(): DashboardDSL {
  return {
    version: '3.0.0',
    title: 'orphan-test',
    layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
    queries: {
      q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] },
      q9: { query_id: 'q9', raw_sql: 'SELECT 9 -- orphan', params: [] },
      q_opt_s1: { query_id: 'q_opt_s1', raw_sql: 'SELECT DISTINCT region FROM t', params: [] },
    },
    widgets: {
      w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ a: 1 }], columns: ['a'] }, status: 'success' },
    },
    slicers: [{ slicer_id: 's1', field: 'region', options_query_id: 'q_opt_s1', target_query_params: { q1: 'region' } }],
    interactions: [],
  };
}

describe('§5 buildDslSchema persistence', () => {
  it('emits slim widgets (data:null)', () => {
    const out = buildDslSchema(makeDsl());
    const widgets = (out.widgets ?? {}) as Record<string, { data: unknown }>;
    for (const w of Object.values(widgets)) {
      expect(w.data).toBeNull();
    }
  });

  it('drops orphan queries, keeps widget-referenced + slicer-referenced queries', () => {
    const out = buildDslSchema(makeDsl());
    const queries = (out.queries ?? {}) as Record<string, unknown>;
    // q1 referenced by w1 → kept.
    expect(queries.q1).toBeDefined();
    // q_opt_s1 referenced by slicer s1 → kept.
    expect(queries.q_opt_s1).toBeDefined();
    // q9 referenced by nobody → dropped.
    expect(queries.q9).toBeUndefined();
  });

  it('preserves layout regions + slots', () => {
    const dsl = makeDsl();
    const out = buildDslSchema(dsl);
    expect(out.layout.regions).toEqual(['content']);
    expect((out.layout.slots as unknown[]).length).toBe(1);
  });

  it('idempotent: buildDslSchema(buildDslSchema(dsl)) equals buildDslSchema(dsl) for queries/widgets keys', () => {
    const dsl = makeDsl();
    const once = buildDslSchema(dsl);
    const twice = buildDslSchema(once as unknown as DashboardDSL);
    expect(Object.keys(twice.queries ?? {}).sort()).toEqual(Object.keys(once.queries ?? {}).sort());
    expect(Object.keys(twice.widgets ?? {}).sort()).toEqual(Object.keys(once.widgets ?? {}).sort());
  });

  it('null dsl → empty schema', () => {
    const out = buildDslSchema(null);
    expect(out.version).toBe('3.0.0');
    expect(out.queries).toEqual({});
    expect(out.widgets).toEqual({});
  });
});
