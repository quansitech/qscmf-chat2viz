import type { WidgetLayout } from '../store/dashboardStore';
import type { Widget } from '../store/dashboardStore';

/**
 * Compute the next available slot in a 24-column grid (two 12-column lanes).
 * Places the new widget at the bottom of the shorter column.
 */
export function computeNextSlot(
  existingWidgets: Record<string, Widget>,
  w = 12,
  h = 6,
): WidgetLayout {
  const widgets = Object.values(existingWidgets);
  if (widgets.length === 0) return { x: 0, y: 0, w, h };

  let leftBottom = 0;
  let rightBottom = 0;

  for (const widget of widgets) {
    const bottom = widget.layout.y + widget.layout.h;
    const touchesLeft = widget.layout.x < 12;
    const touchesRight = widget.layout.x + widget.layout.w > 12;

    if (touchesLeft) leftBottom = Math.max(leftBottom, bottom);
    if (touchesRight) rightBottom = Math.max(rightBottom, bottom);
  }

  return leftBottom <= rightBottom
    ? { x: 0, y: leftBottom, w, h }
    : { x: 12, y: rightBottom, w, h };
}
