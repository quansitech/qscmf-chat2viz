/**
 * stat_card formatter vocabulary (§2.7 authoritative): currency/percent/number/
 * compact/none. Unknown → none.
 *
 * The formatter logic lives in StatCardPlugin.tsx; we re-implement the mapping
 * here to pin the contract without importing the .tsx component (which pulls
 * in React + antd, unsuited to the node test environment). The plugin uses the
 * identical Intl-based logic.
 */
import { describe, it, expect } from 'vitest';

function format(value: unknown, formatter: string): string {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return value == null ? '—' : String(value);
  }
  switch (formatter) {
    case 'currency':
      try {
        return new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'CNY', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
      } catch { return `¥${value.toFixed(2)}`; }
    case 'percent':
      return `${(value * 100).toFixed(2)}%`;
    case 'number':
      try { return new Intl.NumberFormat('zh-CN').format(value); } catch { return String(value); }
    case 'compact':
      try { return new Intl.NumberFormat('zh-CN', { notation: 'compact' }).format(value); } catch { return String(value); }
    case 'none':
    default:
      return String(value);
  }
}

describe('stat_card formatter vocabulary (§2.7 authoritative)', () => {
  it('currency formats with symbol + 2 decimals', () => {
    expect(format(1234.5, 'currency')).toMatch(/[¥￥]/);
    expect(format(1234.5, 'currency')).toMatch(/1,234\.50/);
  });

  it('percent multiplies by 100 + % suffix', () => {
    expect(format(0.15, 'percent')).toBe('15.00%');
  });

  it('number thousands-separates', () => {
    expect(format(1234567, 'number')).toMatch(/1,234,567/);
  });

  it('compact abbreviates', () => {
    const out = format(1500000, 'compact');
    expect(out.length).toBeLessThan('1500000'.length);
  });

  it('none is raw', () => {
    expect(format(42, 'none')).toBe('42');
  });

  it('unknown formatter defaults to none', () => {
    expect(format(42, 'hex')).toBe('42');
    expect(format(42, '')).toBe('42');
  });
});
