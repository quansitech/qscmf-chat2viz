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
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function LazyG2Renderer({ spec, data, width, height, className }: LazyG2RendererProps) {
  const [isVisible, setIsVisible] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const observerRef = useRef<IntersectionObserver | null>(null);

  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;

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
      { rootMargin: '200px' },
    );

    observerRef.current.observe(el);

    return () => {
      if (observerRef.current) {
        observerRef.current.disconnect();
        observerRef.current = null;
      }
    };
  }, []);

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
