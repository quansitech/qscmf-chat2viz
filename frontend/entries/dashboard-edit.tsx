import { createRoot } from 'react-dom/client';
import DashboardEdit from '../../asset/inertia/Chat2viz/DashboardEdit';
import ErrorBoundary from './ErrorBoundary';

const mountEl = document.getElementById('dashboard-edit-app');
if (mountEl) {
  const root = createRoot(mountEl);
  root.render(
    <ErrorBoundary>
      <DashboardEdit />
    </ErrorBoundary>
  );
}
