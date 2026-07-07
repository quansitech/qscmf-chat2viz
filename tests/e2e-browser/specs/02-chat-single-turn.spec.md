---
id: 02-chat-single-turn
priority: P0
source-change: test-engineering-revamp
needs-llm: true           # 需真实 Python NL2SQL 服务
fixture: null
quarantined: false
---

# 02 — 单轮对话生成一个 widget

验证编辑页单轮对话经完整链路（前端 → PHP 代理 → Python NL2SQL → SSE 回流）产出带真实数据的 widget。

## 前置条件
- 已登录 admin（`lib/auth.mjs` → `loginAdmin`）
- 在空白仪表盘编辑页 `/admin/Chat2VizDashboard/edit?id=<new>`
- Python NL2SQL 服务在线（`CHAT2VIZ_API_KEY` 有效）

## 步骤
1. `lib/sse.mjs` → `installSseInterceptor(page)` 装帧序拦截
2. 在 `SELECTORS.chatTextarea` 输入："统计每个地区的订单数"
3. 点击 `SELECTORS.chatSendBtn`
4. `lib/sse.mjs` → `waitForFrame(page, SSE_EVENTS.DASHBOARD_REPLACE)`

## 预期
- 前端 SSE 帧序（经 `src/Sse/Nl2sqlEventTransformer.php` 转换，与 Python 原始事件不同）：`conversation_id → answer* → DASHBOARD_REPLACE → done`
- `assertFrameOrder(page, TYPICAL_FRAME_ORDER)` 通过
- `DASHBOARD_REPLACE` 整树恰好包含 1 个 widget
- 该 widget 的 `data` 非 null（真实 `execute_sql` 结果，遵守 `g2_spec.data` 不变量）
- DOM 出现 `SELECTORS.widgetCard`（含 `SELECTORS.widgetCanvas`）

## 反例
- 不应出现 `WIDGET_DATA_UPDATE` / `DASHBOARD_INIT`（Phase B 已废，被 `DASHBOARD_REPLACE` 取代）
- widget 的 `data` 不应为 null（本例非 `answer===''` 瘦态）
- 帧序中 `done` 不应早于 `DASHBOARD_REPLACE`

## 来源
- `.claude/workflows/test/02-chat-single-turn.mjs`（SSE 拦截 + widget 计数 + 选择器）

## artifacts
- 截图：`artifacts/02-chat-single-turn/screenshot.png`
- DOM 快照：`artifacts/02-chat-single-turn/dom.html`
- 视频（needs-llm:true）：`artifacts/02-chat-single-turn/video.webm`
