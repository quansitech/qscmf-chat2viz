import { createRoot } from 'react-dom/client';
import DashboardEdit from '../../asset/inertia/Chat2viz/DashboardEdit';

const mountEl = document.getElementById('dashboard-edit-app');
if (mountEl) {
  const root = createRoot(mountEl);
  root.render(<DashboardEdit />);
}
