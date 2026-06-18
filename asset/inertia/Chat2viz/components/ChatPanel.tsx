import { useRef, useEffect, useState, useCallback } from 'react';
import { Alert, Badge, Button, Collapse, Empty, Input, message as notify, Modal, Spin, Tag, Tooltip, Typography } from 'antd';
import { SendOutlined, QuestionCircleOutlined, PlusOutlined, CopyOutlined, LikeOutlined, DislikeOutlined } from '@ant-design/icons';
import ReactMarkdown from 'react-markdown';
import rehypeSanitize from 'rehype-sanitize';
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

// Static keyframe CSS for the typing cursor + the three-dot thinking indicator
// — defined once at module level to avoid re-injecting a <style> per render.
const TYPING_CURSOR_CSS = `@keyframes blink { 0%,100%{opacity:1} 50%{opacity:0} } .typing-cursor { animation: blink 1s step-end infinite; display: inline; } .typing-cursor::after { content: '|'; } @keyframes typingDot { 0%,60%,100%{opacity:.25; transform: translateY(0)} 30%{opacity:1; transform: translateY(-2px)} } .typing-dots span { display:inline-block; width:5px; height:5px; margin:0 1px; border-radius:50%; background:#888; animation: typingDot 1.2s infinite; } .typing-dots span:nth-child(2){animation-delay:.2s} .typing-dots span:nth-child(3){animation-delay:.4s}`;

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
  // Whether the last assistant message has real answer text yet.
  const assistantHasContent = useDashboardStore((s) => {
    const msgs = s.messages;
    const last = [...msgs].reverse().find((m) => m.role === 'assistant');
    return !!last?.content;
  });

  // Keep the three-dot indicator until the FIRST answer text arrives — tool
  // calls (search_objects etc.) often arrive before any visible answer, and
  // swapping to step badges prematurely makes the UI look empty/jumpy.
  // Also show it while waiting for the first frame (submitted, no steps yet).
  const awaitingFirstAnswer = streamingState !== 'idle' && !assistantHasContent;
  if (streamingState === 'submitted' || awaitingFirstAnswer) {
    return (
      <div style={styles.loadingIndicator}>
        <span className="typing-dots"><span /><span /><span /></span>
      </div>
    );
  }
  if (streamingState === 'idle') return null;

  // Deduplicate tool steps by label: the backend often repeats the same tool
  // (e.g. search_objects multiple times), and each repeat currently produces a
  // new step row. Collapse to ONE row per label, keeping the LAST occurrence
  // (so its processing/completed state is the most recent). This renders a
  // single "搜索相关表..." regardless of how many times the tool fires.
  const seen = new Map<string, typeof aiSteps[number]>();
  for (const step of aiSteps) {
    if (step.type === 'tool_start') {
      seen.set(step.label, step); // last-write-wins per label
    }
  }
  const dedupedSteps = aiSteps.filter((step) => {
    if (step.type !== 'tool_start') return true;
    return seen.get(step.label) === step; // keep only the last occurrence per label
  });

  return (
    <div style={styles.aiStepsContainer}>
      {dedupedSteps.map((step, i) => (
        <div key={step.id} style={styles.aiStep}>
          <Badge status={step.completed ? 'success' : 'processing'} />
          <Typography.Text type={step.completed ? 'secondary' : undefined} style={{ fontSize: 12 }}>
            {step.label}
          </Typography.Text>
          {i === dedupedSteps.length - 1 && !step.completed && <Spin size="small" style={{ marginLeft: 4 }} />}
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
  /** Feature flag: show the "查询语句" panel in assistant bubbles (env-driven). */
  showSql?: boolean;
}

export default function ChatPanel({ disabled = false, showSql = false }: ChatPanelProps) {
  const messages = useDashboardStore((s) => s.messages);
  const isLoading = useDashboardStore((s) => s.isLoading);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const historyStatus = useDashboardStore((s) => s.historyStatus);
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
  const textAreaRef = useRef<{ focus: () => void; resizableTextArea?: { textArea: HTMLTextAreaElement } } | null>(null);

  /** Refocus the input so the user can keep typing the next question. */
  const refocusInput = useCallback(() => {
    // antd TextArea exposes focus() on its ref; defer to next tick so the
    // post-send state settle (input cleared, height reset) lands first.
    setTimeout(() => textAreaRef.current?.focus(), 0);
  }, []);

  // History still loading → block sending until the conversation is hydrated.
  const historyLoading = historyStatus === 'loading';

  // ---- New Conversation (with confirm: charts preserved, chat cleared) ----
  const performNewConversation = useCallback(async () => {
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
            String(result.data?.conversation_id),
          );
        }
        notify.success('已开始新对话，已生成的图表保留。', 2);
        refocusInput();
      } else {
        notify.error(result.info || 'Failed to create new conversation');
      }
    } catch {
      notify.error('Network error creating conversation');
    } finally {
      setNewConvLoading(false);
    }
  }, [uid, streamingState, refocusInput]);

  const handleNewConversation = useCallback(() => {
    if (!uid || streamingState !== 'idle') return;
    // Confirm before clearing: charts stay, but the conversation history is
    // discarded and cannot be recovered — guard against accidental clicks.
    Modal.confirm({
      title: '开始新对话？',
      content: '当前对话记录将被清空，已生成的图表会保留。此操作无法撤销。',
      okText: '开始新对话',
      okButtonProps: { danger: true },
      cancelText: '取消',
      onOk: performNewConversation,
    });
  }, [uid, streamingState, performNewConversation]);

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
    // Keep focus in the input so the user can immediately type the next
    // follow-up question (industry convention for chat inputs).
    refocusInput();
  }, [inputValue, isLoading, sendQuestion, refocusInput]);

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

      {/* ---- History hydration loader (session init) ---- */}
      {historyLoading && (
        <div style={styles.historyLoader}>
          <Spin size="small" />
          <Typography.Text type="secondary" style={{ marginLeft: 8 }}>正在加载对话历史...</Typography.Text>
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
          <MessageBubble key={msg.id} message={msg} showSql={showSql} />
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
          autoSize={{ minRows: 1, maxRows: 6 }}
          style={styles.textArea}
          disabled={disabled}
          ref={textAreaRef as any}
        />
        <Button
          type={streamingState === 'idle' ? 'primary' : 'default'}
          icon={<SendOutlined />}
          onClick={streamingState !== 'idle' ? cancel : handleSend}
          danger={streamingState !== 'idle'}
          style={styles.sendBtn}
          disabled={disabled || historyLoading || (streamingState === 'idle' && !inputValue.trim())}
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
  showSql?: boolean;
}

function MessageBubble({ message, showSql = false }: MessageBubbleProps) {
  const isUser = message.role === 'user';
  const isSystem = message.role === 'system';

  const streamingState = useDashboardStore((s) => s.streamingState);
  const messages = useDashboardStore((s) => s.messages);
  const isLastAssistant = message.role === 'assistant' &&
    messages[messages.length - 1]?.id === message.id;
  const showCursor = isLastAssistant && streamingState !== 'idle';

  const status = message.message_status;

  // DEF-06: feedback (thumbs up/down) state + handler.
  // NOTE: ALL hooks (useState/useCallback) MUST run before any early return,
  // otherwise React throws "Rendered more hooks than during the previous
  // render" (React error #310) when a message transitions between the
  // empty-content placeholder path (which used to early-return) and a path
  // that rendered this useState. Keep hooks above the returns below.
  const [feedbackGiven, setFeedbackGiven] = useState<string | null>(null);
  const handleFeedback = useCallback(async (msg: any, thumbs: 'up' | 'down') => {
    try {
      const ADMIN_BASE = (window as any).__ADMIN_BASE__ || '';
      const resp = await fetch(`${ADMIN_BASE}/extends/Chat2Viz/api_feedback`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message_id: msg.id || '',
          conversation_id: msg.conversation_id || '',
          thumbs,
        }),
      });
      const result = await resp.json();
      if (result.status === 1 || result.success) {
        setFeedbackGiven(thumbs);
        notify.success(thumbs === 'up' ? '感谢反馈' : '已记录改进建议', 1.2);
      } else {
        notify.error(result.info || '反馈提交失败');
      }
    } catch {
      notify.error('网络错误，请重试');
    }
  }, []);

  if (isSystem) return null;

  // The last assistant message is created empty by startConversation() as a
  // write-target for appendAnswer(). While it has no content yet, the
  // AiStepsIndicator (three-dot / step badges) is the SINGLE loading affordance
  // — skip the empty grey bubble so the two never coexist.
  if (!isUser && isLastAssistant && !message.content && streamingState !== 'idle') {
    return null;
  }

  // Copy the assistant answer text. Only enabled when not streaming and there
  // is content — a common affordance for chat answers.
  const handleCopyAnswer = () => {
    const text = message.content ?? '';
    if (!text) return;
    const done = () => notify.success('已复制回答', 1.2);
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done));
    } else {
      fallbackCopy(text, done);
    }
  };
  // Copy the SQL statement (debugging convenience when show_sql is on).
  const handleCopySql = () => {
    const sql = message.metadata?.sql ?? '';
    if (!sql) return;
    const done = () => notify.success('已复制查询语句', 1.2);
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(sql).then(done).catch(() => fallbackCopy(sql, done));
    } else {
      fallbackCopy(sql, done);
    }
  };

  // Show the copy-answer button only for completed assistant bubbles.
  const canCopyAnswer = !isUser && streamingState === 'idle' && !!message.content;

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

        {/* Copy-answer affordance (top-right of assistant bubbles) */}
        {canCopyAnswer && (
          <Tooltip title="复制回答">
            <CopyOutlined onClick={handleCopyAnswer} style={styles.copyBtn} />
          </Tooltip>
        )}

        {/* DEF-12: render AI answer as markdown (bold, lists, code) via
            react-markdown + rehype-sanitize (whitelist, strips script/onclick).
            Cursor is placed AFTER the markdown block (not inline) because
            markdown produces block-level <p> elements — inline cursor would
            break layout. The typing-cursor class still provides the blink. */}
        {message.content && (
          <div className="chat-markdown" style={styles.bubbleContent}>
            <ReactMarkdown rehypePlugins={[rehypeSanitize]}>
              {message.content}
            </ReactMarkdown>
            {showCursor && <span className="typing-cursor" />}
          </div>
        )}

        {/* DEF-06: like/dislike feedback buttons (only after stream completes).
            Posts thumbs: "up"|"down" to the existing api_feedback endpoint. */}
        {!isUser && streamingState === 'idle' && message.content && (
          <div style={{ display: 'flex', gap: 4, marginTop: 4 }}>
            <Tooltip title="有帮助">
              <Button
                size="small"
                type="text"
                icon={<LikeOutlined />}
                style={{ color: feedbackGiven === 'up' ? '#52c41a' : '#999', padding: '0 4px' }}
                disabled={!!feedbackGiven}
                onClick={() => handleFeedback(message, 'up')}
              />
            </Tooltip>
            <Tooltip title="需改进">
              <Button
                size="small"
                type="text"
                icon={<DislikeOutlined />}
                style={{ color: feedbackGiven === 'down' ? '#ff4d4f' : '#999', padding: '0 4px' }}
                disabled={!!feedbackGiven}
                onClick={() => handleFeedback(message, 'down')}
              />
            </Tooltip>
          </div>
        )}

        {/* SQL collapse — hidden unless the CHAT2VIZ_SHOW_SQL feature flag is on */}
        {showSql && message.metadata?.sql && (
          <Collapse
            ghost
            size="small"
            style={{ marginTop: 4 }}
            items={[
              {
                key: 'sql',
                label: (
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <Typography.Text type="secondary" style={{ fontSize: 11 }}>查询语句</Typography.Text>
                    <Tooltip title="复制 SQL">
                      <CopyOutlined onClick={handleCopySql} style={{ fontSize: 11, color: '#999', cursor: 'pointer' }} />
                    </Tooltip>
                  </span>
                ),
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

// Legacy clipboard fallback for browsers without the async Clipboard API.
function fallbackCopy(text: string, done: () => void): void {
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
    done();
  } catch {
    notify.error('复制失败');
  }
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
  historyLoader: {
    display: 'flex',
    alignItems: 'center',
    padding: '8px 16px',
    background: '#fafafa',
    borderBottom: '1px solid #f0f0f0',
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
    position: 'relative' as const, // anchor for the copy button
  },
  copyBtn: {
    fontSize: 12,
    color: '#999',
    cursor: 'pointer' as const,
    // Inside the assistant bubble: top-right, subtle until hover.
    position: 'absolute' as const,
    top: 6,
    right: 6,
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
