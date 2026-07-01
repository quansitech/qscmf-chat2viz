import { useState, useCallback } from 'react';
import { Alert, Collapse, Popconfirm, Skeleton, Spin, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, InboxOutlined, LoadingOutlined, ReloadOutlined } from '@ant-design/icons';
import LazyG2Renderer from './LazyG2Renderer';
import WidgetTable from './WidgetTable';
import { hasChartSpec, useDashboardStore } from '../store/dashboardStore';
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
  /** Feature flag: show the per-widget "查询语句" panel (CHAT2VIZ_SHOW_SQL). */
  showSql?: boolean;
  /**
   * 统一的"可编辑"开关(默认 true)。false 时禁用所有会改数据/布局的交互:
   * 标题双击编辑、刷新、删除、重新生成。流式生成期间由 PreviewPanel 传入
   * streamingState==='idle', 一处控制全部 widget, 替代之前零散的 streamingState
   * 判断。只读查看类交互(SQL 折叠面板)不受影响。
   */
  editable?: boolean;
}

/**
 * Resolve the effective render status. Widgets without a `status` field
 * default to 'chart' (defensive fallback for any persisted widget that
 * predates the status field or arrived via a non-standard path).
 */
function effectiveStatus(widget: Widget): 'loading' | 'error' | 'chart' | 'empty' {
  return widget.status ?? 'chart';
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export default function WidgetCard({ widget, onTitleChange, onRemove, onRefresh, onRegenerate, refreshing = false, showSql = false, editable = true }: WidgetCardProps) {
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
  // Streaming state — used to vary the loading hint ("正在查询…" vs "加载中…").
  // NOTE: 交互门控改用统一的 `editable` prop(由 PreviewPanel 传入), 不再直接依赖
  // streamingState 判断可否操作 —— 这样未来若有其它"只读"场景(如已发布预览)只需
  // 传 editable={false}, 无需重复耦合 streamingState 语义。
  const streamingState = useDashboardStore((s) => s.streamingState);
  // locked = 不可编辑(流式生成中或显式只读)。统一门控所有改数据/布局的交互。
  const locked = !editable;

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
              onDoubleClick={editable ? handleTitleDoubleClick : undefined}
              style={{ cursor: editable ? 'pointer' : 'default', flex: 1 }}
            >
              {widget.title || '未命名图表'}
              {editable && <EditOutlined style={{ marginLeft: 6, fontSize: 11, opacity: 0.5 }} />}
            </Typography.Text>
          )}
        </div>
        <div style={styles.headerActions}>
          {/* locked(= !editable, 流式生成中或只读)时禁用刷新/删除: 防止用户改动
              已有图表与 AI 的整树替换冲突。统一降透明度 + 拦截指针(antd 图标无
              disabled prop)。locked 由外部 editable prop 驱动, 见组件顶部定义。 */}
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
                        onClick={() => !locked && onRefresh(widget.id)}
                        style={lockStyle}
                      />
                    )}
                  </Tooltip>
                )}
                <Popconfirm
                  title="确定移除该图表？"
                  onConfirm={() => onRemove(widget.id)}
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

      {/* ---- Chart Area — three render branches by status ---- */}
      <div style={styles.chartArea}>
        {status === 'loading' && (
          // DASHBOARD_INIT placeholder: skeleton frame + progress text. While a
          // stream is active this reads "正在查询…"; on dashboard reopen it reads
          // "加载中…". Gives the user a sense of what is happening, not just an
          // abstract shimmer.
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
              <Typography.Link
                onClick={() => !locked && onRegenerate(widget.id)}
                style={locked ? { pointerEvents: 'none', opacity: 0.4 } : undefined}
              >
                <ReloadOutlined style={{ marginRight: 4 }} />
                重新生成
              </Typography.Link>
            )}
          </div>
        )}

        {status === 'empty' && (
          // P0-A: SQL succeeded with 0 rows — a legitimate "no data" result
          // (NOT an error). Render a friendly empty card with the deterministic
          // explanation instead of a blank chart. suspect_value_mismatch hints
          // the LLM may have guessed a WHERE literal wrong (advisory).
          <div style={styles.emptyCard}>
            <InboxOutlined style={{ fontSize: 36, color: '#faad14', marginBottom: 8 }} />
            <Typography.Text strong style={{ marginBottom: 4 }}>
              未查询到数据
            </Typography.Text>
            {widget.data_explain && (
              <Typography.Paragraph
                type="secondary"
                style={{ fontSize: 12, margin: 0, textAlign: 'center', lineHeight: 1.6 }}
              >
                {widget.data_explain}
              </Typography.Paragraph>
            )}
            {widget.suspect_value_mismatch && onRegenerate && (
              <Typography.Link
                onClick={() => !locked && onRegenerate(widget.id)}
                style={{ fontSize: 12, marginTop: 8, ...(locked ? { pointerEvents: 'none', opacity: 0.4 } : {}) }}
              >
                <ReloadOutlined style={{ marginRight: 4 }} />
                检查取值并重新生成
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
