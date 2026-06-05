import React, { useRef, useEffect } from 'react';
import { Chart } from '@antv/g2';

interface G2RendererProps {
  spec: Record<string, unknown> | null | undefined;
}

const G2Renderer: React.FC<G2RendererProps> = ({ spec }) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const chartRef = useRef<Chart | null>(null);

  useEffect(() => {
    if (!spec || !spec.type || !containerRef.current) {
      return;
    }

    chartRef.current?.destroy();
    const chart = new Chart({
      container: containerRef.current,
      autoFit: true,
    });
    chart.options(spec).render();
    chartRef.current = chart;

    return () => {
      chart.destroy();
      chartRef.current = null;
    };
  }, [spec]);

  if (!spec || !spec.type) {
    return <div style={{ minHeight: 300 }} />;
  }

  return <div ref={containerRef} style={{ minHeight: 300 }} />;
};

export default G2Renderer;
