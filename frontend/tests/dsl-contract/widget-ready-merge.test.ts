/**
 * §4.2 WIDGET_READY progressive merge + idempotent whole-tree overlay + the
 * no-flicker rule (reference-identical rows → skip store write).
 */
import { beforeEach, describe, it, expect } from 'vitest';
import { useDashboardStore } from '@chat2viz/asset/store/dashboardStore';
import type { SSEDashboardReplaceV3 } from '@chat2viz/asset/types/dsl';

beforeEach(() => {
  useDashboardStore.setState({ dsl: null, slicerValues: {}, widgetDataCache: {}, isDirty: false });
});

describe('§4.2 READY→REPLACE no-flicker + progressive merge', () => {
  it('WIDGET_READY writes progressive data + tracks the rows ref', () => {
    useDashboardStore.getState().replaceDSL({
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: { w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', status: 'success' } },
      slicers: [], interactions: [],
    });

    const rows = [{ progressive: true }];
    useDashboardStore.getState().updateWidgetDataCache('w1', { rows, columns: ['progressive'] });

    const cache = useDashboardStore.getState().widgetDataCache.w1;
    expect(cache.rows).toBe(rows);
    expect(cache.lastReadyRowsRef).toBe(rows);
  });

  it('replaceDSL preserves the lastReadyRowsRef when payload data is null (slim)', () => {
    // Seed via WIDGET_READY.
    useDashboardStore.getState().replaceDSL({
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: { w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', status: 'success' } },
      slicers: [], interactions: [],
    });
    const rows = [{ ready: true }];
    useDashboardStore.getState().updateWidgetDataCache('w1', { rows, columns: ['ready'] });

    // Slim replace: data:null → ref preserved for the no-flicker compare.
    const slim: SSEDashboardReplaceV3 = {
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: { w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', data: null, status: 'success' } },
      slicers: [], interactions: [],
    };
    useDashboardStore.getState().replaceDSL(slim);

    const cache = useDashboardStore.getState().widgetDataCache.w1;
    // Ref preserved across the slim replace.
    expect(cache.lastReadyRowsRef).toBe(rows);
  });
});
