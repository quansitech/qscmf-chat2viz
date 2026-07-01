/**
 * declarative-frontend-adapter: dashboardStore.replaceDashboard contract tests.
 *
 * Pins the whole-tree replace semantics (contract §2):
 *  - clears old widgets, rebuilds from the payload map
 *  - data:null slim widgets reuse the prior cached data
 *  - widgets absent from the new tree are cleared
 *  - layout is updated
 *
 * The store is a Zustand+temporal+immer factory; we exercise it directly. To
 * avoid the auto-save HTTP side effect (saveDashboardDraft touches window/fetch),
 * we only call replaceDashboard and read state — never trigger an auto-save.
 */
import { describe, it, expect } from 'vitest';

// The store module reads crypto (generateId) which is available in the Node 18+
// global. It does not touch window at import time.
import { useDashboardStore, type Widget } from '../asset/inertia/Chat2viz/store/dashboardStore';

function makeWidget(id: string, data: unknown[] = [{ x: 1 }]): Widget {
  return {
    id,
    title: id,
    g2_spec: { type: 'interval' },
    data,
    sql: `SELECT ${id}`,
    status: 'chart',
    layout: { x: 0, y: 0, w: 12, h: 6 },
  };
}

describe('dashboardStore.replaceDashboard (whole-tree replace)', () => {
  it('clears old widgets and rebuilds from the payload map', () => {
    const store = useDashboardStore.getState();
    // Seed old state
    useDashboardStore.setState({
      widgets: { old1: makeWidget('old1'), old2: makeWidget('old2') },
    });

    store.replaceDashboard(
      [{ i: 'w1', x: 0, y: 0, w: 12, h: 4 }],
      {
        w1: { id: 'w1', title: 'W1', g2_spec: { type: 'line' }, data: [{ a: 1 }], status: 'chart', layout: { x: 0, y: 0, w: 12, h: 4 } },
        w2: { id: 'w2', title: 'W2', g2_spec: { type: 'pie' }, data: [{ b: 2 }], status: 'chart', layout: { x: 12, y: 0, w: 12, h: 4 } },
      },
    );

    const after = useDashboardStore.getState().widgets;
    // old widgets cleared; only w1/w2 remain
    expect(Object.keys(after).sort()).toEqual(['w1', 'w2']);
    expect(after.w1.title).toBe('W1');
    // data comes from payload (first generate — no cache reuse)
    expect(after.w1.data).toEqual([{ a: 1 }]);
  });

  it('data:null slim widgets reuse the prior cached data', () => {
    const cachedRows = [{ cached: true }];
    // Seed: w2 already has data in the store
    useDashboardStore.setState({
      widgets: {
        w1: makeWidget('w1', [{ old: 1 }]),
        w2: { ...makeWidget('w2', cachedRows) },
        w3: makeWidget('w3', [{ keep: 9 }]),
      },
    });

    useDashboardStore.getState().replaceDashboard(
      [{ i: 'w1', x: 0, y: 0, w: 12, h: 4 }, { i: 'w2', x: 12, y: 0, w: 12, h: 4 }],
      {
        // w1 sends fresh data → replaced
        w1: { id: 'w1', title: 'W1', g2_spec: { type: 'line' }, data: [{ fresh: 1 }], status: 'chart', layout: { x: 0, y: 0, w: 12, h: 4 } },
        // w2 sends data:null → reuse cached data
        w2: { id: 'w2', title: 'W2', g2_spec: { type: 'pie' }, data: null, status: 'chart', layout: { x: 12, y: 0, w: 12, h: 4 } },
      },
    );

    const after = useDashboardStore.getState().widgets;
    expect(after.w1.data).toEqual([{ fresh: 1 }]);
    // data:null → reused cached rows from the prior w2
    expect(after.w2.data).toEqual(cachedRows);
  });

  it('clears widgets absent from the new tree', () => {
    useDashboardStore.setState({
      widgets: { w1: makeWidget('w1'), w2: makeWidget('w2'), w3: makeWidget('w3') },
    });

    useDashboardStore.getState().replaceDashboard(
      [{ i: 'w1', x: 0, y: 0, w: 12, h: 4 }],
      { w1: { id: 'w1', title: 'W1', g2_spec: {}, data: [], status: 'chart', layout: { x: 0, y: 0, w: 12, h: 4 } } },
    );

    const after = useDashboardStore.getState().widgets;
    expect(Object.keys(after)).toEqual(['w1']);
  });

  it('updates store.layout-derived slot info per widget', () => {
    useDashboardStore.setState({ widgets: { w1: makeWidget('w1') } });

    useDashboardStore.getState().replaceDashboard(
      [{ i: 'w1', x: 5, y: 7, w: 6, h: 3 }],
      { w1: { id: 'w1', title: 'W1', g2_spec: {}, data: [], status: 'chart', layout: { x: 0, y: 0, w: 12, h: 6 } } },
    );

    const w1 = useDashboardStore.getState().widgets.w1;
    // layout from payload overrides the widget's embedded layout
    expect(w1.layout).toEqual({ x: 5, y: 7, w: 6, h: 3 });
  });
});
