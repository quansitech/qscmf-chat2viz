---
id: 01-smoke
priority: P0
source-change: test-engineering-revamp
needs-llm: false          # 纯 Dashboard CRUD API，不调 NL2SQL
fixture: null
quarantined: false
---

# 01 — Dashboard CRUD 烟雾测试

验证 Dashboard 管理的增删改查 API 闭环（数据层，不涉及 NL2SQL 渲染）。

## 前置条件
- 已登录 admin（`lib/auth.mjs` → `loginAdmin`）
- 本地 PHP 后台可达（`DEFAULT_BASE_URL`）

## 步骤
1. **创建**：`fetch POST /admin/Chat2VizDashboard/create`，body `{title: "smoke 测试"}`，带登录 cookie
2. **读取**：`fetch GET /admin/Chat2VizDashboard/read?uid=<新 uid>`
3. **更新标题**：`fetch POST .../update`，body `{uid, title: "更新后标题"}`
4. **更新 schema**：`fetch POST .../update`，body 含 schema 字段
5. **列表**：`fetch GET .../index`，含 `status=draft` 过滤
6. **归档**：`fetch POST .../archive`，再 read 确认状态
7. **反例-非法 uid**：`read?uid=nonexistent`
8. **反例-缺 uid**：`read` 无 uid 参数
9. **反例-HTTP 方法**：`GET /create`、`POST /read`

## 预期
- create：`status===1`、`data.uid` 存在、`data.status==="draft"`
- read：`status===1`、`data.uid` 与请求一致
- update title：`status===1`、`data.title` 含"更新后"
- update schema：`status===1`
- list：`status===1`、`data.total>=1`、`data.items` 为数组、draft filter 生效
- archive：`status===1`，归档后 read 标记 archived 或 not found

## 反例
- 非法 uid：`status===0`、`info` 含"无效"
- 缺 uid：`status===0`、`info` 含"缺少"
- GET create：`status===0`（方法校验）
- POST read：`status===0`

## 来源
- `.claude/workflows/test/01-smoke-crud.mjs`（testCreateDashboard/testReadDashboard/testUpdateDashboard/testListDashboards/testArchiveDashboard/testInvalidUid/testMissingUid/testHttpMethodValidation，断言逐条对应）

## artifacts
- 截图：`artifacts/01-smoke/screenshot.png`
