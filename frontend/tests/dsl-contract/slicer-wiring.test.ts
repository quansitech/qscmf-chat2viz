/**
 * §2.5/§3.2 slicer affected-set resolution.
 * §3.3 column-mapping rule for slicer options.
 */
import { describe, it, expect } from 'vitest';
import { resolveAffectedWidgets, mapSlicerOptions } from '@chat2viz/asset/utils/dslHelpers';
import type { DashboardDSL, SlicerSpec } from '@chat2viz/asset/types/dsl';

describe('§2.5/§3.2 slicer affected-set resolution', () => {
  it('resolves affected widgets from target_query_params', () => {
    const dsl: DashboardDSL = {
      version: '3.0.0',
      layout: { regions: ['content'], slots: [] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] }, q2: { query_id: 'q2', raw_sql: 'SELECT 2', params: [] }, q3: { query_id: 'q3', raw_sql: 'SELECT 3', params: [] } },
      widgets: {
        w1: { widget_id: 'w1', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q1', region: 'content' },
        w2: { widget_id: 'w2', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q2', region: 'content' },
        w3: { widget_id: 'w3', plugin_type: 'g2_chart', plugin_spec: {}, query_id: 'q3', region: 'content' },
      },
      slicers: [],
      interactions: [],
    };
    const slicer: SlicerSpec = {
      slicer_id: 's1',
      field: 'region',
      target_query_params: { q1: 'region', q2: 'region' },
    };
    const affected = resolveAffectedWidgets(slicer, dsl);
    expect(affected.sort()).toEqual(['w1', 'w2']);
    // w3 excluded (its query_id q3 is not a target).
    expect(affected).not.toContain('w3');
  });

  it('skips dangling query refs silently (no crash)', () => {
    const dsl: DashboardDSL = {
      version: '3.0.0',
      layout: { regions: ['content'], slots: [] },
      queries: {},
      widgets: {},
      slicers: [],
      interactions: [],
    };
    const slicer: SlicerSpec = { slicer_id: 's1', field: 'region', target_query_params: { q9: 'region' } };
    expect(resolveAffectedWidgets(slicer, dsl)).toEqual([]);
  });
});

describe('§3.3 slicer options column-mapping rule', () => {
  it('single-column rows map value=label', () => {
    const out = mapSlicerOptions([{ region: '华东' }, { region: '华北' }]);
    expect(out).toEqual([
      { value: '华东', label: '华东' },
      { value: '华北', label: '华北' },
    ]);
  });

  it('two-column rows map first→value, second→label', () => {
    const out = mapSlicerOptions([{ k: 'CN', name: '中国' }]);
    expect(out).toEqual([{ value: 'CN', label: '中国' }]);
  });

  it('empty/invalid input returns []', () => {
    expect(mapSlicerOptions([])).toEqual([]);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    expect(mapSlicerOptions(null as any)).toEqual([]);
  });
});
