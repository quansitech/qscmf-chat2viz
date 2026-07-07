/**
 * §4.1 whole-tree replace + slim cache reuse + four-status mapping.
 *
 * Drives the store from fixture inputs and asserts the contract behaviors.
 */
import { beforeEach, describe, it, expect } from 'vitest';
import { useDashboardStore } from '@chat2viz/asset/store/dashboardStore';
import type { SSEDashboardReplaceV3 } from '@chat2viz/asset/types/dsl';
import slimReuse from '../../fixtures/dsl-v3/slim-reuse.replace.json';

beforeEach(() => {
  useDashboardStore.setState({ dsl: null, slicerValues: {}, widgetDataCache: {}, isDirty: false });
});

describe('§4.1 whole-tree replace + slim cache reuse', () => {
  it('applies the slim-reuse fixture: w1 fresh, w2/w3 reuse cache (data:null)', () => {
    const payload = slimReuse as unknown as SSEDashboardReplaceV3;

    // Seed cache for w2/w3 (simulating a prior round).
    useDashboardStore.getState().replaceDSL({
      version: '3.0.0',
      layout: { regions: ['content'], slots: [
        { widget_id: 'w2', region: 'content', x: 12, y: 0, w: 12, h: 6 },
        { widget_id: 'w3', region: 'content', x: 0, y: 6, w: 24, h: 6 },
      ] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: {
        w2: { widget_id: 'w2', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ prior: 2 }], columns: ['prior'] }, status: 'success' },
        w3: { widget_id: 'w3', plugin_type: 'data_table', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{ prior: 3 }], columns: ['prior'] }, status: 'success' },
      },
      slicers: [], interactions: [],
    });
    expect(useDashboardStore.getState().widgetDataCache.w2.rows).toEqual([{ prior: 2 }]);

    // Apply the slim-reuse payload.
    useDashboardStore.getState().replaceDSL(payload);

    const cache = useDashboardStore.getState().widgetDataCache;
    // w1 fresh data.
    expect(cache.w1.rows).toEqual([{ region: '华东', amount: 9999 }]);
    // w2/w3 reused cache (data:null).
    expect(cache.w2.rows).toEqual([{ prior: 2 }]);
    expect(cache.w3.rows).toEqual([{ prior: 3 }]);
  });

  it('maps lifecycle status success/error to render status chart/error', () => {
    useDashboardStore.getState().replaceDSL({
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: {
        ok: { widget_id: 'ok', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: { rows: [{}], columns: [] }, status: 'success' },
        bad: { widget_id: 'bad', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', status: 'error', error_msg: 'fail' },
      },
      slicers: [], interactions: [],
    });

    const cache = useDashboardStore.getState().widgetDataCache;
    expect(cache.ok.status).toBe('chart');
    expect(cache.bad.status).toBe('error');
  });
});
