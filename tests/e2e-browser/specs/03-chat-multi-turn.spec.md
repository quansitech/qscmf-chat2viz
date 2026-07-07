---
id: 03-chat-multi-turn
priority: P1
source-change: test-engineering-revamp
needs-llm: true
fixture: null
quarantined: false
---

# 03 — 多轮对话 widget 累积

验证声明式整树在多轮对话中：新 widget 追加、旧 widget 保留（复用 `data:null` 瘦态），`conversation_id` 跨轮一致。

## 前置条件
- 同 02，空白仪表盘
- NL2SQL 服务在线

## 步骤
1. `lib/sse.mjs` → `installSseInterceptor(page)`
2. **第 1 轮**："统计每个地区的订单数" → 等待 `SELECTORS.widgetCard` count >= 1
3. **第 2 轮**："再按月份拆分趋势" → 等待 count >= 2；断言第 1 个 widget 仍在
4. **第 3 轮**：再问一题 → 等待 count >= 3
5. 验证 `conversation_id` 帧跨轮一致（同一会话）
6. 验证 dashboard context 跨轮传递（编辑模式累积生效）

## 预期
- 第 i 轮后 widget 总数 >= i
- 第 1 轮的 widget 在第 2、3 轮的 `DASHBOARD_REPLACE` 整树中仍存在（声明式保留，非替换）
- 每轮 `conversation_id` 帧值一致（同会话续接）

## 反例
- 第 2 轮不应清空第 1 轮 widget（增量→声明式后整树保留）
- 不应每轮发不同 `conversation_id`（同会话续接，PHP 是 conversation_id 唯一权威）

## 子流程（可选）
- **取消流**（testCancelStream）：发送后立即取消，半途 widget 不应落地；后续轮次正常

## 来源
- `.claude/workflows/test/03-chat-multi-turn.mjs`（testTwoTurnAccumulation / testThreeTurnAccumulation / testConversationIdPassed / testDashboardContextPassed / testCancelStream）

## artifacts
- 每轮截图 + 视频
