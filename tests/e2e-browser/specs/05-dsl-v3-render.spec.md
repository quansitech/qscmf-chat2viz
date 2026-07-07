---
id: 05-dsl-v3-render
priority: P0
source-change: test-engineering-revamp
needs-llm: false          # fixture 注入，无需 NL2SQL
fixture: sales-dashboard  # 默认 fixture；needs-llm:false 必填
quarantined: false
reference: tests/e2e/agent-browser/dsl-v3-render.spec.md   # react-dsl-v3-renderer 所有，本 spec 引用不复制
---

# 05 — dsl-v3 渲染（引用 react-dsl-v3-renderer）

> **本 spec 是 pointer**。完整渲染断言（plugin 派发、unknown 降级、slim reuse、切片联动）由 `react-dsl-v3-renderer` change 所有：
> `tests/e2e/agent-browser/dsl-v3-render.spec.md`
>
> 复制会造成双源漂移；引用保证单一权威。

## 本 spec 的职责（AI runner 入口）

AI runner 读本 spec front-matter（`needs-llm:false` + `fixture:sales-dashboard`）→ 通过 `?__dsl_fixture=sales-dashboard` 注入黄金 DSL → 跑引用 spec 的断言。详细断言见引用 spec。

## 可用 fixture（来自 `frontend/fixtures/dsl-v3/`，react-dsl-v3-renderer 所有）

| fixture | 覆盖 |
|---|---|
| `sales-dashboard`（默认） | 标准多 widget 仪表盘 |
| `masked-columns` | PII 脱敏列 |
| `param-types` | 参数类型派发 |
| `slicer-interaction` | 切片联动 |
| `slim-reuse` | `data:null` 瘦态复用（DASHBOARD_REPLACE 不变量） |
| `unknown-plugin` | 未知 plugin_type 降级 |

> 上游改名/删除 fixture → `deterministic/fixtures-reference.contract.spec.ts` 红灯（漂移检测）。

## 来源
- 引用 spec：`tests/e2e/agent-browser/dsl-v3-render.spec.md`（react-dsl-v3-renderer）
- fixtures：`frontend/fixtures/dsl-v3/*.replace.json`

## 锚点
- v3 plugin 派发（5 种 plugin_type）：`asset/inertia/Chat2viz/plugins/registry.ts`
- DSL store（replaceDSL）：`asset/inertia/Chat2viz/store/dashboardStore.ts`
- 调试注入入口：`?__dsl_fixture=<name>`（react-dsl-v3-renderer task 12.1，非生产构建）

## artifacts
- 截图：`artifacts/05-dsl-v3-render/<fixture>.png`
