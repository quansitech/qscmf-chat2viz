import { describe, it, expect } from 'vitest';
import { suggestHeight, MIN_H } from '../asset/inertia/Chat2viz/utils/suggestHeight';

describe('suggestHeight — content-aware widget height (议题4c)', () => {
  it('returns the neutral default for a placeholder with no spec', () => {
    expect(suggestHeight({})).toBe(6);
    expect(suggestHeight({ spec: null })).toBe(6);
    expect(suggestHeight({ spec: {} })).toBe(6);
  });

  it('scales table height with row count, clamped to [5, 16]', () => {
    const tableSpec = { type: 'table' };
    expect(suggestHeight({ spec: tableSpec, data: [] })).toBe(5);
    expect(suggestHeight({ spec: tableSpec, data: Array(8) })).toBe(5); // 2 + ceil(8/4) = 4 → clamped up to 5 (源码 Math.max(5, ...))
    expect(suggestHeight({ spec: tableSpec, data: Array(20) })).toBe(7); // 2 + 5
    expect(suggestHeight({ spec: tableSpec, data: Array(200) })).toBe(16); // clamped
  });

  it('gives taller height to dense (>50 rows) charts', () => {
    const barSpec = { type: 'interval' };
    expect(suggestHeight({ spec: barSpec, data: Array(60) })).toBe(8);
  });

  it('keeps the neutral default for ordinary small charts', () => {
    const lineSpec = { type: 'line' };
    expect(suggestHeight({ spec: lineSpec, data: Array(12) })).toBe(6);
    expect(suggestHeight({ spec: lineSpec })).toBe(6); // spec, no data yet
  });

  it('respects the mark-style spec (G2 v5) too', () => {
    expect(suggestHeight({ spec: { mark: 'line' }, data: Array(12) })).toBe(6);
  });

  it('MIN_H is at least 3 (grid minimum)', () => {
    expect(MIN_H).toBeGreaterThanOrEqual(3);
  });
});
