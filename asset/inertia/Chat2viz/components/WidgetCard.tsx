import { useState, useCallback } from 'react';
import { Alert, Collapse, Popconfirm, Skeleton, Spin, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, LoadingOutlined, ReloadOutlined } from '@ant-design/icons';
import LazyG2Renderer from './LazyG2Renderer';
import WidgetTable from './WidgetTable';
import { hasChartSpec } from '../store/dashboardStore';
import type { Widget } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface WidgetCardProps {
  widget: Widget;
  onTitleChange: (widgetId: string, title: string) => void;
  onRemove: (widgetId: string) => void;
  onRefresh?: (widgetId: string) => void;
  onRegenerate?: (widgetId: string) => void;
  /** True while this widget's data is being re-fetched on manual refresh. */
  refreshing?: boolean;
  /** Feature flag: show the "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
}

/**
 * Resolve the effective render status. Widgets without a `status` field
 * default to 'chart' (defensive fallback for any persisted widget that
 * predates the status field or arrived via a non-standard path).
 */
function effectiveStatus(widget: Widget): 'loading' | 'error' | 'chart' {
  return widget.status ?? 'chart';
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function WidgetCard({ widget, onTitleChange, onRemove, onRefresh, onRegenerate, refreshing = false, showSql = false }: WidgetCardProps) {
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState(widget.title);

  const handleTitleDoubleClick = useCallback(() => {
    setTitleDraft(widget.title);
    setIsEditingTitle(true);
  }, [widget.title]);

  const handleTitleConfirm = useCallback(() => {
    const trimmed = titleDraft.trim();
    if (trimmed && trimmed !== widget.title) {
      onTitleChange(widget.id, trimmed);
    }
    setIsEditingTitle(false);
  }, [titleDraft, widget.title, widget.id, onTitleChange]);

  const handleTitleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === 'Enter') {
        handleTitleConfirm();
      } else if (e.key === 'Escape') {
        setIsEditingTitle(false);
      }
    },
    [handleTitleConfirm],
  );

  // Effective render status — legacy widgets without a status field default to 'chart'.
  const status = effectiveStatus(widget);

  // Unified chart-existence judgment (DESIGN_BASIS #7): type OR mark OR children.
  const hasSpec = hasChartSpec(widget.g2_spec);

  // G2 v5 has no `composition.table` mark — route table specs to the native
  // renderer to avoid "Unknown Component" + a blank widget.
  const isTable = widget.g2_spec?.type === 'table';

  // Shallow-merge config + data at render time. g2_spec source is NOT mutated
  // — data is injected from the widget's own data field (store.widgets[id].data),
  // which is the sole data source per the dashboard-schema contract. After store
  // normalization widget.data is always a bare array; the Array.isArray guard
  // is defensive for any non-store hydration path.
  const mergedSpec = widget.g2_spec
    ? { ...widget.g2_spec, data: Array.isArray(widget.data) ? widget.data : [] }
    : widget.g2_spec;

  return (
    <div className="widget-card" style={styles.card}>
      {/* ---- Header ---- */}
      <div className="widget-header" style={styles.header}>
        <div style={styles.titleArea}>
          {isEditingTitle ? (
            <input
              value={titleDraft}
              onChange={(e) => setTitleDraft(e.target.value)}
              onBlur={handleTitleConfirm}
              onKeyDown={handleTitleKeyDown}
              autoFocus
              style={styles.titleInput}
            />
          ) : (
            <Typography.Text
              strong
              onDoubleClick={handleTitleDoubleClick}
              style={{ cursor: 'pointer', flex: 1 }}
            >
              {widget.title || '未命名图表'}
              <EditOutlined style={{ marginLeft: 6, fontSize: 11, opacity: 0.5 }} />
            </Typography.Text>
          )}
        </div>
        <div style={styles.headerActions}>
          {onRefresh && status === 'chart' && (
            <Tooltip title="刷新该图表数据">
              {refreshing ? (
                <LoadingOutlined style={styles.iconBtn} spin />
              ) : (
                <ReloadOutlined
                  onClick={() => onRefresh(widget.id)}
                  style={styles.iconBtn}
                />
              )}
            </Tooltip>
          )}
          <Popconfirm
            title="确定移除该图表？"
            onConfirm={() => onRemove(widget.id)}
            okText="移除"
            cancelText="取消"
          >
            <DeleteOutlined style={styles.iconBtn} />
          </Popconfirm>
        </div>
      </div>

      {/* ---- Chart Area — three render branches by status ---- */}
      <div style={styles.chartArea}>
        {status === 'loading' && (
          // DASHBOARD_INIT placeholder: skeleton frame (no spec yet)
          <div style={styles.emptyChart}>
            <Skeleton active paragraph={{ rows: 4 }} />
          </div>
        )}

        {status === 'error' && (
          // WIDGET_ERROR local degradation: error card with regenerate entry.
          // Local widget error MUST NOT surface as the global ChatPanel Alert.
          <div style={styles.errorCard}>
            <Alert
              type="error"
              showIcon
              message="图表生成失败"
              description={widget.sql ? undefined : '该图表数据加载失败，请尝试重新生成。'}
              style={{ marginBottom: 8 }}
            />
            {onRegenerate && (
              <Typography.Link onClick={() => onRegenerate(widget.id)}>
                <ReloadOutlined style={{ marginRight: 4 }} />
                重新生成
              </Typography.Link>
            )}
          </div>
        )}

        {status === 'chart' && (
          <>
            {!hasSpec && (
              <div style={styles.emptyChart}>
                <Spin tip="加载图表中..." />
              </div>
            )}
            {hasSpec && mergedSpec && isTable && (
              <WidgetTable
                spec={widget.g2_spec as Record<string, unknown>}
                data={Array.isArray(widget.data) ? widget.data : []}
              />
            )}
            {hasSpec && mergedSpec && !isTable && (
              <LazyG2Renderer
                spec={mergedSpec as Record<string, unknown>}
                data={Array.isArray(widget.data) ? widget.data : []}
              />
            )}
          </>
        )}
      </div>

      {/* ---- Truncation notice (read-only, total reported by Python) ---- */}
      {widget.truncated && typeof widget.total === 'number' && (
        <div style={styles.truncationNotice}>
          <Typography.Text type="warning" style={{ fontSize: 12 }}>
            已截断，共 {widget.total} 行
          </Typography.Text>
        </div>
      )}

      {/* ---- Footer ---- */}
      <div style={styles.footer}>
        {showSql && widget.sql ? (
          <Collapse
            ghost
            size="small"
            items={[
              {
                key: 'sql',
                label: (
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    查询语句
                  </Typography.Text>
                ),
                children: (
                  <pre style={styles.sqlBlock}>{widget.sql}</pre>
                ),
              },
            ]}
          />
        ) : (
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            数据源
          </Typography.Text>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  card: {
    background: '#fff',
    borderRadius: 8,
    border: '1px solid #f0f0f0',
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    overflow: 'hidden',
    transition: 'opacity 0.3s ease-in-out',
    animation: 'widgetFadeIn 0.3s ease-in-out',
  },
  header: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '8px 12px',
    borderBottom: '1px solid #f5f5f5',
    cursor: 'grab',
    userSelect: 'none',
    touchAction: 'none',
  },
  titleArea: {
    display: 'flex',
    alignItems: 'center',
    flex: 1,
    minWidth: 0,
  },
  titleInput: {
    border: '1px solid #d9d9d9',
    borderRadius: 4,
    padding: '2px 6px',
    fontSize: 13,
    width: '100%',
    outline: 'none',
  },
  headerActions: {
    display: 'flex',
    gap: 8,
    marginLeft: 8,
  },
  iconBtn: {
    cursor: 'pointer',
    fontSize: 14,
    color: '#999',
  },
  chartArea: {
    flex: 1,
    minHeight: 200,
    position: 'relative',
  },
  emptyChart: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: 200,
  },
  errorCard: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: 200,
    padding: 12,
  },
  truncationNotice: {
    padding: '4px 12px',
    background: '#fffbe6',
    borderBottom: '1px solid #fff1b8',
  },
  footer: {
    borderTop: '1px solid #f5f5f5',
    padding: '4px 12px',
  },
  sqlBlock: {
    background: '#fafafa',
    padding: 8,
    borderRadius: 4,
    fontSize: 12,
    overflow: 'auto',
    margin: 0,
    maxHeight: 120,
  },
};
