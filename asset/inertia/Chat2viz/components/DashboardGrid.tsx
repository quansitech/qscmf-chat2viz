import { useMemo, type ReactNode } from 'react';
import RGL, { WidthProvider, Layout } from 'react-grid-layout';
import 'react-grid-layout/css/styles.css';
import 'react-grid-layout/css/react-resizable.css';
import WidgetCard from './WidgetCard';
import { suggestHeight } from '../utils/suggestHeight';
import type { WidgetSpec, LayoutSlot } from '../types/dsl';
import type { WidgetCacheEntry as StoreCacheEntry } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// WidthProvider wraps RGL to auto-track container width
// ---------------------------------------------------------------------------

const ResponsiveGridLayout = WidthProvider(RGL);

// ---------------------------------------------------------------------------
// Constants — shared by edit page (PreviewPanel) and view page (DashboardView).
// ---------------------------------------------------------------------------

export const COLS = 24;
export const ROW_HEIGHT = 60;
export const COMPACT_TYPE: ('vertical' | 'horizontal' | null) = 'vertical';
export const MIN_W = 4;
export const MIN_H = 3;

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/**
 * A widget prepared for grid rendering: the DSL WidgetSpec plus its resolved
 * slot and render-side cache entry. Both the edit-page store path and the
 * view-page HTTP-fetch path produce this shape.
 */
export interface GridWidget {
  widget: WidgetSpec;
  cache?: StoreCacheEntry;
  sql?: string;
}

interface DashboardGridProps {
  /** Widgets to lay out, each carrying its slot (embedded by replaceDSL). */
  widgets: GridWidget[];
  /**
   * Regions to render, in order. Defaults to the conventional
   * ["header","content","footer"] subset present in the widgets' slot.region.
   */
  regions?: string[];
  /** Read-only mode (default: true). */
  readonly?: boolean;
  /** Edit-mode layout-change callback. */
  onLayoutChange?: (layout: Layout[]) => void;
  /** Edit-mode resize-stop callback (marks userSized). */
  onResizeStop?: (oldItem: Layout) => void;
  onDragStart?: () => void;
  onDragStop?: () => void;
  onResizeStart?: () => void;
  /** Card render override (view page wraps WidgetCard with react-query). */
  renderCard?: (gw: GridWidget) => ReactNode;
}

/**
 * Extract the slot from a widget (replaceDSL embeds it under a non-standard
 * `layout` key to avoid widening the contract WidgetSpec type).
 */
function getSlot(gw: GridWidget): LayoutSlot | undefined {
  const wl = (gw.widget as WidgetSpec & { layout?: LayoutSlot }).layout;
  return wl;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

/**
 * Region-grouped dashboard grid (contract §2.2).
 *
 * Renders one independent `react-grid-layout` per semantic region
 * (`header`/`content`/`footer`), in `regions` order. Each region's grid
 * contains only the widgets whose `slot.region` matches. Slots whose region is
 * not in `regions` are dropped with a `console.warn` (never crash).
 *
 * Intra-region grid constants (cols=24, rowHeight=60, minW=4, minH=3,
 * compactType=vertical, margin=[12,12], userSized freeze, content-aware
 * auto-height) are preserved so behavior is identical to the legacy single-grid.
 */
export default function DashboardGrid({
  widgets,
  regions,
  readonly = true,
  onLayoutChange,
  onResizeStop,
  onDragStart,
  onDragStop,
  onResizeStart,
  renderCard,
}: DashboardGridProps) {
  // Resolve the region order: explicit prop → union of slot.regions in
  // first-seen order (preserves declaration order).
  const resolvedRegions = useMemo(() => {
    if (regions && regions.length > 0) return regions;
    const seen = new Set<string>();
    const ordered: string[] = [];
    for (const gw of widgets) {
      const slot = getSlot(gw);
      const r = slot?.region ?? gw.widget.region ?? 'content';
      if (!seen.has(r)) {
        seen.add(r);
        ordered.push(r);
      }
    }
    return ordered.length > 0 ? ordered : ['content'];
  }, [regions, widgets]);

  // Group widgets by region; warn + drop slots whose region is unlisted.
  const byRegion = useMemo(() => {
    const regionSet = new Set(resolvedRegions);
    const groups = new Map<string, GridWidget[]>();
    for (const r of resolvedRegions) groups.set(r, []);
    for (const gw of widgets) {
      const slot = getSlot(gw);
      const r = slot?.region ?? gw.widget.region ?? 'content';
      if (!regionSet.has(r)) {
        // eslint-disable-next-line no-console
        console.warn(`[chat2viz] widget ${gw.widget.widget_id} has region "${r}" not in regions ${JSON.stringify(resolvedRegions)} — dropped`);
        continue;
      }
      groups.get(r)?.push(gw);
    }
    return groups;
  }, [widgets, resolvedRegions]);

  return (
    <>
      {resolvedRegions.map((region) => {
        const regionWidgets = byRegion.get(region) ?? [];
        if (regionWidgets.length === 0) return null;

        const layout: Layout[] = regionWidgets.map((gw) => {
          const slot = getSlot(gw);
          const l = slot ?? { widget_id: gw.widget.widget_id, region, x: 0, y: 0, w: 12, h: 6 };
          const userSized = (l as LayoutSlot & { userSized?: boolean }).userSized === true;
          if (userSized) {
            return { i: gw.widget.widget_id, x: l.x, y: l.y, w: l.w, h: l.h, minW: MIN_W, minH: MIN_H, static: readonly };
          }
          const h = suggestHeight({
            pluginType: gw.widget.plugin_type,
            data: gw.cache?.rows ?? [],
          });
          return { i: gw.widget.widget_id, x: l.x, y: 0, w: l.w, h, minW: MIN_W, minH: MIN_H, static: readonly };
        });

        return (
          <ResponsiveGridLayout
            key={region}
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
            {regionWidgets.map((gw) => (
              <div key={gw.widget.widget_id} style={gridItemStyle}>
                {renderCard
                  ? renderCard(gw)
                  : (
                    <WidgetCard
                      widget={gw.widget}
                      cache={gw.cache}
                      editable={!readonly}
                      sql={gw.sql}
                      onTitleChange={() => {}}
                      onRemove={() => {}}
                    />
                  )}
              </div>
            ))}
          </ResponsiveGridLayout>
        );
      })}
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

const gridItemStyle: React.CSSProperties = {
  width: '100%',
  height: '100%',
};
