import { useRef, useEffect, useState, useCallback } from 'react';
import { Alert, Badge, Button, Collapse, Empty, Input, message as notify, Popconfirm, Spin, Tag, Tooltip, Typography } from 'antd';
import { SendOutlined, QuestionCircleOutlined, CopyOutlined, LikeOutlined, DislikeOutlined, RedoOutlined, EditOutlined } from '@ant-design/icons';
import ReactMarkdown from 'react-markdown';
import rehypeSanitize from 'rehype-sanitize';
import { useDashboardStore } from '../store/dashboardStore';
import { useSseStream } from '../hooks/useSseStream';
import { copyText } from '../utils/clipboard';
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

// Scoped markdown reset for chat bubbles. react-markdown emits plain
// <ul>/<ol>/<pre>/<blockquote> which inherit the browser UA default styles —
// notably ul/ol { padding-left: 40px } and pre { margin: 1em 0 }. Inside a
// narrow bubble (maxWidth 85%, content ~220px) that 40px left padding eats
// ~18% of the width and pushes content against / past the right edge.
// This reset tightens list indentation, collapses block margins, and makes
// code blocks scroll horizontally instead of overflowing the bubble.
const CHAT_MARKDOWN_CSS = `
.chat-markdown > :last-child { margin-bottom: 0; }
.chat-markdown p { margin: 0 0 6px; }
.chat-markdown ul, .chat-markdown ol { margin: 4px 0 6px; padding-left: 20px; }
.chat-markdown li { margin: 2px 0; }
.chat-markdown pre {
  margin: 4px 0;
  padding: 6px 8px;
  background: #ececec;
  border-radius: 4px;
  overflow-x: auto;
  max-width: 100%;
  box-sizing: border-box;
}
.chat-markdown code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
}
/* inline code (not inside pre) */
.chat-markdown :not(pre) > code {
  padding: 1px 4px;
  background: #ececec;
  border-radius: 3px;
}
.chat-markdown blockquote {
  margin: 4px 0;
  padding-left: 10px;
  border-left: 3px solid #d9d9d9;
  color: #666;
}
.chat-markdown h1, .chat-markdown h2, .chat-markdown h3 {
  margin: 6px 0 4px;
  font-size: 14px;
}
.chat-markdown a { word-break: break-all; }
.chat-markdown img { max-width: 100%; }
`;

// ---------------------------------------------------------------------------
// AiStepsIndicator — renders progress badges from store.aiSteps
// ---------------------------------------------------------------------------

function AiStepsIndicator() {
  const aiSteps = useDashboardStore((s) => s.aiSteps);
  const streamingState = useDashboardStore((s) => s.streamingState);
  // Whether the CURRENT turn's last assistant message has real answer text yet.
  // fix-stream-message-persistence Decision 4: scope to the last message only
  // (store.messages[last]) rather than a cross-message selector — historical
  // streaming orphans no longer leak in (api_conversation_history filters them),
  // but this keeps the check robust against any residual mixed states.
  const assistantHasContent = useDashboardStore((s) => {
    const msgs = s.messages;
    const last = msgs[msgs.length - 1];
    return !!last && last.role === 'assistant' && !!last.content;
  });

  // fix-stream-message-persistence: the three-dot indicator shows UNCONDITIONALLY
  // while streamingState === 'submitted' (request sent, no first frame yet). It
  // no longer depends on assistantHasContent for the submitted branch — that
  // selector could be polluted by stale historical messages and prematurely hide
  // the loader, leaving a blank reply area.
  if (streamingState === 'submitted') {
    return (
      <div style={styles.loadingIndicator}>
        <span className="typing-dots"><span /><span /><span /></span>
      </div>
    );
  }
  if (streamingState === 'idle') return null;

  // streaming / error states: keep the dots until the first real answer text
  // arrives (tool calls like search_objects often precede any visible answer),
  // then hand off to the step badges.
  const awaitingFirstAnswer = !assistantHasContent;
  if (awaitingFirstAnswer && aiSteps.length === 0) {
    return (
      <div style={styles.loadingIndicator}>
        <span className="typing-dots"><span /><span /><span /></span>
      </div>
    );
  }

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
}

