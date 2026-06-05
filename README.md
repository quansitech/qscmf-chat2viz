# qscmf-chat2viz

QSCMF 自然语言数据可视化集成包：**自然语言 → SQL → G2 图表**。

> **版本匹配**：本分支（`v14`）仅兼容 QSCMF v14。
> - QSCMF v13 请使用 `quansitech/qscmf-chat2viz:^1.0`
> - QSCMF v15+ 请使用 `quansitech/qscmf-chat2viz:^3.0`

## 它做什么

- 后台菜单 `数据可视化 → 智能分析` 提供自然语言问答入口（v14 需手动配置菜单）
- PHP 端把问题代理到 Python `/api/v1/ask` 和 `/api/v1/ask/stream`，携带 `X-API-Key` 鉴权
- SSE 流式端点通过 `quansitech/qscmf-sse-core` 包透传上游事件，前端渐进式渲染
- 前端接收 G2 图表规格 + SQL + 自然语言回答，用 G2 渲染（Inertia + React）
- 内置 Sakila 16 表测试夹具（含中文 COMMENT），用于本地开发与回归测试

## 安装

```bash
composer require quansitech/qscmf-chat2viz:^2.0
composer dump-autoload
npm run build:backend
```

## 环境变量

| 变量 | 必填 | 说明 |
|------|------|------|
| `CHAT2VIZ_SERVICE_URL` | 是 | Python 服务 base URL（不含尾部 `/`） |
| `CHAT2VIZ_API_KEY` | 否 | 与 Python 服务端一致，作为 `X-API-Key` 头部传递 |

## SSE 流式端点

`POST /extends/Chat2Viz/api_ask_stream`

SSE 流式端点通过 `quansitech/qscmf-sse-core` 包实现透明代理：后端收到请求后，以 `STREAM=true` 模式连接 Python `/api/v1/ask/stream`，逐事件转发到浏览器。

请求格式同 `api_ask`（JSON body `{"question": "..."}`），响应为 `text/event-stream`：

```
event: answer
data: {"delta": "SELECT"}

event: sql
data: {"sql": "SELECT ..."}

event: tool
data: {"name": "search_objects"}

event: g2_spec
data: {"type": "line", ...}

event: done
data: {}
```

错误事件格式：`event: error\ndata: {"type": "upstream_disconnected", "info": "分析服务连接中断"}`

## 前端构建

本包的前端源码位于 `asset/inertia/Chat2viz/`：

- `Index.tsx` — 主界面（Inertia + React + AntD + G2）
- `sse-parser.ts` — SSE 解析模块（纯 TS，无 React 依赖）
- `G2Renderer.tsx` — G2 图表渲染组件

**构建产物路径契约**：源码在 `asset/inertia/Chat2viz/`，构建产物由宿主项目的 `npm run build` 产出到 `resources/js/backend/Pages/Chat2viz/...`。本包的 `Chat2VizServiceProvider` 通过软链 `WWW_DIR/Public/inertia-chat2viz → asset/inertia/Chat2viz` 暴露前端资源，宿主项目的 Vite/webpack 配置负责将 TS 源码编译到 Inertia 页面入口。

SSE 解析模块导出：

- `parseSseBlock(raw: string): SseEvent | null` — 解析单个 SSE 块
- `createSseProcessor(onEvent)` — 创建流式处理器（缓冲 + 分块）

## Laravel 集成

Laravel Artisan 命令（`chat2viz:seed-sakila` / `chat2viz:unseed-sakila`）通过 `composer.json` 的 `extra.laravel.providers` 自动注册。如宿主项目未启用自动发现，需在 `config/app.php` 的 `providers` 数组中手动添加 `Qscmf\Chat2Viz\Chat2VizServiceProvider`。

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

## 开发与测试

```bash
php artisan chat2viz:seed-sakila              # 灌入 Sakila 16 表（默认前缀 qs_）
php artisan chat2viz:seed-sakila --prefix=t_  # 自定义前缀
php artisan chat2viz:unseed-sakila            # 卸载
```

数据文件位于 `src/Sakila/data/`，含中文表/字段 COMMENT，便于 NL2SQL 模型识别业务字段。

PHPUnit 测试：

```bash
vendor/bin/phpunit
```
