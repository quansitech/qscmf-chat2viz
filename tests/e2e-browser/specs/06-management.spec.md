---
id: 06-management
priority: P1
source-change: test-engineering-revamp
needs-llm: true           # Conversation + Save-Undo 需真实生成
fixture: null
quarantined: false
---

# 06 — Dashboard 管理全流程

列表 / CRUD / 对话 / 保存撤销 / 布局五阶段的定性检查（AI 驱动，输出 PASS/DEFECT + severity）。

## 前置条件
- 已登录 admin（`lib/auth.mjs`）
- NL2SQL 服务在线

## 阶段与预期

### Phase 1 — Dashboard-List
- 列表页 `ROUTES.dashboardList` 渲染条目
- 状态标签（draft/published/archived）可见

### Phase 2 — Dashboard-CRUD（经 UI）
- create / read / update / archive 闭环（断言同 01，但走 UI 而非直 fetch）
- 标题编辑后自动保存（防抖 ~3s）

### Phase 3 — Conversation
- 单轮对话生成 widget（断言同 02），`SELECTORS.widgetCard` count >= 1

### Phase 4 — Save-Undo
- 自动保存：编辑标题 → 等 4s → "未保存"指示器消失
- 手动保存：Ctrl+S → 成功反馈
- undo/redo：未实现则报 DEFECT(minor)（`Ctrl+Z` 撤销最后操作）

### Phase 5 — Layout
- 拖拽 widget 调整布局 → 等 4s 自动保存 → 刷新后布局持久

## 来源
- `.claude/workflows/e2e/chat2viz-management.js`（Dashboard-List / Dashboard-CRUD / Conversation / Save-Undo / Layout 5 phase）

## artifacts
- 各阶段截图：`artifacts/06-management/mgmt-0{1..5}.png`
