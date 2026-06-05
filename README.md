# qscmf-chat2viz

QSCMF 自然语言数据可视化集成包：**自然语言 → SQL → G2 图表**。

> **版本匹配**：本分支（`v13`）仅兼容 QSCMF v13。
> - QSCMF v14 请使用 `quansitech/qscmf-chat2viz:^2.0`
> - QSCMF v15+ 请使用 `quansitech/qscmf-chat2viz:^3.0`

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

数据文件位于 `src/Sakila/data/`，含中文表/字段 COMMENT，便于 NL2SQL 模型识别业务字段。

## 常见问题

**Q: 必须用 Sakila 吗？**
不必须，是开发/测试夹具，生产环境对接你自己的业务库。

**Q: Python 服务没启动会怎样？**
`api_ask` 返回 `分析服务不可用`，前端展示对应错误；不阻塞页面渲染。

**Q: 为什么用 G2 而不是 ECharts？**
G2 是 AntV 声明式图表库，与 Python 端 `g2_spec` 规格化输出天然契合；ECharts 是命令式 option。
