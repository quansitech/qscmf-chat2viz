import { useEffect, useRef, useCallback } from 'react';
import { hasChartSpec } from './store/dashboardStore';

// ---------------------------------------------------------------------------
// Global guard for G2 internal async noise
// ---------------------------------------------------------------------------
// G2 v5 spawns isolated promises (label layout, interaction handling, animation
// settle) that are NOT chained off the `chart.render()` promise we hold. Some
// specs — e.g. interval marks whose `labels` block omits a `text` channel (KPI
// single-value cards) — make one of those internal promises reject with
// `o.flatMap is not a function`. Because the promise is detached, our
// `.render().catch()` cannot reach it, and it surfaces as an
// `Uncaught (in promise)` that floods the console. The chart itself renders
// fine — the rejection happens during a post-render cleanup step — so this is
// cosmetic noise, not a real failure.
//
// Install a one-shot global `unhandledrejection` listener that swallows ONLY
// these known G2 internal errors (matched by the signature `.flatMap is not a
// function`), leaving every other rejection untouched. Installed once per page.
let g2NoiseGuardInstalled = false;
function installG2NoiseGuard(): void {
  if (g2NoiseGuardInstalled || typeof window === 'undefined') return;
  g2NoiseGuardInstalled = true;
  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason;
    const msg = (reason && typeof reason === 'object' && 'message' in reason
      ? String((reason as Error).message)
      : String(reason));
    // G2's detached label/interaction promise — non-fatal, chart already drew.
    if (/\.flatMap is not a function/.test(msg)) {
      event.preventDefault();
    }
  });
}

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

  // task 8.3: deterministic key-sorted JSON so two specs with the same fields
  // in different insertion orders compare equal. Plain JSON.stringify(copy)
  // produced false-positive 'options' updates whenever the same spec arrived
  // via a different code path (hydration vs SSE vs buildSchema), causing
  // unnecessary full chart recreations.
  const stripDataStable = (s: Record<string, unknown>): string => {
    const copy: Record<string, unknown> = {};
    for (const k of Object.keys(s).sort()) {
      if (k !== 'data') copy[k] = s[k];
    }
    return stableStringify(copy);
  };
  if (stripDataStable(prevSpec) !== stripDataStable(nextSpec)) return 'options';

  // Only data differs
  return 'changeData';
}

/**
 * Deterministic JSON serialization (task 8.3). Like JSON.stringify but with
 * object keys sorted ascending at every depth, so {b:1,a:2} and {a:2,b:1}
 * produce identical output. Handles nested objects and arrays of objects.
 */
function stableStringify(value: unknown): string {
  if (value === null || typeof value !== 'object') {
    return JSON.stringify(value);
  }
  if (Array.isArray(value)) {
    return '[' + value.map(stableStringify).join(',') + ']';
  }
  const obj = value as Record<string, unknown>;
  const keys = Object.keys(obj).sort();
  return '{' + keys.map((k) => JSON.stringify(k) + ':' + stableStringify(obj[k])).join(',') + '}';
}

// ---------------------------------------------------------------------------
// Chart validation helper
// ---------------------------------------------------------------------------
// hasChartSpec is imported from the store (single source of truth, DESIGN_BASIS #7).
// Valid chart iff spec.type OR spec.mark OR non-empty children array.

/**
 * Sanitize chart data for G2 consumption.
 * Returns undefined if data is empty/invalid (G2 will use spec-embedded data).
 */
function sanitizeChartData(data: Record<string, unknown>[] | undefined): Record<string, unknown>[] | undefined {
  if (!data || !Array.isArray(data) || data.length === 0) return undefined;
  return data;
}

/**
 * Build a `.catch()` handler for a G2 `render()`/`changeData()` promise.
 *
 * G2 v5's `chart.options(spec).render()` returns a Promise that can reject
 * asynchronously (e.g. label/interaction processing on malformed or data-less
 * specs surfaces as `o.flatMap is not a function`). The sync `try/catch` in
 * renderChart only catches throws before the promise settles; async rejections
 * would otherwise escape as "Uncaught (in promise)" and spam the console.
 *
 * On rejection this destroys the chart (best-effort) and reports it back via
 * the `onDestroyed` callback so the caller can null its ref — leaving the
 * container blank instead of a half-rendered, broken canvas.
 */
