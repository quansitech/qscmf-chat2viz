/**
 * Type stubs for @inertiajs/react.
 *
 * This package is NOT installed in the v13 build — the webpack alias
 * replaces './adapters' imports with smarty.ts at build time.
 * These declarations exist solely so `tsc --noEmit` passes without
 * requiring the actual @inertiajs/react package.
 */
declare module '@inertiajs/react' {
  import { ReactNode } from 'react';

  interface PageProps {
    [key: string]: unknown;
  }

  interface Page<T = PageProps> {
    props: T;
  }

  export function usePage<T = PageProps>(): Page<T>;

  export const router: {
    visit(url: string, options?: Record<string, unknown>): void;
  };
}
