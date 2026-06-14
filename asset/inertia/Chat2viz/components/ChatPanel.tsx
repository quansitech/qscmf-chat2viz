import { useRef, useEffect, useState, useCallback } from 'react';
import { Alert, Badge, Button, Collapse, Empty, Input, message, Spin, Tag, Tooltip, Typography } from 'antd';
import { SendOutlined, QuestionCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useDashboardStore } from '../store/dashboardStore';
import { useSseStream } from '../hooks/useSseStream';
import type { ChatMessage } from '../store/dashboardStore';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const EXAMPLE_QUESTIONS = [
  '查看月度销售趋势',
  '按产品类别对比收入',
  '订单金额排名前10的客户有哪些？',
];

// Static keyframe CSS for the typing cursor — defined once at module level
// to avoid re-injecting a <style> element on every render.
const TYPING_CURSOR_CSS = `@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} } .typing-cursor { animation: blink 1s step-end infinite; display: inline; } .typing-cursor::after { content: '|'; }`;

/** Selector: does the last assistant message have content? */
function selectLastAssistantHasContent(s: { messages: ChatMessage[] }): boolean {
  const msgs = s.messages;
  const last = [...msgs].reverse().find((m) => m.role === 'assistant');
  return !!last?.content;
}

// ---------------------------------------------------------------------------
// AiStepsIndicator — renders progress badges from store.aiSteps
// ---------------------------------------------------------------------------