function makeRenderCatchHandler(
  chart: any,
  onDestroyed?: () => void,
): (err: unknown) => void {
  return (err: unknown) => {
    // Swallow the rejection — a broken chart is non-fatal; the parent
    // component's error/empty branch renders a friendly placeholder.
    try {
      if (chart && typeof chart.destroy === 'function') chart.destroy();
    } catch {
      /* ignore */
    }
    if (onDestroyed) onDestroyed();
  };
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function G2Renderer({ spec, data, width, height, className }: G2RendererProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<any>(null);
  const prevSpecRef = useRef<Record<string, unknown> | null>(null);
  const prevDataRef = useRef<Record<string, unknown>[] | undefined>(undefined);

  // Install the global G2 internal-noise guard once per page (idempotent).
  useEffect(() => {
    installG2NoiseGuard();
  }, []);

  // --- Chart creation / update logic ---
  const renderChart = useCallback(() => {
    const container = containerRef.current;
    if (!container) return;
    if (typeof (window as any).G2 === 'undefined') return;
    if (!hasChartSpec(spec)) return;

    const G2 = (window as any).G2;
    if (!G2 || typeof G2.Chart !== 'function') return;
    const level = determineUpdateLevel(prevSpecRef.current, spec, prevDataRef.current, data);

    const safeData = sanitizeChartData(data);

    // G2 v5 can throw asynchronously (e.g. label/interaction `flatMap` on a
    // data-less spec) when rendered with no data and no spec-embedded data.
    // Skip rendering until real data arrives — the parent's loading/empty
    // branch covers the interim, so the container simply stays blank rather
    // than spawning an uncaught rejection.
    if (!safeData) return;

    switch (level) {
      case 'changeData': {
        // Level 1: Only data changed -> use changeData() for performance
        if (chartRef.current && safeData) {
          try {
            chartRef.current.changeData(safeData);
          } catch {
            // Fallback to options re-render if changeData fails
            try {
              chartRef.current.options(spec).render().catch(
                makeRenderCatchHandler(chartRef.current, () => { chartRef.current = null; }),
              );
            } catch {
              // Both changeData and options failed — destroy and recreate next cycle
              try { chartRef.current.destroy(); } catch { /* ignore */ }
              chartRef.current = null;
            }
          }
        }
        break;
      }

      case 'options': {
        // Level 2: Config/style changed -> re-apply options
        if (chartRef.current) {
          try {
            chartRef.current.options({ ...spec, ...(safeData ? { data: safeData } : {}) }).render().catch(
              makeRenderCatchHandler(chartRef.current, () => { chartRef.current = null; }),
            );
          } catch {
            // Fallback: destroy and recreate
            try { chartRef.current.destroy(); } catch { /* ignore */ }
            chartRef.current = null;
            try {
              const chart = new G2.Chart({ container, autoFit: true });
              chart.options({ ...spec, ...(safeData ? { data: safeData } : {}) }).render().catch(
                makeRenderCatchHandler(chart, () => { chartRef.current = null; }),
              );
              chartRef.current = chart;
            } catch {
              // Recreation also failed — leave chartRef null
            }
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
        try {
          const chart = new G2.Chart({ container, autoFit: true });
          chart.options({ ...spec, ...(safeData ? { data: safeData } : {}) }).render().catch(
            makeRenderCatchHandler(chart, () => { chartRef.current = null; }),
          );
          chartRef.current = chart;
        } catch (renderErr) {
          // G2 render failed (malformed spec, null data, etc.) — do not crash the component tree
          if (chartRef.current) {
            try { chartRef.current.destroy(); } catch { /* ignore */ }
            chartRef.current = null;
          }
        }
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
