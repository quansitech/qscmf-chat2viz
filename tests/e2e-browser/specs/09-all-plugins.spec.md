---
id: 09-all-plugins
priority: P0
source-change: full-coverage-validation
needs-llm: true           # 需真实 Python NL2SQL 服务（live-service 模式）
fixture: null
quarantined: false
---

# 09 — 全插件 + 全 G2 子类型 + 全 Slicer 控件综合验证

> 通过**多轮对话**在空白仪表盘上生成完整看板，覆盖：
> - 5 种 `plugin_type`（g2_chart / stat_card / data_table / map / markdown）
> - 9 种 g2_chart 子类型（interval / line / area / point / cell / pie / table）
> - 4 种 slicer 控件（Select / Input / InputNumber / DatePicker）
> - widget 间 click→drill 联动
> - conversation_id 跨轮一致

## 前置条件
- 已登录 admin（uid=admin, pwd=Qs123!@#）
- 空白仪表盘编辑页 `/admin/Chat2VizDashboard/edit?id=0`
- Python NL2SQL 服务在线（`localhost:7860`，DB 已连）
- LLM proxy `https://api.minimaxi.com/anthropic` 可达

## 步骤

### Round 1 — KPI 卡 + 占比 + 明细
在 `SELECTORS.chatTextarea` 输入：
> 做一个 Sakila 影碟店的运营仪表盘。顶部加 4 个 KPI 卡：累计营收、订单总数、客户总数、影碟库存数。再加一个各电影类别收入占比的饼图，以及底部一个最近 50 笔订单的多维表格，列包含 rental_id、customer、staff、金额、日期。

### Round 2 — 趋势 + 对比
> 再加三个图表：月度营收折线图（看 12 个月趋势）、各类别营收堆叠面积图、按月份的电影出租量散点图。

### Round 3 — 地图 + 热力 + 摘要
> 再加一个按国家/地区统计客户数的地图、一个按 store_id vs staff_id 出租量的热力图，最后用 markdown 写一段运营摘要放底部。

### Round 4 — 筛选器 + 联动
> 顶部加 4 个筛选器：按国家下拉选择（联动所有 widget）、按门店 ID 输入数字、付款方式输入框（字符串）、订单日期范围选择器。再给饼图加一个点击 → 钻取到明细表的联动。

## 预期
- 4 轮后 widget 总数 ≥ 15
- plugin_type 集合 = {`g2_chart`, `stat_card`, `data_table`, `map`, `markdown`}（全 5 种）
- 4 个 stat_card KPI 全部显示数字
- g2_chart 覆盖至少 7 个子类型（interval/line/area/pie/point/cell/table）
- map widget 存在
- data_table 含 ≥ 5 列
- markdown widget 渲染纯文本（非 canvas/table/statistic）
- 4 个 slicer 控件分别类型不同
- conversation_id 跨轮一致

## 反例
- 不应出现 `DASHBOARD_INIT` / `WIDGET_DATA_UPDATE`（Phase B 已废）
- 第 2 轮不应清空第 1 轮 widget
- 各轮 `conversation_id` 帧值应一致

## artifacts
- 截图：`artifacts/09-all-plugins/final.png`（终态全屏）
- 控制台：`artifacts/09-all-plugins/run.log`（widget 计数 + 断言结果）
