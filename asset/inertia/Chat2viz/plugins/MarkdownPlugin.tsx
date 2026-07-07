import ReactMarkdown from 'react-markdown';
import rehypeSanitize from 'rehype-sanitize';
import { Alert, Typography } from 'antd';
import type { PluginProps } from './registry';

/**
 * MarkdownPlugin — renders `plugin_spec.content` via react-markdown +
 * rehype-sanitize. Also serves as the universal fallback for unknown
 * `plugin_type`s.
 *
 * For the fallback case (no `content`), it renders either:
 *  - the phase-aware unknown-type error widget (Phase 0-2, contract §2.7), or
 *  - a silent markdown placeholder naming the unknown type (Phase 3+).
 *
 * The rehype-sanitize step is the security boundary: any HTML in the markdown
 * is stripped to a safe subset, preventing XSS from LLM-produced content.
 */
export default function MarkdownPlugin({ widget }: PluginProps) {
  const spec = widget.plugin_spec ?? {};
  const content = typeof spec.content === 'string' ? spec.content : null;

  // Direct markdown widget — render content.
  if (content !== null) {
    return (
      <div style={styles.wrap} className="chat2viz-markdown">
        <ReactMarkdown rehypePlugins={[rehypeSanitize]}>{content}</ReactMarkdown>
      </div>
    );
  }

  // Fallback path: no content → unknown plugin_type handling.
  // (PluginRenderer gates Phase 0-2 vs 3+ separately; reaching here means the
  // markdown component is being used as the silent Phase 3+ fallback or for a
  // markdown widget missing its content field.)
  const pluginType = widget.plugin_type ?? '未知';
  const title = widget.title ?? '未命名组件';
  return (
    <div style={styles.wrap}>
      <Alert
        type="info"
        showIcon
        message={`${title}（类型：${pluginType}）`}
        description="该组件类型暂无可视化内容，已降级为占位展示。"
      />
      {widget.data && Array.isArray(widget.data?.rows) && widget.data.rows.length > 0 && (
        <Typography.Paragraph type="secondary" style={{ fontSize: 12, marginTop: 8 }}>
          数据共 {widget.data.rows.length} 行，未渲染。
        </Typography.Paragraph>
      )}
    </div>
  );
}

const styles: Record<string, React.CSSProperties> = {
  wrap: {
    padding: 12,
    fontSize: 13,
    lineHeight: 1.7,
    overflow: 'auto',
    height: '100%',
  },
};
