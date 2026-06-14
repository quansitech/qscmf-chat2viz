/**
 * Dashboard route bases.
 *
 * The dashboard controllers are split by sensitivity:
 *   - ADMIN_BASE  (/admin/Chat2VizDashboard)   -> CRUD / editor, framework-authed
 *   - PUBLIC_BASE (/extends/Chat2VizDashboard) -> published view + widget data
 *
 * The admin module is in BACKEND_MODULE, so QsController enforces login there;
 * the extends module is intentionally public. Call sites must pick the base that
 * matches the controller owning the action, otherwise a CRUD call would hit the
 * public controller (404) or a public call would demand a login.
 */
export const ADMIN_BASE = '/admin/Chat2VizDashboard';
export const PUBLIC_BASE = '/extends/Chat2VizDashboard';
