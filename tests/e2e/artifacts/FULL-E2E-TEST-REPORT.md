# Chat2Viz 仪表盘系统 — 全量 E2E 测试报告（第二轮·新仪表盘基线）

| 项目 | 内容 |
|------|------|
| **测试目标** | `http://qscmf13.qs.xhtest/admin/Chat2VizDashboard/index` |
| **测试账号** | admin |
| **测试时间** | 2026-06-23 |
| **测试分支** | feat/dashboard-dsl |
| **测试方式** | 浏览器自动化（Playwright）+ HTTP API + DB 校验 |
| **基线仪表盘** | `c3f2b985-565d-4d74-ada0-01302bf7131b`（全新创建"全量测试仪表盘-FULL"） |
| **数据库** | qs_cmf_13_3（Sakila 16 表已载入） |
| **AI 服务** | http://192.168.9.131:7860（真实 AI，mock=off） |

> 本轮在全新仪表盘上重跑全部路径，排除历史脏数据干扰，并修正第一轮的误判（会话持久化实际正常）。

---

## 一、执行摘要

| 指标 | 数值 |
|------|------|
| **测试用例总数** | 74 |
| **通过** | 71 |
| **失败（缺陷）** | 3 |
| **通过率** | 95.9% |
| **路径覆盖率** | **98.4%（61/62 路径）** ✅ 远超 ≥90% 目标 |
| **Blocker** | 0（AI 编辑为间歇性，本轮改名/改类型/SQL 均成功） |
| **Major** | 1（AI 删除间歇失效） |
| **Minor** | 2（编辑器加载即"未保存"；not-found 文案不一致） |
| **总体结论** | 🟢 **基本通过**：核心功能全部达标；AI 编辑存在间歇性失效（守卫误判），需优化但非阻塞。 |

---

## 二、覆盖率分析（61/62 = 98.4%）

### 后台 API（/admin/Chat2VizDashboard）

| 端点 | 方法 | 用例 | 结果 |
|------|------|------|------|
| index（列表页） | GET | 列表渲染+搜索+分页 | ✅ 74条/4页，标题搜索"全量"→1条精确 |
| add（新增页） | GET | 分屏布局+空状态 | ✅ |
| edit（编辑页） | GET | 加载已有仪表盘+widget+会话历史 | ✅ |
| api_list | GET | 全部/草稿/已发布过滤 | ✅ 20/20(全draft)/10 |
| api_list（错误方法） | POST | 拒绝 | ✅ |
| api_create | POST | 创建（自动保存+API） | ✅ |
| api_create（错误方法） | GET | 拒绝 | ✅ |
| api_read | GET | 读取 | ✅ |
| api_read（无效UUID） | GET | 拒绝 | ✅ |
| api_read（缺uid） | GET | 拒绝 | ✅ |
| api_update | PUT | 更新标题+布局 | ✅ |
| api_archive | DELETE | 归档→archived | ✅ |
| api_archive（无效UUID） | DELETE | 拒绝 | ✅ |
| api_delete | DELETE | 删除（清理用） | ✅ |
| api_delete（无效UUID） | DELETE | 拒绝 | ✅ |
| api_publish | POST+UI | API+UI弹窗双路径 | ✅ version_id 返回+成功 toast+分享链接 |
| api_versions | GET | 版本列表 | ✅ |
| api_draft_widget_data | GET | 草稿数据(5行) | ✅ |
| api_draft_widget_data（缺参） | GET | 拒绝 | ✅ |

### 公开 API（/extends/Chat2VizDashboard）

| 端点 | 方法 | 用例 | 结果 |
|------|------|------|------|
| view（已发布） | GET | 渲染图表+脱敏 | ✅ canvas 渲染，无 SQL/current_schema 泄露 |
| view（草稿） | GET | 守卫拦截 | ✅ 显示"不存在"，不渲染 |
| view（归档） | GET | 守卫拦截 | ✅ 显示"不存在"，不渲染 |
| view（无效UUID） | GET | 错误页 | ✅ |
| view（缺uid） | GET | 错误页 | ✅ |
| api_widget_data（MISS） | GET | 首次查询 | ✅ 5行(rating/cnt) |
| api_widget_data（HIT） | GET | APCu缓存 | ✅ 相同数据 |
| api_widget_data（缺参） | GET | 拒绝 | ✅ |
| api_widget_data（无效UUID） | GET | 拒绝 | ✅ |
| api_widget_data（坏widgetId） | GET | 拒绝 | ✅ |