function AiStepsIndicator() {
  const aiSteps = useDashboardStore((s) => s.aiSteps);
  const streamingState = useDashboardStore((s) => s.streamingState);

  if (streamingState === 'idle' || aiSteps.length === 0) {
    if (streamingState !== 'idle') {
      return (
        <div style={styles.loadingIndicator}>
          <Spin size="small" />
          <Typography.Text type="secondary" style={{ marginLeft: 8 }}>分析中...</Typography.Text>
        </div>
      );
    }
    return null;
  }

  return (
    <div style={styles.aiStepsContainer}>
      {aiSteps.map((step, i) => (
        <div key={step.id} style={styles.aiStep}>
          <Badge status={step.completed ? 'success' : 'processing'} />
          <Typography.Text type={step.completed ? 'secondary' : undefined} style={{ fontSize: 12 }}>
            {step.label}
          </Typography.Text>
          {i === aiSteps.length - 1 && !step.completed && <Spin size="small" style={{ marginLeft: 4 }} />}
        </div>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

interface ChatPanelProps {
  disabled?: boolean;
}

export default function ChatPanel({ disabled = false }: ChatPanelProps) {
  const messages = useDashboardStore((s) => s.messages);
  const isLoading = useDashboardStore((s) => s.isLoading);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const uid = useDashboardStore((s) => s.uid);
  // Endpoint-level global error — the SINGLE source of truth for endpoint
  // failures (connection/auth/stream-level errors). Widget-level local errors
  // (WIDGET_ERROR) are surfaced inside each WidgetCard and MUST NOT set this.
  const globalError = useDashboardStore((s) => s.error);
  const { sendQuestion, cancel } = useSseStream();
  const lastAssistantHasContent = useDashboardStore(selectLastAssistantHasContent);

  const [inputValue, setInputValue] = useState('');
  const [newConvLoading, setNewConvLoading] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // ---- New Conversation ----
  const handleNewConversation = useCallback(async () => {
    if (!uid || streamingState !== 'idle') return;
    setNewConvLoading(true);
    try {
      const resp = await fetch('/extends/Chat2Viz/api_conversation_create', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ dashboard_uid: uid }),
      });
      const result = await resp.json();
      if (result.status === 1) {
        useDashboardStore.getState().resetConversation();
        if (result.data?.conversation_id) {
          useDashboardStore.getState().setConversationId(
            String(result.data.conversation_id),
          );
        }
      } else {
        message.error(result.info || 'Failed to create new conversation');
      }
    } catch {
      message.error('Network error creating conversation');
    } finally {
      setNewConvLoading(false);
    }
  }, [uid, streamingState]);

  // ---- Auto-scroll to bottom on new messages ----
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // ---- Send question ----
  const handleSend = useCallback(() => {
    const question = inputValue.trim();
    if (!question || isLoading) return;
    setInputValue('');
    sendQuestion(question);
  }, [inputValue, isLoading, sendQuestion]);

  // ---- Enter to send, Shift+Enter for newline ----
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    },
    [handleSend],
  );

  // ---- Example question click ----
  const handleExampleClick = useCallback(
    (q: string) => {
      setInputValue('');
      sendQuestion(q);
    },
    [sendQuestion],
  );

  const hasMessages = messages.length > 0;

  return (
    <div style={styles.panel}>
      {/* ---- Service unavailable banner ---- */}
      {disabled && (
        <div style={styles.disabledBanner}>
          分析服务不可用，请检查后端服务状态
        </div>
      )}

      {/* ---- Endpoint-level global error Alert ---- */}
      {/* Single source of truth for store.error (endpoint failures). The
          duplicate top-bar Tag in DashboardEdit has been removed so the two
          never coexist. Widget-level (WIDGET_ERROR) failures are independent
          and render only inside their WidgetCard. */}
      {globalError && (
        <div style={styles.errorAlertWrap}>
          <Alert
            type="error"
            showIcon
            message={globalError}
            closable
            onClose={() => useDashboardStore.setState({ error: '' })}
          />
        </div>
      )}

      {/* ---- Header with New Conversation button ---- */}
      {hasMessages && (
        <div style={styles.panelHeader}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {messages.length} messages
          </Typography.Text>
          <Tooltip title="New Conversation">
            <Button
              size="small"
              icon={<PlusOutlined />}
              loading={newConvLoading}
              disabled={streamingState !== 'idle'}
              onClick={handleNewConversation}
            >
              New
            </Button>
          </Tooltip>
        </div>
      )}

      {/* ---- Message List ---- */}
      <div style={styles.messageList}>
        {!hasMessages && (
          <div style={styles.emptyState}>
            <Empty
              image={<QuestionCircleOutlined style={{ fontSize: 36, color: '#bfbfbf' }} />}
              description={
                <div>
                  <Typography.Text type="secondary">向数据提问</Typography.Text>
                  <div style={styles.examples}>
                    {EXAMPLE_QUESTIONS.map((q) => (
                      <button key={q} onClick={() => !disabled && handleExampleClick(q)} disabled={disabled} style={{ ...styles.exampleBtn, opacity: disabled ? 0.5 : 1, cursor: disabled ? 'not-allowed' : 'pointer' }}>
                        {q}
                      </button>
                    ))}
                  </div>
                </div>
              }
            />
          </div>
        )}

        {messages.map((msg) => (
          <MessageBubble key={msg.id} message={msg} />
        ))}

        <AiStepsIndicator />

        {/* Endpoint-level streaming errors are surfaced via the global Alert
            above (store.error) — the single source of truth. The legacy
            top-bar Tag in DashboardEdit has been removed so they never
            duplicate. When streamingState resets to 'idle' (via the `done`
            event) the user can send a new question. */}

        <div ref={messagesEndRef} />
        {streamingState !== 'idle' && lastAssistantHasContent && (
          <style>{TYPING_CURSOR_CSS}</style>
        )}
      </div>

      {/* ---- Input Bar ---- */}
      <div style={styles.inputBar}>
        <Input.TextArea
          value={inputValue}
          onChange={(e) => setInputValue(e.target.value)}
          onKeyDown={handleKeyDown}
          placeholder="输入你的问题...（回车发送，Shift+回车换行）"
          autoSize={{ minRows: 1, maxRows: 4 }}
          style={styles.textArea}
          disabled={disabled}
        />
        <Button
          type={streamingState === 'idle' ? 'primary' : 'default'}
          icon={<SendOutlined />}
          onClick={streamingState !== 'idle' ? cancel : handleSend}
          danger={streamingState !== 'idle'}
          style={styles.sendBtn}
          disabled={disabled || (streamingState === 'idle' && !inputValue.trim())}
        >
          {streamingState !== 'idle' ? '停止' : '发送'}
        </Button>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// MessageBubble sub-component
// ---------------------------------------------------------------------------

interface MessageBubbleProps {
  message: ChatMessage;
}

function MessageBubble({ message }: MessageBubbleProps) {
  const isUser = message.role === 'user';
  const isSystem = message.role === 'system';

  const streamingState = useDashboardStore((s) => s.streamingState);
  const messages = useDashboardStore((s) => s.messages);
  const isLastAssistant = message.role === 'assistant' &&
    messages[messages.length - 1]?.id === message.id;
  const showCursor = isLastAssistant && streamingState !== 'idle';

  const status = message.message_status;

  if (isSystem) return null;

  return (
    <div style={{ ...styles.bubbleRow, justifyContent: isUser ? 'flex-end' : 'flex-start' }}>
      <div style={isUser ? styles.userBubble : styles.assistantBubble}>
        {/* Status indicators for non-complete messages */}
        {!isUser && status === 'streaming' && (
          <Tag color="processing" style={styles.statusTag}>AI was generating...</Tag>
        )}
        {!isUser && status === 'interrupted' && (
          <Tag color="warning" style={styles.statusTag}>Interrupted</Tag>
        )}
        {!isUser && status === 'failed' && (
          <Tag color="error" style={styles.statusTag}>Failed</Tag>
        )}

        {/* Security: React auto-escapes text content. If switching to
            dangerouslySetInnerHTML for markdown rendering, MUST sanitize
            with DOMPurify first. */}
        {message.content && (
          <div style={styles.bubbleContent}>
            {message.content}
            {showCursor && <span className="typing-cursor" />}
          </div>
        )}

        {/* SQL collapse */}
        {message.metadata?.sql && (
          <Collapse
            ghost
            size="small"
            style={{ marginTop: 4 }}
            items={[
              {
                key: 'sql',
                label: <Typography.Text type="secondary" style={{ fontSize: 11 }}>查询语句</Typography.Text>,
                children: (
                  <pre style={styles.sqlBlock}>{message.metadata.sql}</pre>
                ),
              },
            ]}
          />
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Styles
// ---------------------------------------------------------------------------

const styles: Record<string, React.CSSProperties> = {
  panel: {
    display: 'flex',
    flexDirection: 'column',
    height: '100%',
    background: '#fff',
    borderRight: '1px solid #f0f0f0',
  },
  disabledBanner: {
    padding: '8px 16px',
    background: '#fff2f0',
    borderBottom: '1px solid #ffccc7',
    color: '#cf1322',
    fontSize: 13,
    textAlign: 'center' as const,
    flexShrink: 0,
  },
  errorAlertWrap: {
    padding: '8px 12px',
    borderBottom: '1px solid #f0f0f0',
    flexShrink: 0,
  },
  panelHeader: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    padding: '6px 12px',
    borderBottom: '1px solid #f0f0f0',
    flexShrink: 0,
  },
  messageList: {
    flex: 1,
    overflowY: 'auto',
    padding: '12px 16px',
  },
  emptyState: {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    height: '100%',
  },
  examples: {
    marginTop: 12,
    display: 'flex',
    flexDirection: 'column',
    gap: 6,
  },
  exampleBtn: {
    background: '#fafafa',
    border: '1px solid #f0f0f0',
    borderRadius: 6,
    padding: '6px 10px',
    fontSize: 12,
    color: '#666',
    cursor: 'pointer',
    textAlign: 'left',
    transition: 'background 0.15s',
  },
  loadingIndicator: {
    display: 'flex',
    alignItems: 'center',
    padding: '8px 0',
  },
  aiStepsContainer: {
    padding: '8px 0',
  },
  aiStep: {
    display: 'flex',
    alignItems: 'center',
    gap: 6,
    marginBottom: 4,
  },
  inputBar: {
    display: 'flex',
    alignItems: 'flex-end',
    gap: 8,
    padding: '12px 16px',
    borderTop: '1px solid #f0f0f0',
    background: '#fafafa',
  },
  textArea: {
    flex: 1,
    resize: 'none',
  },
  sendBtn: {
    flexShrink: 0,
  },
  bubbleRow: {
    display: 'flex',
    marginBottom: 8,
  },
  userBubble: {
    background: '#e6f4ff',
    borderRadius: '12px 12px 2px 12px',
    padding: '8px 12px',
    maxWidth: '85%',
    wordBreak: 'break-word' as const,
  },
  assistantBubble: {
    background: '#f5f5f5',
    borderRadius: '12px 12px 12px 2px',
    padding: '8px 12px',
    maxWidth: '85%',
    wordBreak: 'break-word' as const,
  },
  bubbleContent: {
    fontSize: 13,
    lineHeight: 1.6,
    whiteSpace: 'pre-wrap' as const,
  },
  sqlBlock: {
    background: '#fff',
    padding: 6,
    borderRadius: 4,
    fontSize: 11,
    overflow: 'auto',
    margin: 0,
    maxHeight: 100,
  },
  statusTag: {
    fontSize: 11,
    marginBottom: 4,
  },
};
