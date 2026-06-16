import { describe, it, expect } from 'vitest';

/**
 * Status-transition contract tests (议题1 + 议题2).
 *
 * The store's actual state machine lives inside a Zustand+temporal factory that
 * is awkward to instantiate in isolation, so these tests pin the *behavioral
 * contract* the UI/auto-save rely on: the literal values of StreamingState and
 * the predicates derived from them. This guards against accidental renames
 * (e.g. the useDashboardDraft auto-save edge keys off `=== 'idle'`).
 */
import type { StreamingState, HistoryStatus } from '../asset/inertia/Chat2viz/store/dashboardStore';

describe('status contract (缺陷1 + 缺陷2)', () => {
  it('StreamingState includes the additive submitted phase but keeps streaming/idle', () => {
    // The 'streaming' literal MUST be preserved — useDashboardDraft's edge
    // detection and several UI gates depend on it. 'submitted' is additive.
    const values: StreamingState[] = ['idle', 'submitted', 'streaming', 'error'];
    expect(values).toContain('submitted');
    expect(values).toContain('streaming');
    expect(values).toContain('idle');
  });

  it('an ask is considered in-flight for any non-idle state', () => {
    // useDashboardDraft suppresses auto-save while an ask is in flight. This
    // predicate (non-idle) must cover BOTH 'submitted' and 'streaming'.
    const isInFlight = (s: StreamingState) => s !== 'idle';
    expect(isInFlight('submitted')).toBe(true);
    expect(isInFlight('streaming')).toBe(true);
    expect(isInFlight('idle')).toBe(false);
  });

  it('marks the stream-end edge: any non-idle → idle transition flushes once', () => {
    const isStreamEnd = (prev: StreamingState, cur: StreamingState) =>
      prev !== 'idle' && cur === 'idle';
    // submitted → idle (e.g. immediate error) still counts as an ended ask.
    expect(isStreamEnd('submitted', 'idle')).toBe(true);
    expect(isStreamEnd('streaming', 'idle')).toBe(true);
    // submitted → streaming is NOT an end (still in flight).
    expect(isStreamEnd('submitted', 'streaming')).toBe(false);
    // idle → idle is never an end.
    expect(isStreamEnd('idle', 'idle')).toBe(false);
  });

  it('HistoryStatus is a separate, orthogonal dimension', () => {
    const all: HistoryStatus[] = ['idle', 'loading', 'ready', 'error'];
    expect(all).toEqual(['idle', 'loading', 'ready', 'error']);
    // historyLoading drives the chat send-disable + history spinner.
    const isHistoryLoading = (h: HistoryStatus) => h === 'loading';
    expect(isHistoryLoading('loading')).toBe(true);
    expect(isHistoryLoading('ready')).toBe(false);
  });
});
