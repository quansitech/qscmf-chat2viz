/**
 * Contract schema guard — validates every DSL v3 fixture against the
 * structural invariants of `dashboard-dsl-contract.md` §2 / §2.6.
 *
 * This guard runs uniformly over both the project's golden fixtures and
 * Python's real output once it is dropped into `fixtures/dsl-v3/python/`,
 * acting as a bidirectional contract linter.
 *
 * Invariants asserted (contract clauses in parens):
 *  - `version` starts with "3." (§2.1)
 *  - every `:name` placeholder in a QuerySpec.raw_sql has a matching entry
 *    in `params[]` and vice versa (§2.3 bijection)
 *  - every WidgetSpec.query_id references a query that exists in dsl.queries (§2.4)
 *  - every slot.widget_id references a widget that exists in dsl.widgets (§2.2)
 *  - every slot.region is a member of dsl.layout.regions (§2.2)
 *  - the interaction graph is acyclic per the precise §2.6 definition:
 *      nodes = {widget_id} ∪ {slicer_id}  (queries are NOT nodes)
 *      edges = explicit interactions[*] (source_id → target_id)
 *            + implicit slicer_id → widget_id derived by reverse-looking-up
 *              slicers[*].target_query_params keys against widgets[*].query_id
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));

// ---- type shorthands (kept loose — we are validating untyped fixture JSON) ----

interface LooseQuery {
  query_id?: string;
  raw_sql?: string;
  params?: Array<{ name?: string; type?: string }>;
  masked_columns?: string[];
}
interface LooseWidget {
  widget_id?: string;
  query_id?: string;
}
interface LooseSlot {
  widget_id?: string;
  region?: string;
}
interface LooseSlicer {
  slicer_id?: string;
  target_query_params?: Record<string, string>;
}
interface LooseInteraction {
  source_id?: string;
  target_id?: string;
}
interface LooseDsl {
  version?: string;
  layout?: { regions?: string[]; slots?: LooseSlot[] };
  queries?: Record<string, LooseQuery>;
  widgets?: Record<string, LooseWidget>;
  slicers?: LooseSlicer[];
  interactions?: LooseInteraction[];
}

// ---- helpers --------------------------------------------------------------

/**
 * Extract `:name` placeholders from a SQL template (contract §2.3).
 * Matches `:` followed by an identifier (letters/digits/underscore). Skips the
 * `::` cast operator (postgres) by requiring the next char to be a word char.
 */
function extractParamPlaceholders(rawSql: string): Set<string> {
  const names = new Set<string>();
  const re = /(?<![:\w]):([A-Za-z_]\w*)/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(rawSql)) !== null) {
    names.add(m[1]);
  }
  return names;
}

/** Collect `.replace.json` + `.json` fixture files under a directory. */
function collectFixtures(dir: string): Array<{ name: string; data: unknown }> {
  let entries: string[];
  try {
    entries = readdirSync(dir);
  } catch {
    return [];
  }
  const out: Array<{ name: string; data: unknown }> = [];
  for (const entry of entries) {
    if (!entry.endsWith('.json')) continue;
    if (entry.endsWith('.frames.json')) continue; // frame sequences aren't DSL trees
    const fullPath = join(dir, entry);
    let raw: string;
    try {
      raw = readFileSync(fullPath, 'utf8');
    } catch {
      continue;
    }
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      // malformed JSON — surface as a failing fixture so it gets noticed
      out.push({ name: entry, data: null });
      continue;
    }
    out.push({ name: entry, data: parsed });
  }
  return out;
}

/**
 * Build the §2.6 DAG and detect cycles via Kahn's topological sort.
 *
 * nodes = widget_id ∪ slicer_id (queries NOT nodes)
 * edges = explicit interactions (source→target)
 *       + implicit slicer→widget (reverse-lookup target_query_params keys
 *         against widgets[*].query_id)
 *
 * Returns `{ cyclic: boolean, nodes, edges }` for diagnostic assertions.
 */
