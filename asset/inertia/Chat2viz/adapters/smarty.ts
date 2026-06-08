/**
 * Smarty adapter — v13 builds.
 *
 * Reads page data from window.__PAGE_DATA__ injected by the Smarty
 * template via {:json_encode($data)}. Navigation uses full page loads
 * since there is no client-side router in the v13 Smarty environment.
 */
export function getPageProps<T>(): T {
  const data = (window as any).__PAGE_DATA__;
  if (!data) {
    throw new Error('[Chat2Viz] window.__PAGE_DATA__ is not set. Check Smarty template rendering.');
  }
  return (data || {}) as T;
}

export function navigate(url: string): void {
  window.location.href = url;
}
