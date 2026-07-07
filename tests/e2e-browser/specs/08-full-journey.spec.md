---
id: 08-full-journey
priority: P2               # 端到端综合，依赖前序 01-04
source-change: test-engineering-revamp
needs-llm: true
fixture: null
quarantined: false
---

# 08 — 完整旅程（端到端）

从列表创建到发布查看的 11 步端到端旅程，串起 01-04 的能力。

## 前置条件
- 已登录 admin
- NL2SQL 服务在线

## 步骤
1. **createFromList**：从列表页创建仪表盘
2. **openEditor**：进入编辑页
3. **chatTurn1**：生成第 1 个 widget
4. **chatTurn2**：生成第 2 个
5. **chatTurn3**：生成第 3 个
6. **editTitle**：改标题，触发自动保存
7. **dragWidget**：拖拽调整布局
8. **deleteWidget**：删 1 个（剩 2 个）
9. **undo**：撤销删除（恢复 >= 3 个）
10. **publish**：发布
11. **verifyPublishedView**：公开 view 渲染所有 widget

## 预期
- 每步 widget 计数随步推进（3 → 3 → 3 → 2 → 3）
- undo 后恢复 >= 3 widget
- 公开 view 渲染与编辑态一致

## 来源
- `.claude/workflows/test/07-full-journey.mjs`（step1-step11）

## artifacts
- 每步截图：`artifacts/08-full-journey/step{01..11}.png` + 全程视频
