import { createRoot } from 'react-dom/client';
import DashboardView from '../../asset/inertia/Chat2viz/DashboardView';
import ErrorBoundary from './ErrorBoundary';

const mountEl = document.getElementById('dashboard-view-app');
if (mountEl) {
  const root = createRoot(mountEl);
  root.render(
    <ErrorBoundary>
      <DashboardView />
    </ErrorBoundary>
  );
}
