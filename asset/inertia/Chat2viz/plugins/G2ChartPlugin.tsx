import LazyG2Renderer from '../components/LazyG2Renderer';
import { hasChartSpec } from '../store/dashboardStore';
import { Empty } from 'antd';
import type { PluginProps } from './registry';

/**
 * G2ChartPlugin — wraps the existing LazyG2Renderer for `plugin_type: 'g2_chart'`.
 *
 * The plugin_spec is a G2 v5 spec (object with `type`/`mark`/`encode`, no
 * inline `data`). Data is injected from the resolved render cache at render
 * time (mergedSpec). The existing G2 async-noise guard
 * (`unhandledrejection` swallow for `g2.min.js`) and `makeRenderCatchHandler`
 * destroy-on-reject behavior live inside G2Renderer and are preserved.
 *
 * Invalid/missing spec → empty placeholder with minHeight:300, no chart
 * creation attempted.
 */
export default function G2ChartPlugin({ widget, data }: PluginProps) {
  const spec = widget.plugin_spec ?? {};
  if (!hasChartSpec(spec)) {
    return (
      <div style={{ minHeight: 300, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Empty description="图表配置无效" image={Empty.PRESENTED_IMAGE_SIMPLE} />
      </div>
    );
  }
  const mergedSpec = { ...spec, data: Array.isArray(data) ? data : [] };
  return <LazyG2Renderer spec={mergedSpec} data={Array.isArray(data) ? data : []} />;
}
