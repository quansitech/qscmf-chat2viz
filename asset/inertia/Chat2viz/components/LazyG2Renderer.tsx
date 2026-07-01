import { useState, useEffect, useRef } from 'react';
import { Skeleton } from 'antd';
import G2Renderer from '../G2Renderer';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface LazyG2RendererProps {
  /** G2 v5 spec object. */
  spec: Record<string, unknown>;
  /** Chart data array. */
  data?: Record<string, unknown>[];
  /** Container width (px). */
  width?: number;
  /** Container height (px). */
  height?: number;
  /** Extra CSS class name. */
  className?: string;
  /**
   * Enable IntersectionObserver-based lazy mounting. Defaults to false.
   *
   * Rationale: dashboard widgets live inside an `overflow:auto` scroll container,
   * NOT the viewport. The browser's IntersectionObserver with `root:null`
   * (viewport) misjudges off-screen-but-in-scroll-container cards as invisible,
   * leaving them stuck on the skeleton forever. Since dashboards typically have
   * a handful of widgets (single digits), eager rendering is both simpler and
   * correct. Lazy mounting is opt-in for future high-density scenarios.
   */
  lazy?: boolean;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function LazyG2Renderer({
  spec,
  data,
  width,
  height,
  className,
  lazy = false,
}: LazyG2RendererProps) {
  const [isVisible, setIsVisible] = useState(!lazy);
  const containerRef = useRef<HTMLDivElement>(null);
  const observerRef = useRef<IntersectionObserver | null>(null);

  useEffect(() => {
    // Eager mode — nothing to observe, render immediately.
    if (!lazy) return;

    const el = containerRef.current;
    if (!el) return;

    // Find the nearest scrollable ancestor to use as the IntersectionObserver
    // root. Falling back to null (viewport) is only correct when the container
    // chain has no intermediate scroll element — which is not the case for the
    // dashboard grid (it scrolls inside an overflow:auto panel).
    let root: Element | null = null;
    let node: Element | null = el.parentElement;
    while (node) {
      const style = getComputedStyle(node);
      if (/(auto|scroll|overlay)/.test(style.overflowY)) {
        root = node;
        break;
      }
      node = node.parentElement;
    }

    observerRef.current = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsVisible(true);
          // Stop observing once visible — no oscillation
          if (observerRef.current) {
            observerRef.current.disconnect();
            observerRef.current = null;
          }
        }
      },
      { root, rootMargin: '200px' },
    );

    observerRef.current.observe(el);

    return () => {
      if (observerRef.current) {
        observerRef.current.disconnect();
        observerRef.current = null;
      }
    };
  }, [lazy]);

  return (
    <div ref={containerRef} className={className} style={{ minHeight: height ?? 300 }}>
      {isVisible ? (
        <G2Renderer spec={spec} data={data} width={width} height={height} />
      ) : (
        <div style={{ padding: '24px 16px' }}>
          <Skeleton active paragraph={{ rows: 4 }} />
        </div>
      )}
    </div>
  );
}
