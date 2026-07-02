import { useMemo, type ReactNode } from 'react';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import WidgetCard from './WidgetCard';
import type { Widget, WidgetLayout } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// WidthProvider wraps RGL to auto-track container width
// ---------------------------------------------------------------------------

const ResponsiveGridLayout = WidthProvider(RGL);

// ---------------------------------------------------------------------------
// Constants — shared by edit page (PreviewPanel) and view page (DashboardView).
// Extracted here so both pages are guaranteed identical grid behavior.
// ---------------------------------------------------------------------------

export const COLS = 24;
export const ROW_HEIGHT = 60;
export const COMPACT_TYPE: ('vertical' | 'horizontal' | null) = 'vertical';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/**
 * Unified widget shape accepted by the grid. The store-backed `Widget` and the
 * view-page `SchemaWidget` both structurally satisfy this — the grid never
 * touches editing concerns, only reads layout + chart fields.
 */
export interface GridWidget {
  id: string;
  title?: string;
  g2_spec?: Record<string, unknown>;
  data?: unknown;
  sql?: string;
  status?: Widget['status'];
  layout?: WidgetLayout;
  truncated?: boolean;
  total?: number;
  refreshInterval?: number;
  data_explain?: string;
  suspect_value_mismatch?: boolean;
}

interface DashboardGridProps {
  /** Widgets to lay out. Order determines DOM order; positions come from layout. */
  widgets: GridWidget[];
  /**
   * Read-only mode (default: true). When true, all editing affordances are
   * disabled (drag/resize/title-edit/refresh/delete). The view page passes
   * readonly; the edit page passes readonly={false}.
   */
  readonly?: boolean;
  /**
   * Edit-mode layout-change callback. Invoked with the new layout array when
   * the user drags/resizes. The edit page wires this to updateLayout.
   */
  onLayoutChange?: (layout: Layout[]) => void;
  /**
   * Edit-mode resize-stop callback. Fires when the user finishes a manual
   * resize — the edit page uses this to mark the widget userSized so its
   * height is frozen and not recomputed on the next data refresh.
   */
  onResizeStop?: (oldItem: Layout) => void;
  /** Edit-mode drag lifecycle hooks (scroll-lock toggling on the edit page). */
  onDragStart?: () => void;
  onDragStop?: () => void;
  onResizeStart?: () => void;
  /**
   * Card render override. By default each cell renders a readonly WidgetCard.
   * The view page supplies this to wrap WidgetCard with react-query data
   * fetching (its data arrives via HTTP, not the store).
   */
  renderCard?: (widget: GridWidget) => ReactNode;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

/**
 * Shared dashboard grid — the single source of truth for widget layout.
 *
 * Both the edit page (PreviewPanel) and the view page (DashboardView) render
 * through this component so they produce pixel-identical grid output:
 *  - Content-aware height via `suggestHeight` (tables grow, KPIs shrink).
 *  - `compactType="vertical"` for gap-free stacking.
 *  - `minW:4, minH:3` floor to prevent collapsed cells.
 *  - Identical margins, rowHeight, cols, transforms.
 *
 * Edit vs read-only is controlled purely by props — no branching in the layout
 * math, so the two pages can never drift.
 */
export default function DashboardGrid({
  widgets,
  readonly = true,
  onLayoutChange,
  onResizeStop,
  onDragStart,
  onDragStop,
  onResizeStart,
  renderCard,
}: DashboardGridProps) {
  // ---- Build react-grid-layout layout array ----
  // Use the persisted layout as-is. Height auto-tuning (suggestHeight) is the
  // edit page's job at hydration time (DashboardEdit stores the tuned h); the
  // grid must NOT recompute h at render time — doing so desyncs h from the
  // persisted y values, and RGL's compactType="vertical" then corrupts the
  // layout (items get pushed to y=3612+). Both edit and view pass through
  // here, so both honor whatever h the store/schema carries.
  const layout: Layout[] = useMemo(
    () =>
      widgets.map((w) => {
        const l = w.layout || { x: 0, y: 0, w: 12, h: 6 };
        return {
          i: w.id,
          x: l.x,
          y: l.y,
          w: l.w,
          h: l.h,
          minW: 4,
          minH: 3,
          static: readonly,
        };
      }),
    [widgets, readonly],
  );

  return (
    <>
      <ResponsiveGridLayout
        layout={layout}
        cols={COLS}
        rowHeight={ROW_HEIGHT}
        onLayoutChange={onLayoutChange}
        onDragStart={onDragStart}
        onDragStop={onDragStop}
        onResizeStart={onResizeStart}
        onResizeStop={(_layout, oldItem) => {
          onDragStop?.();
          if (oldItem) onResizeStop?.(oldItem);
        }}
        compactType={COMPACT_TYPE}
        isDraggable={!readonly}
        isResizable={!readonly}
        draggableHandle=".widget-header"
        margin={[12, 12]}
        useCSSTransforms={true}
      >
        {widgets.map((w) => (
          <div key={w.id} style={gridItemStyle}>
          {renderCard
            ? renderCard(w)
            : <WidgetCard widget={w as Widget} editable={!readonly} onTitleChange={() => {}} onRemove={() => {}} />}
          </div>
        ))}
      </ResponsiveGridLayout>
      {/* keyframes consumed by WidgetCard's `animation: 'widgetFadeIn ...'`.
          Hoisted here (the grid owner) so it's emitted exactly once per page. */}
      <style>{widgetFadeInCss}</style>
    </>
  );
}

// ---------------------------------------------------------------------------
// CSS for entry animation (WidgetCard references this keyframe)
// ---------------------------------------------------------------------------

const widgetFadeInCss = `
@keyframes widgetFadeIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
`;

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const gridItemStyle: React.CSSProperties = {
  // react-grid-layout positions each child via absolute positioning; the child
  // must explicitly fill the cell (100% × 100%) or WidgetCard's height:100%
  // collapses to auto and the chart area underflows.
  width: '100%',
  height: '100%',
};
