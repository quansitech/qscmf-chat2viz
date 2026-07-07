// tests/e2e-browser/deterministic/spec-schema.contract.test.mjs
// spec front-matter 完整性 gate：必填字段存在 + 取值合法。
// 防止新增 spec 漏字段或写非法值，runner 调度失败。
// node:test 内置，零依赖零编译。
import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SPECS_DIR = resolve(__dirname, '../specs');

const REQUIRED = ['id', 'priority', 'source-change', 'needs-llm', 'quarantined'];
const VALID_PRIORITY = ['P0', 'P1', 'P2'];

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

const specs = readdirSync(SPECS_DIR).filter((f) => f.endsWith('.spec.md'));

describe('spec front-matter schema', () => {
  test('specs/ has spec files', () => {
    assert.ok(specs.length > 0, `no .spec.md found in ${SPECS_DIR}`);
  });

  for (const spec of specs) {
    test(`${spec}: required front-matter fields`, () => {
      const fm = readFrontMatter(join(SPECS_DIR, spec));
      for (const k of REQUIRED) {
        assert.ok(fm[k] !== undefined, `${spec}: 缺必填字段 "${k}"`);
      }
      assert.ok(
        VALID_PRIORITY.includes(fm.priority),
        `${spec}: priority "${fm.priority}" 非法（合法 ${JSON.stringify(VALID_PRIORITY)}）`,
      );
      assert.ok(
        ['true', 'false'].includes(fm['needs-llm']),
        `${spec}: needs-llm "${fm['needs-llm']}" 必须是 true/false`,
      );
      assert.ok(
        ['true', 'false'].includes(fm.quarantined),
        `${spec}: quarantined 必须是 true/false`,
      );
    });
  }
});
