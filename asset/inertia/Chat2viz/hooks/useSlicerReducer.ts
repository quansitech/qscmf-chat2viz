import { useCallback } from 'react';
import { useDashboardStore } from '../store/dashboardStore';
import { resolveAffectedWidgets } from '../utils/dslHelpers';

// Re-export the pure helper for existing importers (contract tests import it
// directly from utils/dslHelpers now to avoid the React dependency).
export { resolveAffectedWidgets };

// ---------------------------------------------------------------------------
// Hook
//
// On a slicer value change: resolve target_query_params → affected widgets,
// then call setSlicerValue in the store (the single side effect). Affected
// widgets refetch automatically because useWidgetData keys on slicerValues.
// The reducer performs NO manual fetch orchestration.
// ---------------------------------------------------------------------------

export interface UseSlicerReducerReturn {
  /** Change a slicer value (resolves affected widgets, writes to the store). */
  changeSlicer: (slicerId: string, value: unknown) => void;
  /** Drill/detail interaction dispatch (reuses the slicer mechanism, §2.6). */
  onDrill: (params: Record<string, unknown>) => void;
}

export function useSlicerReducer(): UseSlicerReducerReturn {
  const changeSlicer = useCallback((slicerId: string, value: unknown) => {
    const state = useDashboardStore.getState();
    const dsl = state.dsl;
    if (!dsl) return;
    const slicer = (dsl.slicers ?? []).find((s) => s.slicer_id === slicerId);
    if (!slicer) return;
    // Resolve affected widgets (for any future logging/audit). The actual
    // refetch is driven declaratively by the slicerValues react-query key.
    resolveAffectedWidgets(slicer, dsl);
    // Single side effect: write the slicer value.
    state.setSlicerValue(slicerId, value);
  }, []);

  // InteractionSpec drill/detail (§2.6) reuses the slicer mechanism. The
  // interaction's params map carries the new slicer values keyed by slicer_id.
  const onDrill = useCallback((params: Record<string, unknown>) => {
    const state = useDashboardStore.getState();
    for (const [k, v] of Object.entries(params)) {
      if (state.dsl?.slicers?.some((s) => s.slicer_id === k)) {
        state.setSlicerValue(k, v);
      }
    }
  }, []);

  return { changeSlicer, onDrill };
}
