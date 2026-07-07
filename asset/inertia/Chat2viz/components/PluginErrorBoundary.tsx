import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Alert } from 'antd';

/**
 * PluginErrorBoundary — per-widget React error boundary isolating a single
 * plugin's render failure from the whole dashboard tree.
 *
 * Mirrors the SPA-level `ErrorBoundary` pattern but scoped to one widget:
 * a malformed plugin_spec (e.g. an unknown G2 mark) renders this boundary's
 * fallback card (naming the widget_id + short error message) and leaves all
 * sibling plugins rendered. Errors are forwarded to `console.error`
 * (NOT swallowed).
 *
 * Layered UNDER the SPA-level ErrorBoundary: a structural failure
 * (bad __PAGE_DATA__) still gets the friendly full-page fallback; a single
 * plugin failure gets this per-widget card.
 */
interface Props {
  widgetId: string;
  children: ReactNode;
}
interface State {
  hasError: boolean;
  message: string;
}

export default class PluginErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, message: '' };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, message: error.message || '渲染失败' };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // eslint-disable-next-line no-console
    console.error(
      `[chat2viz] PluginErrorBoundary (widget ${this.props.widgetId}) caught:`,
      error,
      info.componentStack,
    );
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 12, height: '100%', display: 'flex', alignItems: 'center' }}>
          <Alert
            type="error"
            showIcon
            message={`组件 ${this.props.widgetId} 渲染失败`}
            description={this.state.message}
          />
        </div>
      );
    }
    return this.props.children;
  }
}
