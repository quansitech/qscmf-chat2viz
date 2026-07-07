# qscmf-chat2viz

QSCMF 自然语言数据可视化集成包：**自然语言 → SQL → G2 图表**。

> **版本兼容**：单一代码库同时服务 QSCMF v13 / v14 / v15。
> - `tiderjian/think-core: >=13.0.0`（无上界）；ORM 走 `AdapterFactory` 运行时挑实现（v13 Think\Model / v14+ Eloquent），渲染走 Smarty（v13）/ Inertia（v14+）。
> - 表名前缀由 `Qscmf\Chat2Viz\Support\Table` 统一解析：`Table::name()` 喂 Schema 构建器/Eloquent（v13 返 `qs_xxx`、v15 返裸名由框架补 `qs_`），`Table::physicalName()` 喂裸 SQL（`DB::statement`/`unprepared` 不自动补前缀，故需完整物理名）。

## 它做什么

- 后台菜单 `数据可视化 → 智能分析` 提供自然语言问答入口
- PHP 端把问题代理到 Python `/api/v1/ask`，携带 `X-API-Key` 鉴权
- 前端接收 G2 图表规格 + SQL + 自然语言回答，用 G2（CDN）渲染
- 内置 Sakila 16 表测试夹具（含中文 COMMENT），用于本地开发与回归测试

## 安装

```bash
composer require quansitech/qscmf-chat2viz:^1.0
```

## 环境变量

| 变量 | 必填 | 说明 |
|------|------|------|
| `CHAT2VIZ_SERVICE_URL` | 是 | Python 服务 base URL（不含尾部 `/`） |
| `CHAT2VIZ_API_KEY` | 是 | 与 Python 服务端 `CHAT2VIZ_API_KEY` 一致，作为 `X-API-Key` 头部传递 |

## 协议

请求：`POST /extends/Chat2Viz/api_ask`

```json
{
  "question": "上月各门店销售额前 5 名",
  "conversation_id": "可选，多轮上下文 ID"
}
```

成功响应：

```json
{
  "status": 1,
  "data": {
    "answer": "自然语言回答",
    "sql": "SELECT ...",
    "g2_spec": { "图表规格，喂给 G2 渲染" },
    "conversation_id": "uuid"
  }
}
```

字段约束：

- `question`：必填，1–1000 字符
- `conversation_id`：可选，匹配 `^[a-f0-9\-]{1,64}$`
- 错误时返回 `{status: 0, info: "..."}`

## 公开视图（Public Dashboard View）

发布后的仪表盘可通过公开路由匿名访问：

```
GET /extends/Chat2VizDashboard/view/uid/{uid}
```

**安全边界**：

- 仅 `dashboard_status = 'published'` 的仪表盘可被匿名访问。草稿（`draft`）和归档（`archived`）状态返回与"UID 不存在"完全一致的错误页，避免状态枚举。
- 公开视图渲染前会剥离 schema 中每个 widget 的 `sql` 字段（仅暴露图表渲染所需的 `g2_spec` / 标题 / 布局）。SQL 字符串属于"查询意图"，不应进浏览器 View Source。`g2_spec` 是图表规格（不是 SQL），保留。
- 图表数据通过公开端点 `GET /extends/Chat2VizDashboard/api_widget_data/uid/{uid}/widgetId/{widgetId}` 获取，服务端依据持久化的 SQL 执行查询，前端 schema 不需要 SQL 即可渲染。
- `api_widget_data` 受 SqlValidator（SELECT-only + UNION 禁止 + 危险函数黑名单）和 IP 速率限制（APCu 可用 60 次/分钟，不可用 30 次/分钟）双重防护。
- admin 模块（`/admin/Chat2VizDashboard/*`）与公开模块（`/extends/Chat2VizDashboard/*`）独立鉴权：公开路由不携带 admin session；内部 API（`api_read` / `api_update` 等）强制 `created_by === currentUserId` 所有权校验。

## 工作原理

```
QSCMF 后台 → Chat2Viz Controller → Python NL2SQL 服务 → 返回 G2 规格 → 前端渲染
```

- PHP 包只做 HTTP 代理、字段校验、`X-API-Key` 鉴权传递
- NL2SQL、SQL 安全校验、`g2_spec` 生成都在 Python 服务端（LangGraph 编排）
- 前端用 G2 声明式规格直接渲染，无需 ECharts option 适配

## 开发与测试

```bash
php artisan chat2viz:seed-sakila              # 灌入 Sakila 16 表（默认前缀 qs_）
php artisan chat2viz:seed-sakila --prefix=t_  # 自定义前缀
php artisan chat2viz:unseed-sakila            # 卸载
```

- 跨库（mysql/pg）：16 表用 Laravel Schema 构建器（可移植类型 + 中文 COMMENT 内联）；7 视图 / 3 触发器 / 6 存储过程按驱动分支建（`SakilaProceduralObjects`）；数据由 `SakilaDataLoader` 清洗官方 `sakila-data.sql` 灌入（pg 自动改写 BLOB hex 为 bytea）。
- 表名前缀跨版本自适应（`Table::physicalName()`），无需关心当前库是 mysql 还是 pg、前缀是 `qs_` 还是空。
- 唯一例外：`address.location` GEOMETRY 列省略（pg 需 PostGIS，demo 不用）。

## 常见问题

**Q: 必须用 Sakila 吗？**
不必须，是开发/测试夹具，生产环境对接你自己的业务库。

**Q: Python 服务没启动会怎样？**
`api_ask` 返回 `分析服务不可用`，前端展示对应错误；不阻塞页面渲染。

**Q: 为什么用 G2 而不是 ECharts？**
G2 是 AntV 声明式图表库，与 Python 端 `g2_spec` 规格化输出天然契合；ECharts 是命令式 option。
