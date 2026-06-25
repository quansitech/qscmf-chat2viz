import { createRoot } from 'react-dom/client';
import DashboardList from '../../asset/inertia/Chat2viz/DashboardList';
import ErrorBoundary from './ErrorBoundary';

const mountEl = document.getElementById('dashboard-list-app');
if (mountEl) {
  const root = createRoot(mountEl);
  root.render(
    <ErrorBoundary>
      <DashboardList />
    </ErrorBoundary>
  );
}