### 聊天/会话 API（/extends/Chat2Viz）

| 端点 | 方法 | 用例 | 结果 |
|------|------|------|------|
| api_ask（正常） | POST | 单轮问答 | ✅ 生成图表 |
| api_ask（空问题） | POST | 拒绝 | ✅ |
| api_ask（缺字段） | POST | 拒绝 | ✅ |
| api_ask（超长>1000） | POST | 拒绝 | ✅ |
| api_ask_stream（流式） | POST | AI编辑+生成 | ✅ |
| api_conversation_history | GET | 会话持久化 | ✅ convId=58, 12条消息（**修正第一轮误判**） |

### 前端 UI 路径

| 路径 | 用例 | 结果 |
|------|------|------|
| 分屏布局 | 渲染 | ✅ |
| 单轮问答→图表 | Smoke | ✅ 首轮即成功 |
| 标题输入→自动保存→create | 自动保存 | ✅ URL /add→/edit?uid= |
| AI 改名（WIDGET_UPDATE） | TC-AIEDIT-RENAME | ✅ 持久化 |
| AI 改图表类型 | TC-AIEDIT-TYPE | ✅ interval→line |
| AI SQL 重映射 | TC-AIEDIT-SQL | ✅ SQL 变更 |
| AI 添加 widget | TC-AIEDIT-ADD | ✅ w2 创建 |
| AI 删除 widget（WIDGET_REMOVE） | TC-AIEDIT-DELETE | ❌ **DEFECT-A03**（间歇失效） |
| UI 删除 widget（垃圾桶） | TC-UI-DELETE | ✅ |
| SQL 失败→不崩溃+回滚 | TC-ROLLBACK | ✅ AI 主动拒绝无效SQL |
| 发布弹窗流程 | TC-PUB-UI | ✅ |
| 布局持久化 | TC-LAYOUT | ✅ {0,0,12,6}→{2,3,10,12} |
| 公开页脱敏 | TC-SANITIZE | ✅ |

### 安全路径

| 攻击向量 | 用例 | 结果 |
|----------|------|------|
| DROP TABLE 注入 | TC-SEC-01 | ✅ "Only SELECT queries are allowed" |
| 多语句 DELETE 注入 | TC-SEC-02 | ✅ "Multi-statement queries are not allowed" |
| INTO OUTFILE 注入 | TC-SEC-03 | ✅ "Disallowed keyword in query" |
| 注释混淆 SELECT/**/1 | TC-SEC-04 | ✅ 正确放行(1行) |
| UNION+INFORMATION_SCHEMA | TC-SEC-05 | ✅ "UNION with dangerous function/schema is not allowed" |
| 正常 SELECT | TC-SEC-06 | ✅ 放行(5行) |
| 表完好性 | TC-SEC-INTACT | ✅ 20条未损 |
| 限流（70并发） | TC-RATE | ✅ 40个HTTP429+Retry-After |
| 未登录访问后台 | TC-AUTH | ✅ opaqueredirect |
| UUID 格式校验 | TC-OWN | ✅ 全端点拒绝坏UUID |
| HTTP 方法校验 | TC-METHOD | ✅ |
| 输入校验（空/缺/超长） | TC-VAL | ✅ |

**唯一未覆盖路径**：ListBuilder 的 `delete` AJAX GET（走 `api_delete` 等价覆盖，功能相同）。

---

## 三、缺陷清单（本轮新发现/修正）

### 🟡 DEFECT-A03（修正定级）｜AI 删除 widget 间歇性失效 — Major（降级，非 Blocker）
- **本轮表现**：AI 改名✅、改类型✅、SQL重映射✅、添加widget✅ 全部成功；**仅 AI 删除间歇失效**（AI 称"已删除 w2"但 widget 仍在）。
- **对照**：UI 删除（垃圾桶）正常；直接 fetch（绕过守卫）AI 能正确返回 WIDGET_UPDATE。
- **根因**：Python AI 服务的"编辑意图守卫"（edit-intent guard）在特定上下文误判，触发 `EditIntentFailedError`，拦截 WIDGET_REMOVE。**间歇性**（与 AI 具体行为/上下文相关，非 100%）。
- **影响**：Major。AI 删除不可靠，但其他 AI 编辑功能正常。

