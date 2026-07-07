// tests/e2e-browser/deterministic/fixtures-reference.contract.test.mjs
// 漂移检测：每个 spec front-matter 引用的 fixture 必须在 frontend/fixtures/dsl-v3/ 存在。
// 上游 react-dsl-v3-renderer 改名/删除 fixture 时本测试红灯。
// node:test 内置，零依赖零编译，CI-safe（无浏览器、无 API key）。
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..'); // tests/e2e-browser/
const SPECS_DIR = join(ROOT, 'specs');
const FIXTURES_DIR = resolve(ROOT, '../../frontend/fixtures/dsl-v3');

function readFrontMatter(specPath) {
  const text = readFileSync(specPath, 'utf8');
  const m = text.match(/^---\n([\s\S]*?)\n---/);
  if (!m) return {};
  const fm = {};
  for (const line of m[1].split('\n')) {
    const kv = line.match(/^(\w[\w-]*):\s*(.*)$/);
    if (kv) fm[kv[1]] = kv[2].replace(/\s+#.*$/, '').trim();
  }
  return fm;
}

const fixtures = readdirSync(FIXTURES_DIR)
  .filter((f) => f.endsWith('.replace.json'))
  .map((f) => f.replace('.replace.json', ''));

const specs = readdirSync(SPECS_DIR).filter((f) => f.endsWith('.spec.md'));

describe('fixtures-reference drift gate', () => {
  test('frontend/fixtures/dsl-v3/ has .replace.json fixtures', () => {
    assert.ok(fixtures.length > 0, `no fixtures found in ${FIXTURES_DIR}`);
  });

  for (const spec of specs) {
    test(`spec ${spec}: referenced fixture exists`, () => {
      const fm = readFrontMatter(join(SPECS_DIR, spec));
      // 规则：声明了 fixture 的必须在上游存在
      // （needs-llm:false 不强制 fixture —— 渲染类如 05 才走 ?__dsl_fixture；
      //  CRUD/API 类如 01 needs-llm:false 也可 fixture:null）
      if (fm.fixture && fm.fixture !== 'null') {
        assert.ok(
          fixtures.includes(fm.fixture),
          `${spec}: 引用 fixture "${fm.fixture}" 不存在于 frontend/fixtures/dsl-v3/；可用 ${JSON.stringify(fixtures)}`,
        );
      }
    });
  }
});