function detectDagCycle(dsl: LooseDsl): {
  cyclic: boolean;
  nodes: Set<string>;
  edges: Array<[string, string]>;
} {
  const widgets = dsl.widgets ?? {};
  const slicers = dsl.slicers ?? [];
  const interactions = dsl.interactions ?? [];

  // Build query_id → widget_id reverse index (for implicit slicer→widget edges)
  const queryToWidgets = new Map<string, Set<string>>();
  for (const [wid, w] of Object.entries(widgets)) {
    const qid = w?.query_id;
    if (typeof qid === 'string' && qid !== '') {
      if (!queryToWidgets.has(qid)) queryToWidgets.set(qid, new Set());
      queryToWidgets.get(qid)!.add(wid);
    }
  }

  const nodes = new Set<string>();
  for (const wid of Object.keys(widgets)) nodes.add(wid);
  for (const s of slicers) {
    if (typeof s.slicer_id === 'string') nodes.add(s.slicer_id);
  }

  const edges: Array<[string, string]> = [];
  // explicit interaction edges (source_id → target_id)
  for (const it of interactions) {
    if (typeof it.source_id === 'string' && typeof it.target_id === 'string') {
      edges.push([it.source_id, it.target_id]);
    }
  }
  // implicit slicer→widget edges
  for (const s of slicers) {
    const sid = s.slicer_id;
    if (typeof sid !== 'string') continue;
    const tqp = s.target_query_params ?? {};
    for (const qid of Object.keys(tqp)) {
      const affectedWidgets = queryToWidgets.get(qid);
      if (!affectedWidgets) continue; // dangling ref — skipped, not a cycle
      for (const wid of affectedWidgets) {
        edges.push([sid, wid]);
      }
    }
  }

  // Kahn's algorithm
  const indegree = new Map<string, number>();
  for (const n of nodes) indegree.set(n, 0);
  for (const [_, tgt] of edges) {
    if (indegree.has(tgt)) indegree.set(tgt, (indegree.get(tgt) ?? 0) + 1);
  }
  const adj = new Map<string, Set<string>>();
  for (const [src, tgt] of edges) {
    if (!adj.has(src)) adj.set(src, new Set());
    adj.get(src)!.add(tgt);
  }
  const queue: string[] = [];
  for (const [n, d] of indegree) if (d === 0) queue.push(n);
  let visited = 0;
  while (queue.length > 0) {
    const n = queue.shift()!;
    visited += 1;
    for (const m of adj.get(n) ?? new Set<string>()) {
      const d = (indegree.get(m) ?? 0) - 1;
      indegree.set(m, d);
      if (d === 0) queue.push(m);
    }
  }
  return { cyclic: visited !== nodes.size, nodes, edges };
}

/** Run all structural checks for a single DSL fixture; returns assertion failures. */
function validateDsl(name: string, data: unknown): string[] {
  const failures: string[] = [];
  const dsl = (data ?? {}) as LooseDsl;

  // §2.1: version starts with "3."
  if (typeof dsl.version !== 'string' || !dsl.version.startsWith('3.')) {
    failures.push(`version must start with "3." (got ${JSON.stringify(dsl.version)})`);
  }

  const queries = dsl.queries ?? {};
  const widgets = dsl.widgets ?? {};
  const slots = dsl.layout?.slots ?? [];
  const regions = dsl.layout?.regions ?? [];

  // §2.3: :param ↔ params[] bijection per query
  for (const [qid, q] of Object.entries(queries)) {
    const sql = typeof q.raw_sql === 'string' ? q.raw_sql : '';
    const placeholders = extractParamPlaceholders(sql);
    const declared = new Set(
      (q.params ?? []).map((p) => (typeof p?.name === 'string' ? p.name : '')).filter((n) => n !== ''),
    );
    for (const ph of placeholders) {
      if (!declared.has(ph)) {
        failures.push(`query ${qid}: placeholder :${ph} not declared in params[]`);
      }
    }
    for (const dn of declared) {
      if (!placeholders.has(dn)) {
        failures.push(`query ${qid}: params[] entry "${dn}" has no matching :${dn} in raw_sql`);
      }
    }
  }

  // §2.4: widget.query_id references an existing query
  for (const [wid, w] of Object.entries(widgets)) {
    const qid = w?.query_id;
    if (typeof qid === 'string' && qid !== '' && !queries[qid]) {
      failures.push(`widget ${wid}: query_id "${qid}" references a non-existent query`);
    }
  }

  // §2.2: slot.widget_id references an existing widget; slot.region ∈ regions
  const regionSet = new Set(regions);
  for (const slot of slots) {
    const swid = slot?.widget_id;
    if (typeof swid === 'string' && swid !== '' && !widgets[swid]) {
      failures.push(`slot references non-existent widget "${swid}"`);
    }
    const region = slot?.region;
    if (typeof region === 'string' && region !== '' && !regionSet.has(region)) {
      failures.push(`slot for widget "${swid}" has region "${region}" not in regions ${JSON.stringify(regions)}`);
    }
  }

  // §2.6: DAG acyclic (precise node/edge definition)
  const { cyclic } = detectDagCycle(dsl);
  if (cyclic) {
    failures.push('interaction/slicer graph has a cycle (§2.6 DAG invariant violated)');
  }

  return failures;
}