export default function ChatPanel({ disabled = false }: ChatPanelProps) {
  const messages = useDashboardStore((s) => s.messages);
  const isLoading = useDashboardStore((s) => s.isLoading);
  const streamingState = useDashboardStore((s) => s.streamingState);
  const historyStatus = useDashboardStore((s) => s.historyStatus);
  const uid = useDashboardStore((s) => s.uid);
  // Endpoint-level global error — the SINGLE source of truth for endpoint
  // failures (connection/auth/stream-level errors). Widget-level local errors
  // (WIDGET_ERROR) are surfaced inside each WidgetCard and MUST NOT set this.
  const globalError = useDashboardStore((s) => s.error);
  // P0-B: derived follow-up suggestions from the last DASHBOARD_REPLACE.
  const suggestedFollowups = useDashboardStore((s) => s.lastSuggestedFollowups);
  const { sendQuestion, cancel } = useSseStream();

  const [inputValue, setInputValue] = useState('');
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const textAreaRef = useRef<{ focus: () => void; resizableTextArea?: { textArea: HTMLTextAreaElement } } | null>(null);
  // UX-H2: 智能滚动. 用 ref 跟踪"用户是否贴底", 仅在贴底时自动滚动 —— 否则流式
  // token 到达会把正在向上翻阅历史的用户强行拽回底部. ref 而非 state: 避免每次
  // scroll 事件触发 re-render (平滑滚动 + 流式 appendAnswer 已足够频繁).
  const messageListRef = useRef<HTMLDivElement>(null);
  const isAtBottomRef = useRef(true);

  /** Refocus the input so the user can keep typing the next question. */
  const refocusInput = useCallback(() => {
    // antd TextArea exposes focus() on its ref; defer to next tick so the
    // post-send state settle (input cleared, height reset) lands first.
    setTimeout(() => textAreaRef.current?.focus(), 0);
  }, []);

  // History still loading → block sending until the conversation is hydrated.
  const historyLoading = historyStatus === 'loading';

  // UX-H2: 主动发送(用户发起提问)时强制贴底 —— 自己发的问题当然要看回复.
  const forceScrollToBottom = useCallback(() => {
    isAtBottomRef.current = true;
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, []);

  // ---- Auto-scroll to bottom on new messages (仅当用户已贴底) ----
  useEffect(() => {
    if (!isAtBottomRef.current) return;
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // ---- Send question ----
  const handleSend = useCallback(() => {
    const question = inputValue.trim();
    if (!question || isLoading) return;
    setInputValue('');
    sendQuestion(question);
    // 用户主动发送 → 强制滚到底部看回复.
    forceScrollToBottom();
    // Keep focus in the input so the user can immediately type the next
    // follow-up question (industry convention for chat inputs).
    refocusInput();
  }, [inputValue, isLoading, sendQuestion, refocusInput, forceScrollToBottom]);

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
  // UX-H5: 与 handleSend 同样的守卫 —— 流式中(isLoading/streamingState!=='idle')
  // 不允许再发, 否则并发两个 SSE 会留下孤儿空 assistant 气泡(useSseStream 的 abort
  // 只中断 fetch, 不清 startConversation 已 push 的消息).
  const isBusy = isLoading || streamingState !== 'idle' || historyLoading;
  const handleExampleClick = useCallback(
    (q: string) => {
      if (isBusy) return;
      setInputValue('');
      sendQuestion(q);
      forceScrollToBottom();
    },
    [sendQuestion, isBusy, forceScrollToBottom],
  );

  // UX-H2: scroll 事件维护 isAtBottomRef. 阈值 80px 容忍平滑滚动的微小偏差.
  const handleMessageListScroll = useCallback(() => {
    const el = messageListRef.current;
    if (!el) return;
    const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight;
    isAtBottomRef.current = distanceFromBottom < 80;
  }, []);

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
            onClose={() => {
              // UX-H3: 关闭错误提示时, 不只清 error 文本 —— setError 把
              // streamingState 设成了 'error' 且永不复位, 导致发送键卡成"停止"、
              // 关掉 Alert 也无法发新问题. 这里同时 cancel()(中断可能仍在跑的
              // in-flight fetch) 并复位 idle, 恢复可发送状态.
              cancel();
              useDashboardStore.setState({ error: '', streamingState: 'idle', isLoading: false });
            }}
          />
        </div>
      )}

      {/* ---- Header ---- */}
      {hasMessages && (
        <div style={styles.panelHeader}>
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {/* UX-M2: 中文化, 且只统计真实消息(user/assistant), 排除 system 占位. */}
            {messages.filter((m) => m.role === 'user' || m.role === 'assistant').length} 条对话
          </Typography.Text>
        </div>
      )}

      {/* ---- Message List ---- */}
      <div
        ref={messageListRef}
        onScroll={handleMessageListScroll}
        style={styles.messageList}
      >
        {!hasMessages && (
          <div style={styles.emptyState}>
            <Empty
              image={<QuestionCircleOutlined style={{ fontSize: 36, color: '#bfbfbf' }} />}
              description={
                <div>
                  <Typography.Text type="secondary">向数据提问</Typography.Text>
                  <div style={styles.examples}>
                    {/* UX-H5: 流式中也禁用示例按钮(disabled||isBusy), 避免并发提问. */}
                    {EXAMPLE_QUESTIONS.map((q) => (
                      <button
                        key={q}
                        onClick={() => !disabled && !isBusy && handleExampleClick(q)}
                        disabled={disabled || isBusy}
                        style={{ ...styles.exampleBtn, opacity: (disabled || isBusy) ? 0.5 : 1, cursor: (disabled || isBusy) ? 'not-allowed' : 'pointer' }}
                      >
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

        {/* P0-B: suggested follow-up questions — rendered as clickable chips
            after the last assistant message, only when streaming has finished
            (streamingState==='idle') and suggestions are present. Clicking a
            chip sends it as a new question (reuses the example-question path). */}
        {hasMessages && streamingState === 'idle' && suggestedFollowups.length > 0 && !disabled && (
          <div style={styles.followupsRow}>
            {suggestedFollowups.map((q) => (
              <button
                key={q}
                onClick={() => handleExampleClick(q)}
                style={styles.followupChip}
                title={q}
              >
                {q}
              </button>
            ))}
          </div>
        )}

        <AiStepsIndicator />

        {/* Endpoint-level streaming errors are surfaced via the global Alert
            above (store.error) — the single source of truth. The legacy
            top-bar Tag in DashboardEdit has been removed so they never
            duplicate. When streamingState resets to 'idle' (via the `done`
            event) the user can send a new question. */}

        <style>{CHAT_MARKDOWN_CSS}</style>
        <div ref={messagesEndRef} />
        {/* 缺陷3: TYPING_CURSOR_CSS 同时承载 .typing-cursor 光标与 .typing-dots 三点
            loading 的样式/动画(见 line 22 的 CSS 字符串). submitted 态(首帧未到)时
            AiStepsIndicator 渲染了三点 <span>, 但若按 lastAssistantHasContent(此时为
            false) 门控注入 CSS, 三点会退化成无样式无动画的隐形 span —— 用户看不到
            "正在思考". 因此只要 streamingState !== 'idle' 就注入, 首帧前三点立即可见. */}
        {streamingState !== 'idle' && (
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
}

function MessageBubble({ message }: MessageBubbleProps) {
  const isUser = message.role === 'user';
  const isSystem = message.role === 'system';

  const streamingState = useDashboardStore((s) => s.streamingState);
  const messages = useDashboardStore((s) => s.messages);
  const isLastAssistant = message.role === 'assistant' &&
    messages[messages.length - 1]?.id === message.id;
  const showCursor = isLastAssistant && streamingState !== 'idle';

  // 是否为"最后一条 user 消息"(倒序第一个 user)。该条可编辑/重发:
  // 改写后发送 = 删除本轮重新生成(等同 retry 流程)。仅 idle 时允许编辑,
  // 流式生成中不响应。历史 user 消息(非最后一条)不可编辑。
  const isLastUser = isUser && (() => {
    for (let i = messages.length - 1; i >= 0; i--) {
      if (messages[i].role === 'user') return messages[i].id === message.id;
    }
    return false;
  })();
  // 编辑态 local state(仅最后一条 user 进入编辑时使用)。
  const [isEditingQuestion, setIsEditingQuestion] = useState(false);
  const [questionDraft, setQuestionDraft] = useState(message.content);
  // hover 显隐: 用 React state 而非 CSS :hover 伪类 —— 后者在合成事件/部分自动化
  // 场景下不可靠, 且 state 驱动对触屏(无 hover)也能平滑降级(focus 时也显示)。
  const [isUserBubbleHovered, setIsUserBubbleHovered] = useState(false);

  const status = message.message_status;

  // DEF-06: feedback (thumbs up/down) state + handler.
  // NOTE: ALL hooks (useState/useCallback) MUST run before any early return,
  // otherwise React throws "Rendered more hooks than during the previous
  // render" (React error #310) when a message transitions between the
  // empty-content placeholder path (which used to early-return) and a path
  // that rendered this useState. Keep hooks above the returns below.
  const [feedbackGiven, setFeedbackGiven] = useState<string | null>(null);
  // regenerateLastTurn 防重入: delete+resend 的网络往返期间, 同时门控"重新生成"
  // 按钮与"编辑提交"按钮(disabled+loading), 防止用户连点导致并发 delete / 消息错乱。
  const [isRegenerating, setIsRegenerating] = useState(false);

  // 重试/编辑重发(仅最后一条 user 触发的本轮): 删除本轮 user+其后所有 assistant
  // (后端事务删), 再用 questionOverride(若提供, 即编辑改写后的问题)或原问题重新发送。
  // 图表不动 —— 后端重新生成时按需更新。独立于 feedback 状态。
  // fix-redis-degrade-and-retry-dedup: the backend DELETE runs first; only on
  // success do we trim the frontend store + resend. This prevents the duplicate-
  // user-row regression (frontend trimmed, backend untouched, resend INSERTs a
  // third copy). On failure we abort with a toast instead.
  const { sendQuestion } = useSseStream();
  const regenerateLastTurn = useCallback(async (questionOverride?: string) => {
    if (isRegenerating) return;
    const cur = useDashboardStore.getState();
    const msgs = cur.messages;
    // 找最后一条 user 问题及其下标(即触发本轮回复的问题).
    let lastUserIndex = -1;
    let lastUserQuestion = '';
    for (let i = msgs.length - 1; i >= 0; i--) {
      if (msgs[i].role === 'user') { lastUserIndex = i; lastUserQuestion = msgs[i].content; break; }
    }
    if (lastUserIndex < 0 || !lastUserQuestion) return;

    const questionToSend = (questionOverride ?? '').trim();
    const effectiveQuestion = questionToSend !== '' ? questionToSend : lastUserQuestion;

    setIsRegenerating(true);
    // 先调后端清理上一轮消息对(dashboard 所有权校验在后端完成)。
    // 失败则不删前端 store、不重试,避免脏数据退回到原 bug。
    const ADMIN_BASE_RETRY = (window as any).__ADMIN_BASE__ || '';
    try {
      const resp = await fetch(`${ADMIN_BASE_RETRY}/extends/Chat2Viz/api_delete_last_turn`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          dashboard_uid: cur.uid,
          conversation_id: cur.conversationId,
        }),
      });
      const result = await resp.json();
      if (result.status !== 1) {
        notify.error(result.info || '清理失败，无法重试');
        return;
      }
    } catch {
      notify.error('网络错误，无法重试');
      return;
    } finally {
      setIsRegenerating(false);
    }

    // 后端成功后才删前端 store 的本轮消息并重新发送.
    // UX-H4: 旧实现无脑 slice(0, -2) 假定尾部正好是 [user, assistant] —— 但流式中断/
    // tool 消息/历史过滤异常时尾部可能不是这一对, 会误删(删掉 user 留下孤立 assistant,
    // 或删掉两轮 assistant). 改为定位最后一条 user 的下标精准切片.
    useDashboardStore.setState((state) => ({
      messages: state.messages.slice(0, lastUserIndex),
    }));
    sendQuestion(effectiveQuestion);
  }, [sendQuestion, isRegenerating]);

  // "重新生成" = 原样重试(不改问题文本)。与编辑提交共享 regenerateLastTurn 的防重入。
  const handleRetry = useCallback(async () => {
    await regenerateLastTurn();
  }, [regenerateLastTurn]);
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

  // ---- 最后一条 user 消息的编辑/重发 hooks ----
  // CRITICAL: 这些 useCallback MUST 在下方两个早返回 (isSystem / 空 assistant 流式)
  // 之前执行。否则当最后一条 assistant 从"空内容"(命中早返回, 不跑这些 hooks)变为
  // "有内容"(不早返回, 跑这些 hooks)时, 同一组件实例两次 render 的 hooks 数量不同,
  // 触发 React error #310 (Rendered more hooks than during the previous render)。
  const handleStartEdit = useCallback(() => {
    setQuestionDraft(message.content);
    setIsEditingQuestion(true);
  }, [message.content]);

  const handleCancelEdit = useCallback(() => {
    setIsEditingQuestion(false);
  }, []);

  const handleSubmitEdit = useCallback(() => {
    const trimmed = questionDraft.trim();
    if (!trimmed) return; // 空文本不提交
    setIsEditingQuestion(false);
    // 改写后发送 = 删除本轮重新生成(questionOverride 覆盖原问题)。
    // 原样不改文本直接提交也允许, 等价于"重新生成"。
    void regenerateLastTurn(trimmed);
  }, [questionDraft, regenerateLastTurn]);

  const handleEditKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSubmitEdit();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        handleCancelEdit();
      }
    },
    [handleSubmitEdit, handleCancelEdit],
  );

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
    // 统一走 utils/clipboard 的 fallback(HTTP 非安全上下文也能复制).
    copyText(text,
      () => notify.success('已复制回答', 1.2),
      () => notify.error('复制失败，请手动复制', 1.2),
    );
  };

  // Show the copy-answer button only for completed assistant bubbles.
  const canCopyAnswer = !isUser && streamingState === 'idle' && !!message.content;

  // ---- 最后一条 user 消息的编辑/重发 ----
  const canEditQuestion = isLastUser && streamingState === 'idle' && !isRegenerating;

  return (
    <div style={{ ...styles.bubbleRow, justifyContent: isUser ? 'flex-end' : 'flex-start' }}>
      <div
        style={isUser ? styles.userBubble : styles.assistantBubble}
        className={isUser ? 'user-bubble' : undefined}
        onMouseEnter={isUser ? () => setIsUserBubbleHovered(true) : undefined}
        onMouseLeave={isUser ? () => setIsUserBubbleHovered(false) : undefined}
      >
        {/* Status indicators for non-complete messages */}
        {/* UX-M1: 中文化(其余 UI 全是中文, 这三个英文标签很突兀). */}
        {!isUser && status === 'streaming' && (
          <Tag color="processing" style={styles.statusTag}>生成中…</Tag>
        )}
        {!isUser && status === 'interrupted' && (
          <Tag color="warning" style={styles.statusTag}>已中断</Tag>
        )}
        {!isUser && status === 'failed' && (
          <Tag color="error" style={styles.statusTag}>生成失败</Tag>
        )}

        {/* Copy-answer affordance (top-right of assistant bubbles) */}
        {canCopyAnswer && (
          <Tooltip title="复制回答">
            <CopyOutlined
              onClick={handleCopyAnswer}
              style={styles.copyBtn}
              role="button"
              tabIndex={0}
              aria-label="复制回答"
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleCopyAnswer(); } }}
            />
          </Tooltip>
        )}

        {/* thought/answer split: 可折叠"AI 思考过程"面板。仅在 message.thought
            非空时渲染(后端 reasoning 事件累积)。默认折叠,不打扰主回答流。
            展示后用户可看到 ReAct 推理过程(如"先查一下表结构")。 */}
        {!isUser && message.thought && (
          <Collapse
            ghost
            size="small"
            style={{ marginBottom: 4 }}
            items={[{
              key: 'thought',
              label: <span style={{ fontSize: 12, color: '#999' }}>💭 AI 思考过程</span>,
              children: (
                <div style={{
                  fontSize: 12,
                  color: '#888',
                  lineHeight: 1.6,
                  whiteSpace: 'pre-wrap',
                  // 长英文/代码/时间戳串(如 2006-02-15 05:03:42、release_year、
                  // 长 SQL/路径)在 pre-wrap 下不会在空白外换行, 会水平溢出气泡
                  // 右边界与正文重叠. 强制任意字符处可断行 + 溢出隐藏兜底.
                  overflowWrap: 'anywhere',
                  wordBreak: 'break-word',
                  overflow: 'hidden',
                }}>
                  {message.thought}
                  {streamingState !== 'idle' && <span className="typing-cursor" />}
                </div>
              ),
            }]}
          />
        )}

        {/* 最后一条 user 消息: 编辑态下渲染 TextArea + 发送/取消, 替代纯文本。
            改写后发送 = 删除本轮重新生成(等同 retry 流程), 原样发送 = 重新生成。
            仅 idle 且非重生成中可进入编辑(canEditQuestion 门控)。 */}
        {isUser && isEditingQuestion && (
          <div style={styles.editWrap}>
            <Input.TextArea
              value={questionDraft}
              onChange={(e) => setQuestionDraft(e.target.value)}
              onKeyDown={handleEditKeyDown}
              autoSize={{ minRows: 1, maxRows: 6 }}
              autoFocus
              style={styles.editTextArea}
            />
            <div style={styles.editActions}>
              <Button size="small" onClick={handleCancelEdit}>取消</Button>
              <Button
                size="small"
                type="primary"
                icon={<SendOutlined />}
                onClick={handleSubmitEdit}
                disabled={!questionDraft.trim()}
                loading={isRegenerating}
              >
                发送
              </Button>
            </div>
          </div>
        )}

        {/* 最后一条 user 消息: 非编辑态时, 在气泡左下角 hover 浮现"编辑"图标。
            点击进入编辑态。安静不打扰, 符合主流 chat 产品惯例。
            显隐由 isUserBubbleHovered state 驱动(React onMouseEnter/Leave),
            不依赖 CSS :hover 伪类(后者在合成事件/自动化/触屏下不可靠)。 */}
        {isUser && canEditQuestion && !isEditingQuestion && (
          <div className="user-edit-trigger" style={{ ...styles.editIconWrap, opacity: isUserBubbleHovered ? 1 : 0 }}>
            <Tooltip title="编辑问题">
              <EditOutlined
                onClick={handleStartEdit}
                style={styles.editIcon}
                role="button"
                tabIndex={0}
                aria-label="编辑问题"
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); handleStartEdit(); } }}
              />
            </Tooltip>
          </div>
        )}

        {/* DEF-12: render AI answer as markdown (bold, lists, code) via
            react-markdown + rehype-sanitize (whitelist, strips script/onclick).
            Cursor is placed AFTER the markdown block (not inline) because
            markdown produces block-level <p> elements — inline cursor would
            break layout. The typing-cursor class still provides the blink.
            user 消息编辑态时不重复渲染纯文本(避免与编辑器重复)。 */}
        {message.content && !(isUser && isEditingQuestion) && (
          <div className="chat-markdown" style={styles.bubbleContent}>
            <ReactMarkdown rehypePlugins={[rehypeSanitize]}>
              {message.content}
            </ReactMarkdown>
            {showCursor && <span className="typing-cursor" />}
          </div>
        )}

        {/* UX-M5: 流结束后只有 thought 没有 answer 的气泡(例如只收到 reasoning
            帧就中断)会只剩一个"💭 AI 思考过程"折叠面板、主回答区空白, 看起来坏掉.
            已完成(idle)且无 content 时给一条兜底提示, 区分于正常空状态. */}
        {!isUser && !message.content && streamingState === 'idle' && (
          <div style={{ ...styles.bubbleContent, color: '#999', fontStyle: 'italic' }}>
            AI 未返回回答内容，请重试或换个问法。
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
            {/* 重试:仅最后一条 AI 消息显示。删除本轮对话并重新发送上一条问题,
                图表保持不变。Popconfirm 确认避免误触(feedback 按钮不弹确认是
                因为它们非破坏性;重试会清掉本轮回复,属轻微破坏性操作)。 */}
            {isLastAssistant && (
              <Popconfirm
                title="重新生成最后一条回复？"
                description="将清除本轮对话并重新提问，已生成的图表不受影响。"
                okText="重新生成"
                cancelText="取消"
                onConfirm={handleRetry}
                disabled={isRegenerating}
              >
                <Button
                  size="small"
                  type="text"
                  icon={<RedoOutlined />}
                  loading={isRegenerating}
                  style={{ color: '#999', padding: '0 4px' }}
                  disabled={isRegenerating}
                />
              </Popconfirm>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

// clipboard fallback 已抽到 utils/clipboard.ts(统一 copyText)。

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
  // P0-B: suggested-followups chip row (rendered after the last answer).
  followupsRow: {
    display: 'flex',
    flexWrap: 'wrap',
    gap: 6,
    padding: '6px 0 2px',
  },
  followupChip: {
    background: '#e8f0fe',
    border: '1px solid #d2e3fc',
    borderRadius: 14,
    padding: '4px 12px',
    fontSize: 12,
    color: '#1a73e8',
    cursor: 'pointer',
    maxWidth: '100%',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
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
    position: 'relative' as const, // anchor for the edit icon (last user message)
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
  statusTag: {
    fontSize: 11,
    marginBottom: 4,
  },
  // ---- 最后一条 user 消息编辑态 ----
  editWrap: {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: 6,
    minWidth: 200,
  },
  editTextArea: {
    resize: 'none' as const,
    background: '#fff',
  },
  editActions: {
    display: 'flex',
    justifyContent: 'flex-end',
    gap: 6,
  },
  // hover 浮现的"编辑问题"图标定位在 user 气泡左下角外侧。默认 opacity:0,
  // 定位(left/bottom)与过渡(transition)走内联 style; 显隐 opacity 由渲染时的
  // isUserBubbleHovered state 动态注入(不在此固定, 也不依赖 CSS :hover 伪类)。
  editIconWrap: {
    position: 'absolute' as const,
    left: -26,
    bottom: 0,
    transition: 'opacity 0.15s',
  },
  editIcon: {
    fontSize: 13,
    color: '#999',
    cursor: 'pointer' as const,
  },
};
