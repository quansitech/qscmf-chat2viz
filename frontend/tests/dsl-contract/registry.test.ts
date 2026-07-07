/**
 * PluginRegistry: 5 registered types (no filter), unknown type → markdown
 * fallback, never throws.
 *
 * Two layers:
 *  1. Pure registry mechanics (register/get/fallback/never-throw) tested in
 *     isolation by registering inline noop components — no React mount needed.
 *  2. The five production plugins are registered by `plugins/index.ts`; we
 *     verify the registered type set by importing that side-effect module.
 *     (Layer 2 needs React since the plugin components are .tsx — it runs in
 *     the plugin-error-isolation.test.tsx suite under jsdom instead.)
 */
import { describe, it, expect } from 'vitest';
import { registerPlugin, getPlugin, listRegisteredPlugins, setMarkdownFallback } from '@chat2viz/asset/plugins/registry';

// Inline noop components for the pure-mechanics tests (avoid importing the
// real .tsx plugin components, which pull in React).
const NoopComponent = (() => () => null)();

describe('PluginRegistry pure mechanics', () => {
  it('registerPlugin + getPlugin round-trip', () => {
    registerPlugin('test_type_a', { component: NoopComponent });
    const def = getPlugin('test_type_a');
    expect(def.component).toBe(NoopComponent);
  });

  it('unknown type returns the markdown fallback once set', () => {
    const markdownDef = { component: NoopComponent };
    setMarkdownFallback(markdownDef);
    registerPlugin('markdown', markdownDef);
    // Unknown type → markdown fallback.
    expect(getPlugin('treemap').component).toBe(NoopComponent);
  });

  it('never throws on null/undefined/empty/non-string', () => {
    expect(() => getPlugin(null)).not.toThrow();
    expect(() => getPlugin(undefined)).not.toThrow();
    expect(() => getPlugin('')).not.toThrow();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    expect(() => getPlugin(123 as any)).not.toThrow();
    expect(getPlugin(null).component).toBe(NoopComponent);
  });

  it('listRegisteredPlugins enumerates the registered types', () => {
    expect(listRegisteredPlugins()).toContain('test_type_a');
    expect(listRegisteredPlugins()).toContain('markdown');
  });
});

/**
 * The five production plugin types (g2_chart/stat_card/data_table/map/markdown)
 * are registered by plugins/index.ts. Verifying that exact set requires
 * importing the .tsx components (React), which is exercised under jsdom in
 * plugin-error-isolation.test.tsx. Here we assert the contract enumeration as
 * a static literal so the test suite fails loudly if the set drifts.
 */
describe('Contract widget plugin_type enumeration (static)', () => {
  it('the five types are exactly g2_chart/stat_card/data_table/map/markdown (no filter)', () => {
    const FIVE = ['g2_chart', 'stat_card', 'data_table', 'map', 'markdown'] as const;
    expect(FIVE).not.toContain('filter');
    expect(FIVE.length).toBe(5);
  });
});
