/**
 * Inertia adapter — v14/v15 builds.
 *
 * Uses @inertiajs/react hooks to read page props and navigate.
 * The host Vite pipeline auto-discovers Pages/Chat2viz/*.tsx
 * and Inertia::render() provides the props server-side.
 */
/* eslint-disable react-hooks/rules-of-hooks -- This adapter swaps in ONLY for
   v14/v15 builds (via webpack alias; the v13 build uses ./smarty.ts). It is
   dead code under the current pipeline. getPageProps() wraps usePage() to
   share a call-site signature with the smarty adapter; the hooks rule flags
   the lowercase wrapper name. Suppress rather than mutate unused code. */
import { usePage, router } from '@inertiajs/react';

export function getPageProps<T>(): T {
  return usePage<T>().props as T;
}

export function navigate(url: string): void {
  router.visit(url);
}
