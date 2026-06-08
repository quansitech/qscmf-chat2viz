/**
 * Inertia adapter — v14/v15 builds.
 *
 * Uses @inertiajs/react hooks to read page props and navigate.
 * The host Vite pipeline auto-discovers Pages/Chat2viz/*.tsx
 * and Inertia::render() provides the props server-side.
 */
import { usePage, router } from '@inertiajs/react';

export function getPageProps<T>(): T {
  return usePage<T>().props as T;
}

export function navigate(url: string): void {
  router.visit(url);
}
