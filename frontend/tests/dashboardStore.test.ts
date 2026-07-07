/**
 * react-dsl-v3-renderer: dashboardStore.replaceDSL contract tests (v3).
 *
 * Pins the DSL AST whole-tree replace semantics (contract §4.1):
 *  - clears the old DSL, rebuilds the AST from the payload (queries/widgets/
 *    slicers/interactions/layout)
 *  - data:null slim widgets reuse the prior widgetDataCache entry
 *  - widgets absent from the new tree are cleared
 *  - bad fields degrade (missing query_id → widget status='error') rather than
 *    mid-tree throwing
 *  - §4.2 idempotency: reference-identical rows skip store writes
 */
import { beforeEach, describe, it, expect } from 'vitest';
import { useDashboardStore } from '../asset/inertia/Chat2viz/store/dashboardStore';
import type { SSEDashboardReplaceV3 } from '../asset/inertia/Chat2viz/types/dsl';

function makePayload(overrides: Partial<SSEDashboardReplaceV3> = {}): SSEDashboardReplaceV3 {
  return {
    version: '3.0.0',
    title: '测试看板',
    layout: {
      regions: ['content'],
      slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }],
    },
    queries: {
      q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] },
    },
    widgets: {
      w1: {
        widget_id: 'w1',
        plugin_type: 'g2_chart',
        plugin_spec: { type: 'interval' },
        query_id: 'q1',
        region: 'content',
        title: 'W1',
        data: { rows: [{ a: 1 }], columns: ['a'] },
        total: 1,
        truncated: false,
        status: 'success',
      },
    },
    slicers: [],
    interactions: [],
    answer: '',
    ...overrides,
  };
}

beforeEach(() => {
  // Reset the store between tests so the cache/state is clean.
  useDashboardStore.setState({
    dsl: null,
    slicerValues: {},
    widgetDataCache: {},
    isDirty: false,
  });
});

describe('dashboardStore.replaceDSL (v3 whole-tree replace)', () => {
  it('clears the old DSL and rebuilds the AST from the payload', () => {
    useDashboardStore.getState().replaceDSL(makePayload());

    const state = useDashboardStore.getState();
    expect(state.dsl).not.toBeNull();
    expect(state.dsl!.version).toBe('3.0.0');
    expect(Object.keys(state.dsl!.widgets)).toEqual(['w1']);
    expect(state.dsl!.widgets.w1.plugin_type).toBe('g2_chart');
    // data lands in the cache.
    expect(state.widgetDataCache.w1.rows).toEqual([{ a: 1 }]);
    expect(state.widgetDataCache.w1.status).toBe('chart');
  });

  it('data:null slim widgets reuse the prior cache entry (§4.1)', () => {
    // Seed: w2 already has cached data via a prior replace.
    useDashboardStore.getState().replaceDSL(makePayload({
      widgets: {
        w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ old: 1 }], columns: ['old'] }, status: 'success' },
        w2: { widget_id: 'w2', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ cached: true }], columns: ['cached'] }, status: 'success' },
      },
      layout: { regions: ['content'], slots: [
        { widget_id: 'w1', region: 'content', x: 0, y: 0, w: 6, h: 6 },
        { widget_id: 'w2', region: 'content', x: 6, y: 0, w: 6, h: 6 },
      ] },
    }));
    expect(useDashboardStore.getState().widgetDataCache.w2.rows).toEqual([{ cached: true }]);

    // Second replace: w2 data:null → reuse cache; w1 fresh.
    useDashboardStore.getState().replaceDSL(makePayload({
      widgets: {
        w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ fresh: 1 }], columns: ['fresh'] }, status: 'success' },
        w2: { widget_id: 'w2', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: null, status: 'success' },
      },
      layout: { regions: ['content'], slots: [
        { widget_id: 'w1', region: 'content', x: 0, y: 0, w: 6, h: 6 },
        { widget_id: 'w2', region: 'content', x: 6, y: 0, w: 6, h: 6 },
      ] },
    }));

    const cache = useDashboardStore.getState().widgetDataCache;
    expect(cache.w1.rows).toEqual([{ fresh: 1 }]);
    // data:null → reused.
    expect(cache.w2.rows).toEqual([{ cached: true }]);
  });

  it('clears widgets absent from the new tree', () => {
    useDashboardStore.getState().replaceDSL(makePayload({
      widgets: {
        w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
        w2: { widget_id: 'w2', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
      },
      layout: { regions: ['content'], slots: [
        { widget_id: 'w1', region: 'content', x: 0, y: 0, w: 6, h: 6 },
        { widget_id: 'w2', region: 'content', x: 6, y: 0, w: 6, h: 6 },
      ] },
    }));
    expect(Object.keys(useDashboardStore.getState().dsl!.widgets).sort()).toEqual(['w1', 'w2']);

    // Second replace with only w1.
    useDashboardStore.getState().replaceDSL(makePayload({
      widgets: {
        w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
      },
    }));

    expect(Object.keys(useDashboardStore.getState().dsl!.widgets)).toEqual(['w1']);
    expect(useDashboardStore.getState().widgetDataCache.w2).toBeUndefined();
  });

  it('degrades a widget referencing a missing query_id (status=error), never throws', () => {
    useDashboardStore.getState().replaceDSL(makePayload({
      widgets: {
        w_bad: { widget_id: 'w_bad', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q_missing', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
        w_ok: { widget_id: 'w_ok', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
      },
      layout: { regions: ['content'], slots: [
        { widget_id: 'w_bad', region: 'content', x: 0, y: 0, w: 6, h: 6 },
        { widget_id: 'w_ok', region: 'content', x: 6, y: 0, w: 6, h: 6 },
      ] },
    }));

    const state = useDashboardStore.getState();
    // bad widget degraded to error; ok widget unaffected.
    expect(state.widgetDataCache.w_bad.status).toBe('error');
    expect(state.widgetDataCache.w_ok.status).toBe('chart');
  });
});

describe('dashboardStore WIDGET_READY + WIDGET_ERROR (§4)', () => {
  it('updateWidgetDataCache writes progressive data + tracks the rows ref', () => {
    useDashboardStore.getState().replaceDSL(makePayload());
    const rows = [{ progressive: true }];
    useDashboardStore.getState().updateWidgetDataCache('w1', { rows, columns: ['progressive'] }, { total: 1 });

    const cache = useDashboardStore.getState().widgetDataCache.w1;
    expect(cache.rows).toBe(rows);
    expect(cache.lastReadyRowsRef).toBe(rows);
    expect(cache.total).toBe(1);
  });

  it('setWidgetError persists error_code + error_msg (§4 line 274)', () => {
    useDashboardStore.getState().replaceDSL(makePayload());
    useDashboardStore.getState().setWidgetError('w1', 'QUERY_TIMEOUT', '查询超时');

    const cache = useDashboardStore.getState().widgetDataCache.w1;
    expect(cache.status).toBe('error');
    expect(cache.error_code).toBe('QUERY_TIMEOUT');
    expect(cache.error_msg).toBe('查询超时');
  });
});
