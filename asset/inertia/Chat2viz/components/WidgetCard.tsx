import { useState, useCallback } from 'react';
import { Collapse, Popconfirm, Spin, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, ReloadOutlined } from '@ant-design/icons';
import LazyG2Renderer from './LazyG2Renderer';
import type { Widget } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface WidgetCardProps {
  widget: Widget;
  onTitleChange: (widgetId: string, title: string) => void;
  onRemove: (widgetId: string) => void;
  onRefresh?: (widgetId: string) => void;
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function WidgetCard({ widget, onTitleChange, onRemove, onRefresh }: WidgetCardProps) {
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

  const hasSpec = widget.g2_spec && (
    widget.g2_spec.type || (Array.isArray((widget.g2_spec as any).children) && (widget.g2_spec as any).children.length > 0)
  );

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
          {onRefresh && (
            <ReloadOutlined
              onClick={() => onRefresh(widget.id)}
              style={styles.iconBtn}
            />
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

      {/* ---- Chart Area ---- */}
      <div style={styles.chartArea}>
        {!hasSpec && (
          <div style={styles.emptyChart}>
            <Spin tip="加载图表中..." />
          </div>
        )}
        {hasSpec && widget.g2_spec && (
          <LazyG2Renderer
            spec={widget.g2_spec as Record<string, unknown>}
            data={widget.data ? (Object.values(widget.data) as Record<string, unknown>[]) : undefined}
          />
        )}
      </div>

      {/* ---- Footer ---- */}
      <div style={styles.footer}>
        {widget.sql ? (
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
