import { Alert } from 'antd';
import PluginErrorBoundary from './PluginErrorBoundary';
import { getPlugin, UNKNOWN_PLUGIN_RENDERS_ERROR } from '../plugins/registry';
import type { PluginProps } from '../plugins/registry';

/**
 * PluginRenderer — the single dispatch point for widget rendering.
 *
 * Looks up `getPlugin(widget.plugin_type)` and renders its component wrapped
 * in a <PluginErrorBoundary>. Contains ZERO conditional logic keyed on a
 * specific plugin_type value (no `if type==='table'` / `switch(type)`).
 *
 * Unknown plugin_type handling is **phase-aware** (contract §2.7 lines 201-203):
 *  - Phase 0-2 (default, `UNKNOWN_PLUGIN_RENDERS_ERROR=true`): an unregistered
 *    plugin_type renders an explicit error widget naming the unknown type and
 *    prompting regeneration (no silent markdown — silent degradation would
 *    swallow LLM production errors during the error-and-rewind phase).
 *  - Phase 3+: an unregistered plugin_type silently degrades to the markdown
 *    fallback (never blank).
 *
 * The set of "known" types is read from the registry at render time
 * (registered plugins). This avoids duplicating the type list here.
 */
import { listRegisteredPlugins } from '../plugins/registry';

export interface PluginRendererProps extends PluginProps {
  /** Override for testing the phase behavior; defaults to the build constant. */
  unknownRendersError?: boolean;
}

export default function PluginRenderer({
  widget,
  data,
  total,
  truncated,
  onDrill,
  unknownRendersError = UNKNOWN_PLUGIN_RENDERS_ERROR,
}: PluginRendererProps) {
  const pluginType = widget.plugin_type;
  const isKnown = typeof pluginType === 'string' && pluginType !== '' && listRegisteredPlugins().includes(pluginType);

  // Phase-aware unknown-type handling: Phase 0-2 → explicit error widget.
  if (!isKnown && unknownRendersError) {
    return (
      <div style={{ padding: 12, height: '100%', display: 'flex', alignItems: 'center' }}>
        <Alert
          type="error"
          showIcon
          message={`未知的组件类型：${pluginType ?? '（缺失）'}`}
          description="该组件类型尚未注册，请尝试重新生成或调整描述。"
        />
      </div>
    );
  }

  // Phase 3+ OR a known type → registry dispatch (markdown fallback for unknown).
  const def = getPlugin(pluginType);
  const Component = def.component;

  return (
    <PluginErrorBoundary widgetId={widget.widget_id}>
      <Component
        widget={widget}
        data={data}
        {...(total !== undefined ? { total } : {})}
        {...(truncated !== undefined ? { truncated } : {})}
        {...(onDrill ? { onDrill } : {})}
      />
    </PluginErrorBoundary>
  );
}
