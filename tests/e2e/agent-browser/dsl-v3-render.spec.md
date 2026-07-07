# DSL v3 Render — agent-browser E2E Spec

> Contract: `docs/contracts/dashboard-dsl-contract.md` v3.0.0
> Change: `react-dsl-v3-renderer`
> Artifacts: `tests/e2e/artifacts/`

## Goal

Verify end-to-end that the React frontend renders the full v3 DSL AST
correctly: all five plugin_types dispatch to their renderers, regions group
correctly, an unknown plugin_type degrades phase-appropriately (no blank),
and a slicer change triggers a refetch of the affected widgets.

## Preconditions

- The site is running (`http://qscmf13.qs.xhtest`).
- The frontend bundle is built with the latest `dashboard-view.js` (bump `?v=N`).
- Python v3 is NOT yet shipped, so this spec is **fixture-driven**: the
  `?__dsl_fixture=<name>` debug injection point loads golden DSL payloads from
  `frontend/fixtures/dsl-v3/` instead of the server schema.

## Journey

### 1. Load the sales-dashboard fixture (all plugin_types render)

```
agent-browser open 'http://qscmf13.qs.xhtest/extends/Chat2VizDashboard/view/uid/__fixture__?__dsl_fixture=sales-dashboard'
agent-browser wait for "总销售额"   # stat_card title
agent-browser screenshot tests/e2e/artifacts/sales-dashboard.png --annotate
```

**Assert**:
- The page renders 3 regions (header / content / footer) in order.
- The `stat_card` widgets (w1, w2) render antd `Statistic` values
  (`¥1,234,567.00` currency-formatted, and a number-formatted count).
- The `g2_chart` widget (w3) renders a G2 canvas (an `<canvas>` or svg).
- The `data_table` widget (w4) renders an antd `Table` with columns
  `month`, `amount`.
- The `markdown` widget (w5) in the footer renders the `## 数据说明` heading.
- No widget is blank.

### 2. Unknown plugin_type degrades (Phase 0-2 → error widget)

```
agent-browser open 'http://qscmf13.qs.xhtest/extends/Chat2VizDashboard/view/uid/__fixture__?__dsl_fixture=unknown-plugin'
agent-browser wait for "矩形树图"
agent-browser screenshot tests/e2e/artifacts/unknown-plugin.png --annotate
```

**Assert**:
- The widget titled "矩形树图（未注册插件...）" renders an **explicit error card**
  naming `treemap` and prompting regeneration (Phase 0-2 behavior — no silent
  markdown placeholder).
- The widget is NOT blank.

### 3. Slicer interaction triggers refetch (slicer-interaction fixture)

```
agent-browser open 'http://qscmf13.qs.xhtest/extends/Chat2VizDashboard/view/uid/__fixture__?__dsl_fixture=slicer-interaction'
agent-browser wait for "区域柱状图"
agent-browser screenshot tests/e2e/artifacts/slicer-before.png --annotate
# Change the 区域 slicer to "华北"
agent-browser eval "..."   # select the antd Select option
agent-browser wait for "华北"
agent-browser screenshot tests/e2e/artifacts/slicer-after.png --annotate
```

**Assert**:
- The `SlicerPanel` renders above the grid with a `区域` Select control
  populated from `q_opt_s1`.
- After changing the slicer to "华北", the affected widgets (w1柱状图, w2明细表)
  refetch (network requests visible), while w3 (月度趋势, query_id q1 but
  unaffected by the changed slicer dimension) does not refetch unnecessarily.

### 4. WIDGET_ERROR error_code UI differentiation (synthetic)

This is verified at the unit level (`widget-error-code.test.ts`) since
simulating a `WIDGET_ERROR` frame requires injecting SSE. The agent-browser
path is reserved for when Python v3 ships real error frames.

## Notes

- The `__fixture__` uid is a sentinel; the view page's `__dsl_fixture` query
  param overrides the server schema entirely, so no real dashboard row is
  needed.
- Artifacts (screenshots, recordings) land in `tests/e2e/artifacts/`.
- Once Python v3 ships, drop the `?__dsl_fixture` param and run against the
  real `/extends/Chat2VizDashboard/view/uid/<real-uid>` path; the same asserts
  apply.
