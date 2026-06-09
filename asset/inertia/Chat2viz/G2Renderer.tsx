import { useEffect, useRef, useCallback } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface G2RendererProps {
  /** G2 v5 spec object (the value passed to chart.options()). */
  spec: Record<string, unknown>;
  /** Chart data array — when only data changes, uses changeData() for speed. */
  data?: Record<string, unknown>[];
  /** Explicit width override (px). When omitted, uses container natural width. */
  width?: number;
  /** Explicit height override (px). When omitted, uses container natural height. */
  height?: number;
  /** Extra CSS class name for the container div. */
  className?: string;
}

// ---------------------------------------------------------------------------
// Update level determination
// ---------------------------------------------------------------------------

type UpdateLevel = 'changeData' | 'options' | 'recreate';

function determineUpdateLevel(
  prevSpec: Record<string, unknown> | null,
  nextSpec: Record<string, unknown>,
  prevData: Record<string, unknown>[] | undefined,
  nextData: Record<string, unknown>[] | undefined,
): UpdateLevel {
  // First render or spec reference changed type -> recreate
  if (!prevSpec) return 'recreate';

  const prevType = prevSpec.type;
  const nextType = nextSpec.type;

  // Chart type changed (e.g. "interval" -> "line") -> full recreate
  if (prevType !== nextType) return 'recreate';

  // Spec reference is the same object -> only data changed
  if (prevSpec === nextSpec) {
    if (prevData !== nextData) return 'changeData';
    return 'options'; // fallback — re-render options (no-op visually)
  }

  // Spec is a different object but same type — check if only data changed
  // by comparing spec keys (excluding "data")
  const prevKeys = Object.keys(prevSpec).filter((k) => k !== 'data').sort();
  const nextKeys = Object.keys(nextSpec).filter((k) => k !== 'data').sort();
  if (prevKeys.join(',') !== nextKeys.join(',')) return 'options';

  // Deep-compare non-data fields by JSON stringify (shallow enough for G2 specs)
  const stripData = (s: Record<string, unknown>) => {
    const copy = { ...s };
    delete copy.data;
    return JSON.stringify(copy);
  };
  if (stripData(prevSpec) !== stripData(nextSpec)) return 'options';

  // Only data differs
  return 'changeData';
}

// ---------------------------------------------------------------------------
// Chart validation helper
// ---------------------------------------------------------------------------

function hasChartSpec(spec: Record<string, unknown>): boolean {
  if (!spec || typeof spec !== 'object') return false;
  if (spec.type) return true;
  const children = spec.children;
  if (Array.isArray(children) && children.length > 0) return true;
  return false;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function G2Renderer({ spec, data, width, height, className }: G2RendererProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<any>(null);
  const prevSpecRef = useRef<Record<string, unknown> | null>(null);
  const prevDataRef = useRef<Record<string, unknown>[] | undefined>(undefined);

  // --- Chart creation / update logic ---
  const renderChart = useCallback(() => {
    const container = containerRef.current;
    if (!container) return;
    if (typeof (window as any).G2 === 'undefined') return;
    if (!hasChartSpec(spec)) return;

    const G2 = (window as any).G2;
    if (!G2 || typeof G2.Chart !== 'function') return;
    const level = determineUpdateLevel(prevSpecRef.current, spec, prevDataRef.current, data);

    switch (level) {
      case 'changeData': {
        // Level 1: Only data changed -> use changeData() for performance
        if (chartRef.current && data) {
          try {
            chartRef.current.changeData(data);
          } catch {
            // Fallback to options re-render if changeData fails
            chartRef.current.options(spec).render();
          }
        }
        break;
      }

      case 'options': {
        // Level 2: Config/style changed -> re-apply options
        if (chartRef.current) {
          try {
            chartRef.current.options({ ...spec, ...(data ? { data } : {}) }).render();
          } catch {
            // Fallback: destroy and recreate
            chartRef.current.destroy();
            chartRef.current = null;
            const chart = new G2.Chart({ container, autoFit: true });
            chart.options({ ...spec, ...(data ? { data } : {}) }).render();
            chartRef.current = chart;
          }
        }
        break;
      }

      case 'recreate':
      default: {
        // Level 3: Full recreate
        if (chartRef.current) {
          try {
            chartRef.current.destroy();
          } catch {
            // Ignore destroy errors
          }
          chartRef.current = null;
        }
        const chart = new G2.Chart({ container, autoFit: true });
        chart.options({ ...spec, ...(data ? { data } : {}) }).render();
        chartRef.current = chart;
        break;
      }
    }

    prevSpecRef.current = spec;
    prevDataRef.current = data;
  }, [spec, data]);

  // --- Initial render + spec/data change handling ---
  useEffect(() => {
    renderChart();
  }, [renderChart]);

  // --- ResizeObserver for container size changes ---
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const observer = new ResizeObserver(() => {
      if (chartRef.current && typeof chartRef.current.forceFit === 'function') {
        chartRef.current.forceFit();
      }
    });
    observer.observe(container);

    return () => {
      observer.disconnect();
    };
  }, []);

  // --- Cleanup: destroy chart on unmount ---
  useEffect(() => {
    return () => {
      if (chartRef.current) {
        try {
          chartRef.current.destroy();
        } catch {
          // Ignore cleanup errors
        }
        chartRef.current = null;
      }
    };
  }, []);

  const containerStyle: React.CSSProperties = {
    minHeight: 300,
    width: width ?? '100%',
    height: height ?? 'auto',
  };

  return (
    <div ref={containerRef} className={className} style={containerStyle} />
  );
}
