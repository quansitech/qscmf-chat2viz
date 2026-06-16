# Chat2Viz Dashboard — Frontend Tests

Pure-logic unit tests for the interaction-polish changes:

| File | Covers |
|------|--------|
| `aiSteps.test.ts` | 缺陷2 — tool-step `action_type` de-duplication + single-in-progress rule (the "不断追加工具执行过程" fix) |
| `concurrency.test.ts` | 议题3b — bounded-concurrency semaphore (`createSemaphore`) + `runWithConcurrency` order/cap |
| `suggestHeight.test.ts` | 议题4c — content-aware default widget height |
| `statusContract.test.ts` | 缺陷1 + 缺陷2 — `StreamingState`/`HistoryStatus` literal values and the `!== 'idle'` predicates the auto-save edge & UI gates depend on |

## Run

Two runners cover the same pure-logic assertions:

**vitest (preferred; needs the npm registry)** — runs the `.test.ts` specs:
```bash
cd frontend
npm install        # installs vitest + jsdom (devDependencies)
npm test           # vitest run
```

**node built-in (no install needed)** — `node-smoke.test.mjs` transpiles the
real util sources with the already-installed `typescript` devDep and runs them
under `node:test`. Use this when the npm registry is unreachable:
```bash
cd frontend
npm run test:node  # node --test tests/node-smoke.test.mjs
```

Both are green as of this change (12 assertions: tool-step de-dup, semaphore
concurrency cap + value/error semantics, height heuristics).

> The `.test.ts` files typecheck cleanly (`npm run typecheck`) even before
> vitest is installed. The devDependencies (`vitest ^4`, `jsdom ^25`) are
> declared in `package.json`; in environments where the npm registry tarball
> fetch is blocked, use `npm run test:node` instead (it only needs `typescript`,
> which is already present).

## Manual regression checklist (no test runner required)

- [ ] **缺陷1**: Reload the edit page → chat shows "正在加载对话历史..." and the send
      button is disabled until history loads; widgets show skeletons meanwhile.
- [ ] **缺陷2**: Ask a multi-tool question → only ONE in-progress tool step shows
      at a time; repeated same-type calls collapse; steps turn green (completed)
      when `action_call_result` arrives; "思考中..." three-dots show before the
      first frame.
- [ ] **议题3a**: With `CHAT2VIZ_SHOW_SQL` unset → 查询语句 panel hidden in widget
      footer, chat bubbles, and the view page. Set `CHAT2VIZ_SHOW_SQL=true` → it
      appears.
- [ ] **议题3b**: Click one widget's refresh icon → only that widget re-fetches
      (single network request); others are untouched.
- [ ] **议题4a**: Multi-line input grows up to 6 rows then scrolls; Enter sends,
      Shift+Enter adds a newline.
- [ ] **议题4b**: Collapse button hides the chat pane; a floating button restores
      it; preview fills the width (≈ published look). On mobile (≤768px) the
      collapse button is hidden.
- [ ] **议题4c**: A table widget with many rows gets a taller default height; a
      single-value chart is shorter; persisted layouts are not overwritten.
