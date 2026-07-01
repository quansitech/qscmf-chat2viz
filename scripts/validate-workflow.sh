#!/usr/bin/env bash
# validate-workflow.sh — Pre-flight syntax check for workflow scripts
# Usage: ./scripts/validate-workflow.sh <workflow-file.mjs>
# Exit 0 = valid, Exit 1 = parse error with details

set -euo pipefail

FILE="${1:?Usage: $0 <workflow-file>}"

if [ ! -f "$FILE" ]; then
  echo "FAIL: File not found: $FILE"
  exit 1
fi

# Simulate the Workflow runtime environment:
# - Strips 'export' keyword (runtime handles it)
# - Wraps in async function with runtime stubs (agent, phase, log, args, budget, workflow)
# - Checks with Node.js parser
node -e "
const fs = require('fs');
const code = fs.readFileSync('$FILE', 'utf8')
  .replace(/^export\s+const\s+meta/m, 'const meta');

const stubs = [
  'const agent = () => Promise.resolve()',
  'const parallel = (fns) => Promise.resolve([])',
  'const pipeline = (items, ...stages) => Promise.resolve([])',
  'const phase = (t) => {}',
  'const log = (m) => {}',
  'const args = {}',
  'const budget = {total:null,spent:()=>0,remaining:()=>0}',
  'const workflow = (n,a) => Promise.resolve()',
].join('; ');

try {
  new Function(stubs + '; async function __wf(){' + code + '}');
  console.log('PASS: $FILE');
  process.exit(0);
} catch(e) {
  // Extract line number from error
  const lineMatch = e.message.match(/\((\d+):(\d+)\)/);
  const loc = lineMatch ? ' (near line ' + lineMatch[1] + ', col ' + lineMatch[2] + ')' : '';
  console.log('FAIL: $FILE' + loc);
  console.log('  Error: ' + e.message);
  console.log('');
  console.log('  Common fixes:');
  console.log('    1. Template literal with \\\\\\` — remove the backslash before closing backtick');
  console.log('    2. \\\\u in text — escape as \\\\\\\\u or use string concatenation');
  console.log('    3. Nested backticks — use concatenation: \"text\" + \`\${var}\`');
  process.exit(1);
}
"
