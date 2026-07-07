import { useState, useCallback } from 'react';
import { Alert, Collapse, Popconfirm, Skeleton, Spin, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, InboxOutlined, LoadingOutlined, ReloadOutlined } from '@ant-design/icons';
import PluginRenderer from './PluginRenderer';
import { useDashboardStore } from '../store/dashboardStore';
import type { WidgetSpec } from '../types/dsl';
import type { WidgetStatus, WidgetCacheEntry } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface WidgetCardProps {
  /** The DSL widget spec (from dsl.widgets[id]). */
  widget: WidgetSpec;
  /** The render-side cache entry (rows + status + error_code). */
  cache?: WidgetCacheEntry;
  onTitleChange: (widgetId: string, title: string) => void;
  onRemove: (widgetId: string) => void;
  onRefresh?: (widgetId: string) => void;
  onRegenerate?: (widgetId: string) => void;
  /** True while this widget's data is being re-fetched on manual refresh. */
  refreshing?: boolean;
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
  /** SQL string to display in the collapsible panel (form A draft fetch). */
  sql?: string;
  /**
   * 统一的"可编辑"开关(默认 true)。false 时禁用所有会改数据/布局的交互。
   */
  editable?: boolean;
}

/**
 * Resolve the effective render status. A cache entry without status defaults
 * to 'chart' (defensive for any widget that arrived via a non-standard path).
 */
function effectiveStatus(cache: WidgetCacheEntry | undefined): WidgetStatus {
  return cache?.status ?? 'chart';
}

/**
 * §4 line 274: WIDGET_ERROR error_code differentiation.
 * Returns a per-code UI descriptor for the error branch.
 */
interface ErrorUi {
  message: string;
  description?: string;
  allowRetry: boolean;
}

