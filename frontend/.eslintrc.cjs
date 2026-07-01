// ESLint config — narrow, hooks-focused.
//
// Scope rationale: this config exists to prevent React hooks violations
// (the class of bug that caused the new-dashboard send crash, React error
// #310 — "Rendered more hooks than during the previous render"). It
// intentionally does NOT enable @typescript-eslint recommended rules,
// no-unused-vars, or formatting rules, so the first run on existing code
// stays low-noise. widen deliberately, not by accident.
module.exports = {
  root: true,
  parser: '@typescript-eslint/parser',
  parserOptions: {
    ecmaVersion: 2020,
    sourceType: 'module',
    ecmaFeatures: { jsx: true },
  },
  // Load @typescript-eslint as a plugin so existing inline eslint-disable
  // comments (e.g. // eslint-disable-next-line @typescript-eslint/no-explicit-any)
  // resolve correctly instead of erroring with "rule not found". We do NOT
  // enable its recommended rule set — only react-hooks rules are turned on
  // below, keeping the first-run noise to zero as intended.
  plugins: ['react-hooks', '@typescript-eslint'],
  rules: {
    // THE rule this config exists to enforce: hooks must be called
    // unconditionally, never after an early return or inside a condition.
    // Violations crash the app at runtime (#310) — block at lint time.
    'react-hooks/rules-of-hooks': 'error',
    // Hook dependency-array completeness. Warn only (not error) so existing
    // code with intentional omissions does not break the lint gate; fix
    // opportunistically.
    'react-hooks/exhaustive-deps': 'warn',
  },
  // Ignore build output + deps only. NOTE: do NOT ignore all of ../asset/ —
  // the dashboard source tree lives at ../asset/inertia/Chat2viz/ and IS in
  // lint scope. Only the compiled bundle dir (../asset/chat2viz-dashboard/)
  // should be excluded.
  ignorePatterns: [
    'node_modules/',
    'dist/',
    '../asset/chat2viz-dashboard/',
  ],
};
