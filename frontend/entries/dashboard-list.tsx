import { createRoot } from 'react-dom/client';
import DashboardList from '../../asset/inertia/Chat2viz/DashboardList';

const mountEl = document.getElementById('dashboard-list-app');
if (mountEl) {
  const root = createRoot(mountEl);
  root.render(<DashboardList />);
}
