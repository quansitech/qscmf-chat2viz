import { defineConfig } from 'vitest/config';

/**
 * Vitest configuration for the chat2viz dashboard frontend.
 *
 * Scope: pure-logic unit tests for the new utils (tool-step de-duplication,
 * bounded-concurrency semaphore, content-aware height suggestion, and the
 * streaming/history status transitions). No DOM rendering is exercised, so
 * the default (node) environment is used; jsdom is installed as a devDep in
 * case component tests are added later (set `environment: 'jsdom'` per-file
 * via a `// @vitest-environment jsdom` comment then).
 *
 * Run: `npm test` (see package.json).
 */
export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
    environment: 'node',
  },
});
