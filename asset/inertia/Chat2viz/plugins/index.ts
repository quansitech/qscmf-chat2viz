/**
 * Plugin registration side-effect module.
 *
 * Importing this module registers the five contract widget plugins
 * (g2_chart / stat_card / data_table / map / markdown). `filter` is NOT
 * registered (it is a slicer, rendered by <SlicerPanel>, contract §2.7 line 199).
 *
 * Import this module once at the application entry point (dashboard-list /
 * dashboard-edit / dashboard-view) so the registry is populated before any
 * <PluginRenderer> mounts.
 */
import { registerPlugin, setMarkdownFallback } from './registry';
import MarkdownPlugin from './MarkdownPlugin';
import G2ChartPlugin from './G2ChartPlugin';
import DataTablePlugin from './DataTablePlugin';
import StatCardPlugin from './StatCardPlugin';
import MapPlugin from './MapPlugin';

// markdown is the universal fallback — register it first and set the fallback.
registerPlugin('markdown', { component: MarkdownPlugin });
setMarkdownFallback({ component: MarkdownPlugin });

registerPlugin('g2_chart', { component: G2ChartPlugin });
registerPlugin('data_table', { component: DataTablePlugin });
registerPlugin('stat_card', { component: StatCardPlugin });
registerPlugin('map', { component: MapPlugin });

// The set of registered plugin_types is exactly the five above.
export const REGISTERED_PLUGIN_TYPES = ['g2_chart', 'stat_card', 'data_table', 'map', 'markdown'] as const;