// ---- the actual test suite -------------------------------------------------

const goldenDir = __dirname;
const pythonDir = join(__dirname, 'python');

const goldenFixtures = collectFixtures(goldenDir).filter(
  (f) => !f.name.endsWith('.test.ts') && f.name !== '__schema__.test.ts',
);
const pythonFixtures = collectFixtures(pythonDir);

describe('DSL v3 contract schema guard — golden fixtures', () => {
  for (const fixture of goldenFixtures) {
    const label = fixture.name.replace(/\.replace\.json$/, '').replace(/\.json$/, '');
    describe(`fixture: ${label}`, () => {
      it('satisfies all contract §2 structural invariants', () => {
        const failures = validateDsl(fixture.name, fixture.data);
        if (failures.length > 0) {
          throw new Error(
            `${fixture.name} violated contract invariants:\n  - ` + failures.join('\n  - '),
          );
        }
        expect(failures).toEqual([]);
      });
    });
  }
});

describe('DSL v3 contract schema guard — Python output (bidirectional)', () => {
  // Skip the suite entirely when no Python fixtures are present yet (early
  // parallel-development window). Once Python ships v3 and dumps its output
  // here, the same guard runs automatically.
  const hasPython = pythonFixtures.length > 0;
  (hasPython ? describe : describe.skip)('python fixtures present', () => {
    for (const fixture of pythonFixtures) {
      const label = basename(fixture.name).replace(/\.json$/, '');
      it(`python fixture ${label} satisfies contract §2 invariants`, () => {
        const failures = validateDsl(fixture.name, fixture.data);
        if (failures.length > 0) {
          throw new Error(
            `${fixture.name} violated contract invariants:\n  - ` + failures.join('\n  - '),
          );
        }
        expect(failures).toEqual([]);
      });
    }
  });
});

// ---- DAG precision unit tests (§2.6 explicit contract examples) ------------

describe('DAG precision (§2.6 node/edge definition)', () => {
  it('detects a slicer→widget→slicer cycle via implicit + explicit edges', () => {
    // slicer s1 affects widget w1 (implicit via q1), interaction w1→s1 (explicit)
    const dsl: LooseDsl = {
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1', params: [] } },
      widgets: { w1: { widget_id: 'w1', query_id: 'q1' } },
      slicers: [{ slicer_id: 's1', target_query_params: { q1: 'region' } }],
      interactions: [{ source_id: 'w1', target_id: 's1', event_type: 'click', action: 'drill' }],
    };
    expect(detectDagCycle(dsl).cyclic).toBe(true);
  });

  it('does NOT flag slicer→query→widget injection as a cycle (queries are not nodes)', () => {
    // No interaction cycle — just a slicer affecting widgets through a query.
    const dsl: LooseDsl = {
      version: '3.0.0',
      layout: { regions: ['content'], slots: [{ widget_id: 'w1', region: 'content', x: 0, y: 0, w: 12, h: 6 }] },
      queries: { q1: { query_id: 'q1', raw_sql: 'SELECT 1 WHERE r = :r', params: [{ name: 'r', type: 'string' }] } },
      widgets: { w1: { widget_id: 'w1', query_id: 'q1' } },
      slicers: [{ slicer_id: 's1', target_query_params: { q1: 'r' } }],
      interactions: [],
    };
    expect(detectDagCycle(dsl).cyclic).toBe(false);
  });
});
