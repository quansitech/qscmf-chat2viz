import type { WidgetSpec, LayoutSlot } from '../types/dsl';

/**
 * Compute the next available slot in a 24-column grid (two 12-column lanes).
 * Places the new widget at the bottom of the shorter column.
 *
 * Reads slot coordinates from the DSL widget's embedded `layout` (set by
 * replaceDSL from `dsl.layout.slots`).
 */
export function computeNextSlot(
  existingWidgets: Record<string, WidgetSpec>,
  w = 12,
  h = 6,
): LayoutSlot {
  const widgets = Object.values(existingWidgets);
  if (widgets.length === 0) return { widget_id: '', region: 'content', x: 0, y: 0, w, h };

  let leftBottom = 0;
  let rightBottom = 0;

  for (const widget of widgets) {
    const wl = (widget as WidgetSpec & { layout?: LayoutSlot }).layout;
    if (!wl) continue;
    const bottom = wl.y + wl.h;
    const touchesLeft = wl.x < 12;
    const touchesRight = wl.x + wl.w > 12;

    if (touchesLeft) leftBottom = Math.max(leftBottom, bottom);
    if (touchesRight) rightBottom = Math.max(rightBottom, bottom);
  }

  return {
    widget_id: '',
    region: 'content',
    x: leftBottom <= rightBottom ? 0 : 12,
    y: leftBottom <= rightBottom ? leftBottom : rightBottom,
    w,
    h,
  };
}