function describeError(errorCode: string | undefined, errorMsg: string | undefined): ErrorUi {
  switch (errorCode) {
    case 'QUERY_TIMEOUT':
      return {
        message: '查询超时',
        description: errorMsg ?? '该图表查询超时，可点击重试。',
        allowRetry: true,
      };
    case 'SQL_VALIDATION_ERROR':
      return {
        message: '查询语句不合法',
        description: errorMsg ?? '该图表的 SQL 未通过安全校验，需要重新生成。',
        allowRetry: false,
      };
    case 'DATABASE_ERROR':
      return {
        message: '数据源异常',
        description: errorMsg ?? '数据源连接或执行异常，请稍后重试。',
        allowRetry: false,
      };
    default:
      return {
        message: '图表生成失败',
        description: errorMsg,
        allowRetry: false,
      };
  }
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function WidgetCard({
  widget,
  cache,
  onTitleChange,
  onRemove,
  onRefresh,
  onRegenerate,
  refreshing = false,
  showSql = false,
  sql,
  editable = true,
}: WidgetCardProps) {
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [titleDraft, setTitleDraft] = useState(widget.title ?? '');

  const handleTitleDoubleClick = useCallback(() => {
    setTitleDraft(widget.title ?? '');
    setIsEditingTitle(true);
  }, [widget.title]);

  const handleTitleConfirm = useCallback(() => {
    const trimmed = titleDraft.trim();
    if (trimmed && trimmed !== widget.title) {
      onTitleChange(widget.widget_id, trimmed);
    }
    setIsEditingTitle(false);
  }, [titleDraft, widget.title, widget.widget_id, onTitleChange]);

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

  const status = effectiveStatus(cache);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const locked = !editable;

  // Resolved render data: from the cache (DSL store path) or empty.
  const rows = cache?.rows ?? [];
  const total = cache?.total;
  const truncated = cache?.truncated;
  const errorCode = cache?.error_code;
  const errorMsg = cache?.error_msg;

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
              onDoubleClick={editable ? handleTitleDoubleClick : undefined}
              style={{ cursor: editable ? 'pointer' : 'default', flex: 1 }}
            >
              {widget.title || '未命名图表'}
              {editable && <EditOutlined style={{ marginLeft: 6, fontSize: 11, opacity: 0.5 }} />}
            </Typography.Text>
          )}
        </div>
        <div style={styles.headerActions}>
          {(() => {
            const lockStyle = locked ? { ...styles.iconBtn, opacity: 0.35, pointerEvents: 'none' as const, cursor: 'not-allowed' as const } : styles.iconBtn;
            return (
              <>
                {onRefresh && status === 'chart' && (
                  <Tooltip title={locked ? 'AI 生成中，暂不可操作' : '刷新该图表数据'}>
                    {refreshing ? (
                      <LoadingOutlined style={lockStyle} spin />
                    ) : (
                      <ReloadOutlined
                        onClick={() => !locked && onRefresh(widget.widget_id)}
                        style={lockStyle}
                      />
                    )}
                  </Tooltip>
                )}
                <Popconfirm
                  title="确定移除该图表？"
                  onConfirm={() => onRemove(widget.widget_id)}
                  okText="移除"
                  cancelText="取消"
                  disabled={locked}
                >
                  <DeleteOutlined style={lockStyle} />
                </Popconfirm>
              </>
            );
          })()}
        </div>
      </div>

      {/* ---- Chart Area — four render branches by status ---- */}
      <div style={styles.chartArea}>
        {status === 'loading' && (
          <div style={styles.emptyChart}>
            <Skeleton active paragraph={{ rows: 4 }} />
            <div style={styles.loadingHint}>
              <Spin size="small" />
              <Typography.Text type="secondary" style={{ fontSize: 12, marginLeft: 8 }}>
                {streamingState !== 'idle' ? '正在查询…' : '加载中…'}
              </Typography.Text>
            </div>
          </div>
        )}

        {status === 'error' && (() => {
          // §4 line 274: differentiate the error UI by error_code.
          const ui = describeError(errorCode, errorMsg);
          return (
            <div style={styles.errorCard}>
              <Alert
                type="error"
                showIcon
                message={ui.message}
                description={ui.description}
                style={{ marginBottom: 8 }}
              />
              {ui.allowRetry && onRefresh && (
                <Typography.Link
                  onClick={() => !locked && onRefresh(widget.widget_id)}
                  style={locked ? { pointerEvents: 'none', opacity: 0.4 } : undefined}
                >
                  <ReloadOutlined style={{ marginRight: 4 }} />
                  重试
                </Typography.Link>
              )}
              {!ui.allowRetry && onRegenerate && (
                <Typography.Link
                  onClick={() => !locked && onRegenerate(widget.widget_id)}
                  style={locked ? { pointerEvents: 'none', opacity: 0.4 } : undefined}
                >
                  <ReloadOutlined style={{ marginRight: 4 }} />
                  重新生成
                </Typography.Link>
              )}
            </div>
          );
        })()}

        {status === 'empty' && (
          <div style={styles.emptyCard}>
            <InboxOutlined style={{ fontSize: 36, color: '#faad14', marginBottom: 8 }} />
            <Typography.Text strong style={{ marginBottom: 4 }}>
              未查询到数据
            </Typography.Text>
            <Typography.Paragraph
              type="secondary"
              style={{ fontSize: 12, margin: 0, textAlign: 'center', lineHeight: 1.6 }}
            >
              该查询返回 0 行数据。
            </Typography.Paragraph>
            {onRegenerate && (
              <Typography.Link
                onClick={() => !locked && onRegenerate(widget.widget_id)}
                style={{ fontSize: 12, marginTop: 8, ...(locked ? { pointerEvents: 'none', opacity: 0.4 } : {}) }}
              >
                <ReloadOutlined style={{ marginRight: 4 }} />
                检查取值并重新生成
              </Typography.Link>
            )}
          </div>
        )}

        {status === 'chart' && (
          <PluginRenderer
            widget={widget}
            data={rows}
            {...(total !== undefined ? { total } : {})}
            {...(truncated !== undefined ? { truncated } : {})}
          />
        )}
      </div>

      {/* ---- Truncation notice (read-only, total reported by the backend) ---- */}
      {truncated && typeof total === 'number' && (
        <div style={styles.truncationNotice}>
          <Typography.Text type="warning" style={{ fontSize: 12 }}>
            已截断，共 {total} 行
          </Typography.Text>
        </div>
      )}

      {/* ---- Footer ---- */}
      <div style={styles.footer}>
        {showSql && sql ? (
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
                  <pre style={styles.sqlBlock}>{sql}</pre>
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
    flexDirection: 'column' as const,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 12,
    height: '100%',
    minHeight: 200,
    padding: 12,
  },
  loadingHint: {
    display: 'flex',
    alignItems: 'center',
  },
  errorCard: {
    display: 'flex',
    flexDirection: 'column' as const,
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
    minHeight: 200,
    padding: 12,
  },
  emptyCard: {
    display: 'flex',
    flexDirection: 'column' as const,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    height: '100%',
    minHeight: 200,
    padding: 16,
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
