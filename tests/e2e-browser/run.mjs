#!/usr/bin/env node
// tests/e2e-browser/run.mjs
// AI 优先 E2E runner harness（spec 发现 + 调度决策 + artifacts 准备 + 执行计划输出）。
//
// 架构诚实：Playwright MCP（mcp__playwright__*）是 Claude 的工具，独立 Node 脚本不能
// 直接调用。本脚本是 harness —— 它读 spec front-matter、决定调度模式（真实服务 vs
// fixture 注入）、准备 artifacts 目录、输出人类 + Claude 双读的执行计划。
// 真正的浏览器自动化由 Claude 经 Playwright MCP 执行（读本脚本的输出 + spec body）。
// 这避免引入 playwright npm 包（~300MB），与 frontend 现有 vitest/jsdom 生态解耦。
//
// 用法：
//   node run.mjs                       # 列出所有 spec + 调度模式
//   node run.mjs <spec-id>             # 打印单个 spec 的执行计划（供 Claude 执行）
//   node run.mjs <spec-id> --prepare   # 额外创建 artifacts/<id>/ 目录
//   node run.mjs --all                 # 打印全部 spec 计划（夜跑批量）
import { readFileSync, readdirSync, mkdirSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SPECS_DIR = join(__dirname, 'specs');
const ARTIFACTS_DIR = join(__dirname, 'artifacts');

function readSpec(file) {
  const text = readFileSync(join(SPECS_DIR, file), 'utf8');
  const m = text.match(/^---\n([\s\S]*?)\n---\n([\s\S]*)$/);
  if (!m) return { fm: {}, body: text };
  const fm = {};
  for (const line of m[1].split('\n')) {
    const kv = line.match(/^(\w[\w-]*):\s*(.*)$/);
    if (kv) fm[kv[1]] = kv[2].replace(/\s+#.*$/, '').trim();
  }
  return { fm, body: m[2] };
}

function listSpecs() {
  return readdirSync(SPECS_DIR)
    .filter((f) => f.endsWith('.spec.md'))
    .sort()
    .map((f) => ({ file: f, ...readSpec(f) }));
}

function mode({ fm }) {
  const needsLlm = fm['needs-llm'] === 'true';
  const fixture = fm.fixture && fm.fixture !== 'null' ? fm.fixture : null;
  if (needsLlm) return { label: 'live-service', hint: '连真实 Python NL2SQL（需 CHAT2VIZ_API_KEY + 服务在线）' };
  if (fixture) return { label: 'fixture-inject', hint: `访问页面附加 ?__dsl_fixture=${fixture}` };
  return { label: 'api-ui', hint: '纯 API/UI（无 NL2SQL、无 fixture 注入）' };
}

function renderPlan(spec) {
  const m = mode(spec);
  const q = spec.fm.quarantined === 'true' ? ' ⚠️ QUARANTINED（flaky 隔离中）' : '';
  return [
    `# 执行计划: ${spec.fm.id}${q}`,
    `priority: ${spec.fm.priority} | needs-llm: ${spec.fm['needs-llm']} | mode: ${m.label}`,
    ``,
    `## 调度决策`,
    `- ${m.hint}`,
    `- 登录：lib/auth.mjs → loginAdmin（uid/pwd，运行时读 local-dev-credentials）`,
    `- SSE 拦截：lib/sse.mjs → installSseInterceptor（needs-llm 时断言前端帧序）`,
    ``,
    `## spec 正文（Claude 经 Playwright MCP 据此执行）`,
    spec.body.trim(),
    ``,
    `## artifacts → ${relative(__dirname, ARTIFACTS_DIR)}/${spec.fm.id}/`,
  ].join('\n');
}

const args = process.argv.slice(2);
const idArg = args.find((a) => !a.startsWith('--'));
const prepare = args.includes('--prepare');
const all = args.includes('--all');
const specs = listSpecs();

if (all) {
  for (const s of specs) {
    if (prepare) mkdirSync(join(ARTIFACTS_DIR, s.fm.id), { recursive: true });
    console.log(renderPlan(s));
    console.log('\n' + '-'.repeat(72) + '\n');
  }
  process.exit(0);
}

if (!idArg) {
  console.log('Chat2Viz E2E specs（tests/e2e-browser/specs/）:\n');
  for (const s of specs) {
    const m = mode(s);
    const q = s.fm.quarantined === 'true' ? ' ⚠️QUARANTINED' : '';
    console.log(
      `  ${s.fm.id.padEnd(22)} [${s.fm.priority}] ${m.label.padEnd(14)} ${q}`,
    );
  }
  console.log('\n用法: node run.mjs <spec-id> [--prepare] | --all');
  process.exit(0);
}

const spec = specs.find((s) => s.fm.id === idArg || s.file.startsWith(idArg));
if (!spec) {
  console.error(`spec "${idArg}" 未找到。可用: ${specs.map((s) => s.fm.id).join(', ')}`);
  process.exit(1);
}

if (prepare) {
  const dir = join(ARTIFACTS_DIR, spec.fm.id);
  mkdirSync(dir, { recursive: true });
  console.error(`✓ artifacts 目录已准备: ${relative(__dirname, dir)}/`);
}
console.log(renderPlan(spec));
