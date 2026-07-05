import { useMemo, useCallback } from 'react';
import { Table, Empty, Button, Space, Tooltip } from 'antd';
import { DownloadOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { PluginProps } from './registry';

/**
 * DataTablePlugin — antd Table renderer for `plugin_type: 'data_table'`.
 *
 * Evolved from the legacy WidgetTable. Column discovery priority:
 *   1. plugin_spec.columns — ordered field names (contract §2.7)
 *   2. fall back to the union of keys across data rows
 *
 * Features:
 *   - Column sorting (numeric + string-aware comparator)
 *   - CSV export (zero-dependency, Blob-based download)
 *   - Pagination + horizontal scroll
 */
interface DataTableSpec {
  columns?: unknown;
}

function asStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((v): v is string => typeof v === 'string');
}

/**
 * Universal comparator for antd Table sorter: handles numbers, strings, nulls.
 * Numbers sort numerically; everything else by locale string compare; nulls last.
 */
function universalCompare(a: unknown, b: unknown): number {
  const av = a;
  const bv = b;
  if (av == null && bv == null) return 0;
  if (av == null) return -1;
  if (bv == null) return 1;
  if (typeof av === 'number' && typeof bv === 'number') return av - bv;
  // Coerce numeric-looking strings to numbers for comparison (DBHub returns
  // DECIMAL/SUM as strings; the Python coercion may not have run on this path).
  const an = typeof av === 'string' ? parseFloat(av) : NaN;
  const bn = typeof bv === 'string' ? parseFloat(bv) : NaN;
  if (!isNaN(an) && !isNaN(bn)) return an - bn;
  return String(av).localeCompare(String(bv));
}

/**
 * Export rows to CSV and trigger a browser download. Zero-dependency (no xlsx).
 * Columns come from the resolved field list; values are CSV-escaped.
 */
function exportCSV(
  fields: string[],
  rows: Record<string, unknown>[],
  filename: string,
): void {
  const escape = (v: unknown): string => {
    const s = v == null ? '' : String(v);
    // RFC 4180: quote fields containing comma, quote, or newline.
    if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
    return s;
  };
  const header = fields.map(escape).join(',');
  const body = rows.map((row) => fields.map((f) => escape(row[f])).join(',')).join('\n');
  const csv = `\uFEFF${header}\n${body}`; // BOM for Excel UTF-8 detection
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export default function DataTablePlugin({ widget, data }: PluginProps) {
  const spec = (widget.plugin_spec ?? {}) as DataTableSpec;
  const rows = Array.isArray(data) ? data : [];

  const fields = useMemo(() => {
    let f = asStringArray(spec.columns);
    if (f.length === 0) {
      const keySet = new Set<string>();
      for (const row of rows) {
        if (row && typeof row === 'object') {
          for (const k of Object.keys(row)) keySet.add(k);
        }
      }
      f = Array.from(keySet);
    }
    return f;
  }, [spec.columns, rows]);

  const columns: ColumnsType<Record<string, unknown>> = useMemo(() => {
    return fields.map((field) => ({
      title: field,
      dataIndex: field,
      key: field,
      ellipsis: true,
      sorter: (a, b) => universalCompare(a[field], b[field]),
      sortDirections: ['descend', 'ascend'] as const,
    }));
  }, [fields]);

  const handleExport = useCallback(() => {
    const name = (widget.title || 'data_table').replace(/[^\w\u4e00-\u9fa5-]/g, '_');
    exportCSV(fields, rows, `${name}.csv`);
  }, [fields, rows, widget.title]);

  if (columns.length === 0) {
    return (
      <div style={{ padding: 16, display: 'flex', justifyContent: 'center' }}>
        <Empty description="暂无列数据" image={Empty.PRESENTED_IMAGE_SIMPLE} />
      </div>
    );
  }

  return (
    <div style={{ width: '100%' }}>
      <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 4 }}>
        <Space>
          <Tooltip title="导出为 CSV 文件（可在 Excel 中打开）">
            <Button
              size="small"
              icon={<DownloadOutlined />}
              onClick={handleExport}
              disabled={rows.length === 0}
            >
              导出 CSV
            </Button>
          </Tooltip>
        </Space>
      </div>
      <Table<Record<string, unknown>>
        columns={columns}
        dataSource={rows}
        rowKey={(_, idx) => String(idx)}
        size="small"
        pagination={{ pageSize: 8, size: 'small', showSizeChanger: false }}
        scroll={{ x: 'max-content' }}
      />
    </div>
  );
}
