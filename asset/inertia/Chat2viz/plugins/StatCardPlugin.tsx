import { Card, Statistic, Typography } from 'antd';
import type { PluginProps } from './registry';

/**
 * StatCardPlugin — antd Statistic renderer for `plugin_type: 'stat_card'`.
 *
 * Reads the `field` value from the first data row. The `formatter` vocabulary
 * is authoritative for the contract (§2.7): currency | percent | number |
 * compact | none. Unknown/missing formatter defaults to `none`.
 *
 * NEVER uses G2 gauge/liquid — `@antv/g2-extension-plot` is not installed, so
 * any G2 gauge mark would blank. antd Statistic is the explicit, dependency-
 * free path.
 */

type FormatterName = 'currency' | 'percent' | 'number' | 'compact' | 'none';

function isFormatterName(v: unknown): v is FormatterName {
  return v === 'currency' || v === 'percent' || v === 'number' || v === 'compact' || v === 'none';
}

interface StatCardSpec {
  field?: unknown;
  label?: unknown;
  formatter?: unknown;
}

/** Format a numeric value per the formatter vocabulary (§2.7). */
export function formatStatValue(value: unknown, formatter: FormatterName): string {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return value == null ? '—' : String(value);
  }
  switch (formatter) {
    case 'currency':
      // locale-currency formatted, e.g. ¥1,234.50 (CNY, 2 decimals).
      try {
        return new Intl.NumberFormat('zh-CN', {
          style: 'currency',
          currency: 'CNY',
          minimumFractionDigits: 2,
          maximumFractionDigits: 2,
        }).format(value);
      } catch {
        return `¥${value.toFixed(2)}`;
      }
    case 'percent':
      // value × 100 with % suffix (contract: percent formatter multiplies).
      return `${(value * 100).toFixed(2)}%`;
    case 'number':
      try {
        return new Intl.NumberFormat('zh-CN').format(value);
      } catch {
        return String(value);
      }
    case 'compact':
      // K/M abbreviation.
      try {
        return new Intl.NumberFormat('zh-CN', { notation: 'compact' }).format(value);
      } catch {
        return String(value);
      }
    case 'none':
    default:
      return String(value);
  }
}

export default function StatCardPlugin({ widget, data }: PluginProps) {
  const spec = (widget.plugin_spec ?? {}) as StatCardSpec;
  const field = typeof spec.field === 'string' ? spec.field : null;
  const label = typeof spec.label === 'string' ? spec.label : widget.title ?? '';
  const formatter: FormatterName = isFormatterName(spec.formatter) ? (spec.formatter as FormatterName) : 'none';

  const rows = Array.isArray(data) ? data : [];
  const firstRow = rows.length > 0 ? rows[0] : null;
  const rawValue = field && firstRow && typeof firstRow === 'object' ? (firstRow as Record<string, unknown>)[field] : undefined;
  const display = formatStatValue(rawValue, formatter);

  return (
    <Card size="small" style={{ height: '100%', display: 'flex', flexDirection: 'column', justifyContent: 'center' }} bodyStyle={{ padding: 16 }}>
      <Statistic
        title={label || '指标'}
        value={display}
      />
      {rows.length === 0 && (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          暂无数据
        </Typography.Text>
      )}
    </Card>
  );
}
