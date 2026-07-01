import { Component, type ErrorInfo, type ReactNode } from 'react';

/**
 * ErrorBoundary — catches render-time exceptions in the dashboard SPA subtree
 * and shows a user-facing fallback instead of a blank admin page.
 *
 * Without this, a single throw in DashboardEdit/DashboardView/DashboardList
 * (e.g. malformed __PAGE_DATA__ from a corrupt dashboard row) blank-screens
 * the entire admin page with no feedback. The boundary isolates the crash to
 * the chat2viz mount point.
 *
 * (fix-frontend-review-defects task 10.1-10.4)
 */
interface Props {
  children: ReactNode;
}
interface State {
  hasError: boolean;
  message: string;
}

export default class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, message: '' };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, message: error.message || '未知错误' };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // eslint-disable-next-line no-console
    console.error('[chat2viz] ErrorBoundary caught:', error, info.componentStack);
  }

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div style={{ padding: 24, fontFamily: 'sans-serif', color: '#d4380d' }}>
          <h3 style={{ margin: '0 0 8px' }}>仪表盘渲染失败</h3>
          <p style={{ margin: 0, fontSize: 13, wordBreak: 'break-all' }}>
            {this.state.message}
          </p>
          <p style={{ margin: '8px 0 0', fontSize: 12, color: '#999' }}>
            请刷新页面重试，或检查仪表盘数据是否完整。
          </p>
        </div>
      );
    }
    return this.props.children;
  }
}
