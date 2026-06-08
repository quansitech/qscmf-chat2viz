/**
 * Adapter entry point.
 *
 * Default: re-exports the inertia adapter (used by v14/v15 Vite builds).
 * For v13, the Webpack config in frontend/webpack.config.js uses
 * resolve.alias with './adapters$' to redirect this import to smarty.ts,
 * so the same source code works across all versions without modification.
 */
export { getPageProps, navigate } from './inertia';
