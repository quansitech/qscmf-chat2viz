import type { Widget } from '../store/dashboardStore';

/**
 * Serialize current widget state into the dashboard schema payload
 * sent to the server for save/publish operations.
 */
export function buildSchema(widgets: Record<string, Widget>) {
  const widgetList = Object.values(widgets);
  return {
    widgets: widgetList.map((w) => ({
      id: w.id,
      title: w.title,
      g2_spec: w.g2_spec,
      layout: w.layout,
      sql: w.sql,
      refreshInterval: w.refreshInterval,
    })),
  };
}
