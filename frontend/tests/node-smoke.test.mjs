// Zero-dependency test entry runnable with plain Node (no vitest required).
//
// The pure utils are authored in TypeScript; this script transpiles just those
// files to a temp dir using the already-installed `typescript` devDep, then
// runs the same assertions as the vitest specs via node's built-in test runner.
// Use when the npm registry is unreachable (vitest can't install):
//   node --test tests/node-smoke.test.mjs   (or `npm run test:node`)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as ts from 'typescript';

const __dirname = dirname(fileURLToPath(import.meta.url));
// __dirname = <pkg>/frontend/tests ; ROOT = <pkg> (where asset/ lives).
const ROOT = join(__dirname, '..', '..');
const SRC = join(ROOT, 'asset', 'inertia', 'Chat2viz', 'utils');

// Transpile a single .ts file to ESM .mjs in a temp dir (no type-check, just
// strip types). The utils have no runtime imports, so no module resolution.
function compile(file) {
  const dir = mkdtempSync(join(tmpdir(), 'c2v-'));
  const srcPath = join(SRC, file);
  const sourceText = readFileSync(srcPath, 'utf8');
  const out = ts.transpileModule(sourceText, {
    compilerOptions: {
      module: ts.ModuleKind.ESNext,
      target: ts.ScriptTarget.ES2020,
    },
  }).outputText;
  const outPath = join(dir, file.replace(/\.ts$/, '.mjs'));
  writeFileSync(outPath, out);
  return outPath;
}

const dirs = [];
const compileTmp = (f) => { const p = compile(f); dirs.push(dirname(p)); return p; };

const ai = compileTmp('aiSteps.ts');
const con = compileTmp('concurrency.ts');
const sug = compileTmp('suggestHeight.ts');

const { planActionCall, findInProgressToolStep } = await import('file://' + ai);
const { runWithConcurrency, createSemaphore } = await import('file://' + con);
const { suggestHeight, MIN_H } = await import('file://' + sug);

const step = (id, label, completed = false) => ({ id, type: 'tool_start', label, timestamp: id, completed });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

test('aiSteps: appends when none in progress', () => {
  assert.equal(planActionCall([], 'x', 'L', () => step('s1', 'L')).kind, 'add');
});
test('aiSteps: refreshes repeat same-type call', () => {
  const d = planActionCall([step('s1', 'L')], 'x', 'L', () => step('s2', 'L'));
  assert.equal(d.kind, 'refresh'); assert.equal(d.id, 's1');
});
test('aiSteps: completes prior tool on different type', () => {
  const ns = step('s2', 'L2');
  const d = planActionCall([step('s1', 'L1')], 'y', 'L2', () => ns);
  assert.equal(d.kind, 'completeAndAdd'); assert.equal(d.completeId, 's1'); assert.equal(d.step, ns);
});
test('aiSteps: single in-progress after repeats + type change', () => {
  let steps = []; let counter = 0;
  const apply = (actionType, label) => {
    const d = planActionCall(steps, actionType, label, () => step(`s${++counter}`, label));
    if (d.kind === 'refresh') { steps = steps.map((s) => s.id === d.id ? { ...s, completed: true } : s); steps.push(step(`s${++counter}`, label)); }
    else if (d.kind === 'completeAndAdd') { if (d.completeId) steps = steps.map((s) => s.id === d.completeId ? { ...s, completed: true } : s); steps.push(d.step); }
    else steps.push(d.step);
  };
  apply('execute_sql', '执行查询...'); apply('execute_sql', '执行查询...'); apply('execute_sql', '执行查询...'); apply('describe_table', '分析表结构...');
  assert.equal(steps.filter((s) => !s.completed).length, 1);
  assert.equal(findInProgressToolStep(steps).label, '分析表结构...');
});
test('concurrency: order preserved', async () => {
  assert.deepEqual(await runWithConcurrency([3,1,2].map((n)=>async()=>{await sleep(n*10);return n;}), 2), [3,1,2]);
});
test('concurrency: runWithConcurrency cap', async () => {
  let active=0,peak=0;
  await runWithConcurrency(Array.from({length:20},()=>async()=>{active++;peak=Math.max(peak,active);await sleep(10);active--;}), 6);
  assert.ok(peak<=6, `peak=${peak}`);
});
test('concurrency: createSemaphore shared cap', async () => {
  const limit=createSemaphore(6);let active=0,peak=0;
  await Promise.all(Array.from({length:15},()=>limit(async()=>{active++;peak=Math.max(peak,active);await sleep(10);active--;})));
  assert.ok(peak<=6 && peak>1, `peak=${peak}`);
});
test('concurrency: createSemaphore preserves values + errors', async () => {
  const limit = createSemaphore(3);
  assert.equal(await limit(async () => 42), 42);
  await assert.rejects(() => limit(async () => { throw new Error('boom'); }), /boom/);
});
test('suggestHeight: placeholder default 6', () => { assert.equal(suggestHeight({}), 6); });
test('suggestHeight: table scales + clamps', () => {
  assert.equal(suggestHeight({ spec:{type:'table'}, data:Array(20) }), 7);
  assert.equal(suggestHeight({ spec:{type:'table'}, data:Array(200) }), 16);
  assert.ok(suggestHeight({ spec:{type:'table'}, data:[] }) >= 5);
});
test('suggestHeight: dense chart taller', () => { assert.equal(suggestHeight({ spec:{type:'interval'}, data:Array(60) }), 8); });
test('MIN_H >= 3', () => assert.ok(MIN_H >= 3));

process.on('exit', () => dirs.forEach((d) => { try { rmSync(d, { recursive: true, force: true }); } catch {} }));
