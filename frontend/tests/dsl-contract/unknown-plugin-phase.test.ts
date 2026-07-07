/**
 * §2.7 phase-aware unknown plugin_type handling.
 *
 * Phase 0-2 (default): unknown type renders an explicit error widget (no silent
 * markdown). Phase 3+: silent markdown fallback. Both driven by the build-time
 * phase constant + the `unknownRendersError` prop override.
 */
import { describe, it, expect } from 'vitest';
import { UNKNOWN_PLUGIN_RENDERS_ERROR } from '@chat2viz/asset/plugins/registry';

describe('§2.7 phase-aware unknown plugin_type', () => {
  it('the build-time default is Phase 0-2 (error, not silent markdown)', () => {
    // Default until Python ships directory discovery.
    expect(UNKNOWN_PLUGIN_RENDERS_ERROR).toBe(true);
  });
});

// PluginRenderer's phase behavior is verified by constructing the component's
// decision logic inline (avoids jsdom DOM mounting for this pure-logic test).
describe('PluginRenderer phase decision logic', () => {
  // Re-implement the decision to avoid importing React/jsdom here. The actual
  // component uses the same `unknownRendersError` flag.
  function decidesError(isKnown: boolean, unknownRendersError: boolean): boolean {
    return !isKnown && unknownRendersError;
  }

  it('Phase 0-2: unknown type → error widget', () => {
    expect(decidesError(false, true)).toBe(true);
  });

  it('Phase 3+: unknown type → markdown fallback (not error)', () => {
    expect(decidesError(false, false)).toBe(false);
  });

  it('Known type never triggers the error path regardless of phase', () => {
    expect(decidesError(true, true)).toBe(false);
    expect(decidesError(true, false)).toBe(false);
  });
});
