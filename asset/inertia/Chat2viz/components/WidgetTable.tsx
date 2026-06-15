import { useMemo } from 'react';
import { Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';

/**
 * Native renderer for `type: "table"` widgets.
 *
 * G2 v5 has no `composition.table` mark, so a table g2_spec cannot flow through
 * LazyG2Renderer (it logs "Unknown Component: composition.table" and renders
 * nothing). Table widgets are rendered natively here instead, reading the same
 * `{rows, columns}` data the chart path uses (store-normalized bare Row[]).
 *
 * Column discovery (in priority order):
 *   1. g2_spec.encode.columns — ordered field names (agent contract)
 *   2. g2_spec.labels[].{text,alias} — display title per field
 *   3. fall back to the union of keys in the first data row
 *
 * The component is presentational: it derives columns purely from the spec +
 * data and does not mutate either.
 */
export interface WidgetTableProps {
  spec: Record<string, unknown>;
  data: Record<string, unknown>[];
}

interface LabelEntry {
  text?: unknown;
  alias?: unknown;
}

function asStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((v): v is string => typeof v === 'string');
}

function resolveTitle(field: string, labels: LabelEntry[]): string {
  const match = labels.find((l) => String(l.text ?? '') === field);
  const alias = match?.alias;
  return typeof alias === 'string' && alias.length > 0 ? alias : field;
}

export default function WidgetTable({ spec, data }: WidgetTableProps) {
  const columns: ColumnsType<Record<string, unknown>> = useMemo(() => {
    const encode = spec.encode;
    const orderedFields =
      encode && typeof encode === 'object' && !Array.isArray(encode)
        ? asStringArray((encode as Record<string, unknown>).columns)
        : [];

    const rawLabels = spec.labels;
    const labels: LabelEntry[] = Array.isArray(rawLabels)
      ? (rawLabels.filter((l) => l && typeof l === 'object') as LabelEntry[])
      : [];

    let fields = orderedFields;
    if (fields.length === 0) {
      const keySet = new Set<string>();
      for (const row of data) {
        if (row && typeof row === 'object') {
          for (const k of Object.keys(row)) keySet.add(k);
        }
      }
      fields = Array.from(keySet);
    }

    return fields.map((field) => ({
      title: resolveTitle(field, labels),
      dataIndex: field,
      key: field,
      ellipsis: true,
    }));
  }, [spec, data]);

  if (columns.length === 0) {
    return null;
  }

  return (
    <Table<Record<string, unknown>>
      columns={columns}
      dataSource={data}
      rowKey={(_, idx) => String(idx)}
      size="small"
      pagination={{ pageSize: 8, size: 'small', showSizeChanger: false }}
      scroll={{ x: 'max-content' }}
      style={{ width: '100%' }}
    />
  );
}
