import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';

/**
 * Vitest configuration for the chat2viz dashboard frontend.
 *
 * Scope: pure-logic unit tests + the dsl-contract pipeline suite (jsdom where
 * needed via a per-file `// @vitest-environment jsdom` comment). The default
 * (node) environment is used for pure-logic tests.
 *
 * The `@chat2viz/asset` alias points at the in-package asset tree so both
 * `tests/` (one level deep) and `tests/dsl-contract/` (two levels deep) can
 * import the same modules without per-file relative-path drift.
 *
 * Run: `npm test` (see package.json).
 */
export default defineConfig({
  resolve: {
    alias: {
      '@chat2viz/asset': resolve(__dirname, '../asset/inertia/Chat2viz'),
    },
  },
  test: {
    include: ['tests/**/*.test.ts', 'fixtures/**/*.test.ts'],
    environment: 'node',
  },
});
