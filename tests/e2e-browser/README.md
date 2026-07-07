# Browser E2E 测试资产（`tests/e2e-browser/`）

> **总指南（测试金字塔 / spec 模板 / 保留废弃清单 / CI 策略）**：
> 跨仓主仓文档中心 `docs/plans/test-engineering-revamp.md`（相对主仓根）
>
> **行为契约来源**：`openspec/changes/test-engineering-revamp/`（本仓）
> **协调**：dsl-v3 fixtures / 契约测试由 `react-dsl-v3-renderer` 所有，本目录**引用不复制**。

---

## 这是什么

Chat2Viz 浏览器 E2E 测试的**唯一资产家**（取代历史上散落在 `.claude/workflows/e2e/`、`.claude/workflows/test/`、`tests/e2e/agent-browser/`、`scripts/` 四处的资产）。两层运行时：

- **确定性层**（`deterministic/`）：`@playwright/test`，fixture 对拍，无 LLM，CI 阻塞
- **AI 层**（`specs/` + `run.mjs`）：Playwright MCP 读 spec 驱动浏览器，夜跑/按需

## 目录结构

```
tests/e2e-browser/
├── README.md                 ← 本文件
├── specs/                    ← 双 reader spec（人类 + AI），见总指南 §3 模板
├── fixtures/dsl-v3/          ← pointer 到 frontend/fixtures/dsl-v3/（SSoT，不复制）
├── lib/                      ← auth.ts / sse.ts / selectors.ts（共享步骤）
├── deterministic/            ← @playwright/test 确定性契约层
├── playwright.config.ts      ← 确定性层配置
├── run.mjs                   ← AI 优先 runner（读 spec → Playwright MCP）
└── artifacts/                ← 截图/录屏/DOM/console/network（.gitignore，勿提交）
```

## 快速开始

### 环境
- `BASE_URL`：**必填**（env 注入，不硬编码）。例：`export BASE_URL=http://your-test-host`
- 登录凭据：`lib/auth.ts` 运行时从 `local-dev-credentials` memory 文件读取（**勿提交凭据**）
- AI 层需 `CHAT2VIZ_API_KEY`（连真实 Python 服务）；确定性层**不需要**

### 跑确定性层（CI-safe，无 LLM）
```bash
cd frontend && npm run test:e2e-deterministic    # 等价 npx playwright test
```

### 跑 AI 层（Playwright MCP，按需）
```bash
node tests/e2e-browser/run.mjs 01-smoke                   # 单个 spec
node tests/e2e-browser/run.mjs --all                      # 全部
node tests/e2e-browser/run.mjs 05-dsl-v3-render           # fixture 驱动（needs-llm:false）
```
artifacts 落 `artifacts/<spec-id>/`（截图 + DOM + 视频 + 失败时 console/network）。

## 写一个新 spec

1. 复制 `specs/02-chat-single-turn.spec.md` 为 `specs/NN-<flow>.spec.md`
2. 改 front-matter（`id` / `priority` / `needs-llm` / `fixture`）
3. 按【前置条件 / 步骤 / 预期 / 反例 / artifacts】写正文（模板见总指南 §3）
4. 步骤**只引用 `lib/`**（`data-testid` 优先，CSS fallback），不内联选择器
5. 跑 `node run.mjs NN-<flow>` 验证

**spec 格式铁律**：
- 选择器优先 `data-testid`，`lib/selectors.ts` 统一维护 fallback
- `needs-llm: false` 必须配 `fixture: <name>`（走 `?__dsl_fixture=<name>`）
- `needs-llm: true` 步骤里断言 SSE 帧序（`lib/sse.ts`）

## 加 fixture

dsl-v3 fixture 的**唯一来源**是 `frontend/fixtures/dsl-v3/`（react-dsl-v3-renderer 所有）。本目录 `fixtures/dsl-v3/` 仅是 pointer（`FIXTURES_DIR` 常量解析到上游路径）。

- 新 fixture → 在 `frontend/fixtures/dsl-v3/` 建（遵循 react-dsl-v3-renderer 的 `__schema__.test.ts` 校验）
- 引用 → spec front-matter `fixture: <name>`（不带 `.replace.json`）
- 上游改名 → `deterministic/fixtures-reference.contract.spec.ts` 红灯（漂移检测）

## CI 策略

| 层 | 触发 | 阻塞 | API key |
|---|---|---|---|
| `deterministic/` | 每次 PR | 是 | 否 |
| `specs/` + `run.mjs` | 夜跑 + 按需 | 否（报告） | 是 |

AI 层失败不阻塞合并（LLM flakiness 隔离，借鉴 qs-chat2viz `provider_flaky`）。flaky spec（≥2/10 失败）置 front-matter `quarantined: true` + issue 链接，文件保留待修。

## 故障排查

| 症状 | 排查 |
|---|---|
| `BASE_URL` 连不上 | 确认 `BASE_URL` 已 export 且目标主机可达 |
| 登录失败 | `lib/auth.ts` 读的 `local-dev-credentials` 是否有效 |
| SSE 帧序断言失败 | `lib/sse.ts` 的帧序是否与 `src/Sse/Nl2sqlEventTransformer.php` 当前输出一致 |
| 选择器失效 | 先查 `lib/selectors.ts`，确认前端是否加了 `data-testid` |
| fixture 找不到 | `frontend/fixtures/dsl-v3/<name>.replace.json` 是否存在 |
| Playwright MCP 不可用 | AI 层是夜跑/按需；CI 走 `deterministic/`（标准 `@playwright/test`） |
