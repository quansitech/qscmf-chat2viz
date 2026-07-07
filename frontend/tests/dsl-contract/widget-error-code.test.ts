/**
 * §4 line 274: WIDGET_ERROR error_code persisted on the widget + drives UI
 * differentiation (QUERY_TIMEOUT / SQL_VALIDATION_ERROR / DATABASE_ERROR /
 * unknown → generic).
 */
import { beforeEach, describe, it, expect } from 'vitest';
import { useDashboardStore } from '@chat2viz/asset/store/dashboardStore';
import errorFrames from '../../fixtures/dsl-v3/widget-error.frames.json';

beforeEach(() => {
  useDashboardStore.setState({ dsl: null, slicerValues: {}, widgetDataCache: {}, isDirty: false });
});

describe('§4 line 274 WIDGET_ERROR error_code', () => {
  it('persists error_code + error_msg on the widget cache', () => {
    useDashboardStore.getState().replaceDSL({
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: { w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content', status: 'success' } },
      slicers: [], interactions: [],
    });

    useDashboardStore.getState().setWidgetError('w1', 'QUERY_TIMEOUT', '查询超时');
    const cache = useDashboardStore.getState().widgetDataCache.w1;
    expect(cache.error_code).toBe('QUERY_TIMEOUT');
    expect(cache.error_msg).toBe('查询超时');
    expect(cache.status).toBe('error');
  });

  it('the widget-error fixture covers the three known codes + an unknown one', () => {
    const frames = (errorFrames as { frames: Array<{ error_code: string }> }).frames;
    const codes = frames.map((f) => f.error_code);
    expect(codes).toContain('QUERY_TIMEOUT');
    expect(codes).toContain('SQL_VALIDATION_ERROR');
    expect(codes).toContain('DATABASE_ERROR');
    // Forward-compatible: an unmapped code is carried too.
    expect(codes.some((c) => !['QUERY_TIMEOUT', 'SQL_VALIDATION_ERROR', 'DATABASE_ERROR'].includes(c))).toBe(true);
  });
});
