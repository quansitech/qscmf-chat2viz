---
id: 04-publish-view
priority: P1
source-change: test-engineering-revamp
needs-llm: true           # 需 NL2SQL 生成 widget data
fixture: null
quarantined: false
---

# 04 — 发布与公开查看

验证发布 → 公开查看 → widget data 查询/缓存全链路，含 SQL 安全反例（无 SQL 泄漏、强制 LIMIT、拒绝非 SELECT）。

## 前置条件
- 已创建含 >= 2 widget 的仪表盘（先跑 03）
- NL2SQL 服务在线

## 步骤
1. `lib/sse.mjs` → `installSseInterceptor(page)`
2. 多轮对话生成 2 个 widget
3. `fetch POST publish`，body `{uid}`
4. **公开查看**：在未登录上下文访问 `ROUTES.publicView(uid)`，渲染所有 widget
5. dump 页面 `window.__PAGE_DATA__`（安全反例取证）
6. 查询 widget data 接口（首次）；二次查询（缓存命中）
7. 访问**未发布**仪表盘的 publicView → 应拒绝
8. 查看版本历史
9. **SQL 安全反例-非 SELECT**：构造 `DELETE/UPDATE` SQL → 应拒绝
10. **强制 LIMIT**：无 LIMIT 的大结果 SQL → 返回行数 <= 上限

## 预期
- publish：`status===1`
- 公开 view：渲染所有 widget，data 含预期列
- widget data 二次查询命中缓存（响应更快/缓存标记）
- 未发布 view：拒绝（403 或错误页）
- 版本历史：可回溯各版本
- 非 SELECT SQL：拒绝（`SQL_VALIDATION_ERROR`）
- 强制 LIMIT：返回行数 <= `sql_max_rows`

## 反例（SQL 泄漏 — A3，最高优先级安全项）
- `__PAGE_DATA__` **不应**包含原始 SQL 文本（`SELECT`/`current_schema`/`FROM` 等）
- 公开 view 的 widget data 只含结果行，不含 SQL 语句或库结构信息

## 来源
- `.claude/workflows/test/04-publish-view.mjs`（testPublishDashboard / testViewPublished / testWidgetDataQuery / testWidgetDataCache / testUnpublishedViewRejected / testVersionHistory / testRejectNonSelect / testForceLimit）

## artifacts
- 截图 + 视频 + `__PAGE_DATA__` dump（安全反例取证，脱敏后保留）
