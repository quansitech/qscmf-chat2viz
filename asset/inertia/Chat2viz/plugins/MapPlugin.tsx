import { useMemo, useRef, useEffect, useCallback } from 'react';
import { Empty, Spin, Typography } from 'antd';
import type { PluginProps } from './registry';

/**
 * MapPlugin — geographic distribution visualization for `plugin_type: 'map'`.
 *
 * Strategy: uses G2 v5's built-in `cell` mark (heatmap/matrix) to render
 * region-value pairs as a color-intensity grid. Zero new dependencies (no L7/
 * leaflet/mapbox needed — G2 polygon/cell is built-in).
 *
 * For real geographic boundary rendering (choropleth), a future change can
 * fetch a GeoJSON (e.g. china-geojson) and use G2's `polygon` mark with
 * `coordinate.type: 'map'`. This cell-based approach is the pragmatic default
 * for databases without coordinate columns (like Sakila).
 *
 * plugin_spec shape (contract §2.7):
 *   { regionField: string, valueField: string, mapKey?: string }
 */
interface MapSpec {
  regionField?: string;
  valueField?: string;
  mapKey?: string;
}

export default function MapPlugin({ widget, data }: PluginProps) {
  const spec = (widget.plugin_spec ?? {}) as MapSpec;
  const rows = Array.isArray(data) ? data : [];
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<any>(null);

  const regionField = spec.regionField || '';
  const valueField = spec.valueField || '';

  // Infer fields from data if not declared in spec.
  const effectiveRegion = useMemo(() => {
    if (regionField) return regionField;
    if (rows.length > 0 && typeof rows[0] === 'object') {
      const keys = Object.keys(rows[0]);
      return keys.find((k) => typeof rows[0][k] === 'string') || keys[0] || '';
    }
    return '';
  }, [regionField, rows]);

  const effectiveValue = useMemo(() => {
    if (valueField) return valueField;
    if (rows.length > 0 && typeof rows[0] === 'object') {
      const keys = Object.keys(rows[0]);
      // Find the first numeric field.
      return keys.find((k) => {
        const v = rows[0][k];
        return typeof v === 'number' || (typeof v === 'string' && !isNaN(parseFloat(v)));
      }) || keys[1] || keys[0] || '';
    }
    return '';
  }, [valueField, rows]);

  // Build the G2 cell chart: region on y-axis (one row per region), value → color.
  const renderChart = useCallback(() => {
    const container = containerRef.current;
    if (!container) return;
    const G2 = (window as any).G2;
    if (!G2 || typeof G2.Chart !== 'function') return;
    if (!effectiveRegion || !effectiveValue || rows.length === 0) return;

    // Prepare data: coerce values to numbers for color intensity.
    const chartData = rows.map((r: Record<string, unknown>) => {
      const raw = r[effectiveValue];
      const num = typeof raw === 'number' ? raw : parseFloat(String(raw));
      return {
        region: String(r[effectiveRegion] ?? ''),
        value: isNaN(num) ? 0 : num,
      };
    });

    // Destroy previous chart if exists (modify-mode safety).
    if (chartRef.current) {
      try { chartRef.current.destroy(); } catch { /* ignore */ }
      chartRef.current = null;
    }

    try {
      const chart = new G2.Chart({ container, autoFit: true });
      chart.options({
        type: 'cell',
        data: chartData,
        encode: { y: 'region', color: 'value' },
        scale: {
          color: { palette: 'YlOrRd' },
        },
        style: {
          fillOpacity: 0.85,
          stroke: '#fff',
          lineWidth: 2,
        },
        axis: {
          y: { title: widget.title || '区域' },
          x: false,
        },
        legend: {
          color: {
            position: 'right',
            title: effectiveValue,
            layout: { justifyContent: 'center' },
          },
        },
        tooltip: {
          title: 'region',
          items: [{ field: 'value', name: effectiveValue }],
        },
        labels: [
          {
            text: 'value',
            position: 'inside',
            fill: '#333',
            fontSize: 11,
            fontWeight: 500,
          },
        ],
      });
      chart.render();
      chartRef.current = chart;
    } catch {
      // G2 render failed — fall through to the table fallback below.
    }
  }, [effectiveRegion, effectiveValue, rows, widget.title]);

  useEffect(() => {
    renderChart();
  }, [renderChart]);

  // Cleanup on unmount.
  useEffect(() => {
    return () => {
      if (chartRef.current) {
        try { chartRef.current.destroy(); } catch { /* ignore */ }
      }
    };
  }, []);

  // Fallback: if G2 chart didn't render, show the data as a colored table.
  const hasChart = !!chartRef.current;

  if (!effectiveRegion || !effectiveValue || rows.length === 0) {
    return (
      <div style={{ padding: 16, display: 'flex', justifyContent: 'center' }}>
        <Empty description="暂无地理数据（需要 regionField + valueField）" image={Empty.PRESENTED_IMAGE_SIMPLE} />
      </div>
    );
  }

  return (
    <div style={{ width: '100%', height: '100%', display: 'flex', flexDirection: 'column' }}>
      <div ref={containerRef} style={{ flex: 1, minHeight: 200 }} />
      {!hasChart && (
        <div style={{ padding: 8, overflow: 'auto' }}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            地理分布（区域热力）
          </Typography.Text>
          <RegionColorTable
            rows={rows}
            regionField={effectiveRegion}
            valueField={effectiveValue}
          />
        </div>
      )}
    </div>
  );
}

/**
 * Fallback colored table: each region row is shaded by its value intensity.
 */
function RegionColorTable({
  rows,
  regionField,
  valueField,
}: {
  rows: Record<string, unknown>[];
  regionField: string;
  valueField: string;
}) {
  const values = rows.map((r) => {
    const v = r[valueField];
    return typeof v === 'number' ? v : parseFloat(String(v)) || 0;
  });
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const range = max - min || 1;

  const colorFor = (v: number): string => {
    const intensity = (v - min) / range;
    // YlOrRd palette interpolation: yellow(255,247,188) → red(240,59,32)
    const r = Math.round(255 + (240 - 255) * intensity);
    const g = Math.round(247 + (59 - 247) * intensity);
    const b = Math.round(188 + (32 - 188) * intensity);
    return `rgb(${r},${g},${b})`;
  };

  return (
    <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12, marginTop: 4 }}>
      <thead>
        <tr>
          <th style={thStyle}>{regionField}</th>
          <th style={thStyle}>{valueField}</th>
        </tr>
      </thead>
      <tbody>
        {rows.slice(0, 50).map((r, i) => {
          const v = typeof r[valueField] === 'number' ? r[valueField] : parseFloat(String(r[valueField])) || 0;
          return (
            <tr key={i}>
              <td style={{ ...tdStyle, background: colorFor(v) }}>{String(r[regionField] ?? '')}</td>
              <td style={{ ...tdStyle, background: colorFor(v), fontWeight: 600 }}>{v}</td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

const thStyle: React.CSSProperties = {
  border: '1px solid #f0f0f0',
  padding: '4px 8px',
  background: '#fafafa',
  textAlign: 'left',
};
const tdStyle: React.CSSProperties = {
  border: '1px solid #f0f0f0',
  padding: '4px 8px',
};