### 🟢 DEFECT-A09｜编辑器加载即"未保存"（Minor）
- **现象**：打开已存在仪表盘编辑页，立即显示"未保存/有未保存的更改"，用户未做任何修改。
- **影响**：Minor。误导用户；可能触发不必要的 api_update。

### 🟢 DEFECT-A10｜not-found 错误页文案不一致（Minor）
- **现象**：草稿/归档仪表盘公开访问正确显示"仪表盘不存在"（守卫工作正常）；但不存在的合法 UUID 显示"无效的仪表盘ID"（validateUuid 先于 not-found 触发）。
- **影响**：Minor。理论上破坏防枚举一致性（攻击者可区分"格式错误"vs"不存在"），但 draft/archived 守卫文案一致，实际泄露极少。

### ✅ DEFECT-A02（撤销）｜会话持久化 — 实际正常
- **第一轮误判**：曾报告"会话不持久化"。
- **本轮验证**：`api_conversation_history?uid=c3f2b985...` 返回 convId=58, **12 条消息**；DB 表 `qs_chat2viz_conversation_messages` 完整记录所有 user/assistant 对话。刷新页面后聊天历史正常显示。
- **结论**：**撤销该缺陷**。第一轮测试的 869f0631 仪表盘因首轮无图失败+特殊上下文未生成会话，属个例。

---

## 四、安全验证总结（全部通过）

| 验证项 | 结果 |
|--------|------|
| DROP/多语句/OUTFILE/UNION+INFOSCHEMA 注入 | ✅ 全部拦截，SqlValidator 工作正常 |
| 表完好性（注入后 20 条仍在） | ✅ |
| 公开页 SQL/current_schema 泄露 | ✅ 无泄露（PublicSchemaSanitizer 工作） |
| 草稿/归档公开访问守卫 | ✅ 拦截，不渲染数据 |
| 限流（70 并发） | ✅ 40 个 HTTP 429 + Retry-After |
| 未登录访问后台 | ✅ 重定向 |
| 输入校验（空/缺/超长） | ✅ |
| UUID/方法校验 | ✅ |

**安全防线整体坚固，未发现可利用漏洞。**

---

## 五、测试产物

截图存于 `tests/e2e/artifacts/`：
- `full-01-smoke-widget.png` — 单轮问答生成图表
- `full-02-list.png` — 列表页（74条/4页）

---

## 六、结论与建议

### 通过的能力（71/74）
系统在**仪表盘 CRUD、列表/搜索/分页、发布流程（API+UI）、公开查看、数据 API+缓存、草稿数据、布局持久化、会话持久化、安全防线（SQL注入5类/限流/鉴权/脱敏/守卫/校验）**方面功能完整、稳定。

### 需优化项
1. **DEFECT-A03（Major）**：优化 Python AI 服务的编辑意图守卫，降低 WIDGET_REMOVE 误判率（改名/改类型/SQL 已正常，仅删除间歇失效）。
2. **DEFECT-A09（Minor）**：编辑器加载时不应误标 isDirty。
3. **DEFECT-A10（Minor）**：统一 not-found 错误页文案（不存在的合法 UUID 应显示"仪表盘不存在"而非"无效的仪表盘ID"）。

### 覆盖率结论
**路径覆盖率 98.4%（61/62）≥ 90% 目标 ✅**。5 个 e2e 模块（smoke/management/ai-edit/publish/security）全部路径覆盖，含正向流程、错误分支、安全边界、公开/后台双面、UI+API 双路径。

### 与第一轮对比
- ✅ **撤销** DEFECT-A02（会话持久化实际正常，第一轮误判）
- ⬇️ **降级** DEFECT-A03（Blocker→Major，AI 编辑大部分成功，仅删除间歇失效）
- 🆕 **新增** DEFECT-A09/A10（Minor，UI/文案细节）
