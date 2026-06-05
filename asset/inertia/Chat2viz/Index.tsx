import React, { useState, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { Input, List, Typography } from 'antd';
import G2Renderer from './G2Renderer';
import { createSseProcessor, type SseEvent } from './sse-parser';

// --- Types ---

interface AiMessage {
  readonly role: 'user' | 'ai' | 'error';
  readonly content: string;
  readonly sql?: string;
  readonly g2_spec?: Record<string, unknown>;
  readonly toolLabel?: string;
}

interface ApiSuccess {
  readonly status: 1;
  readonly data: {
    readonly answer: string;
    readonly sql: string;
    readonly g2_spec: Record<string, unknown> | null;
    readonly conversation_id: string | null;
  };
}

interface ApiError {
  readonly status: 0;
  readonly info: string;
}

type ApiResponse = ApiSuccess | ApiError;

function isApiSuccess(res: ApiResponse): res is ApiSuccess {
  return res.status === 1;
}

const toolLabels: Record<string, string> = {
  search_objects: '搜索相关表...',
  describe_table: '查看表结构...',
  execute_sql: '执行查询...',
};

interface PageProps {
  meta_title: string;
}

const { Text } = Typography;

const Index: React.FC = () => {
  const { meta_title: metaTitle } = usePage().props as PageProps;
  const [messages, setMessages] = useState<AiMessage[]>([]);
  const [question, setQuestion] = useState<string>('');
  const [loading, setLoading] = useState<boolean>(false);
  const abortRef = useRef<AbortController | null>(null);

  const handleAskStream = async (value: string): Promise<void> => {
    const q = value.trim();
    if (!q || loading) {
      return;
    }

    setMessages((prev) => [...prev, { role: 'user', content: q }]);
    setQuestion('');
    setLoading(true);

    // SSE streaming path
    const controller = new AbortController();
    abortRef.current = controller;

    let currentAnswer = '';
    let currentSql = '';
    let currentG2Spec: Record<string, unknown> | undefined;
    let currentToolLabel = '';

    try {
      const res = await fetch('/extends/Chat2Viz/api_ask_stream', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: q }),
        signal: controller.signal,
      });

      // Non-JSON response (e.g. 500) — fall back to JSON parsing
      const contentType = res.headers.get('content-type') ?? '';
      if (!contentType.includes('text/event-stream')) {
        const json: ApiResponse = await res.json();
        if (isApiSuccess(json)) {
          setMessages((prev) => [
            ...prev,
            {
              role: 'ai',
              content: json.data.answer || '分析完成',
              sql: json.data.sql || undefined,
              g2_spec: json.data.g2_spec ?? undefined,
            },
          ]);
        } else {
          setMessages((prev) => [
            ...prev,
            { role: 'error', content: json.info || '请求失败' },
          ]);
        }
        return;
      }

      // ReadableStream SSE consumption
      const reader = res.body?.getReader();
      if (!reader) {
        setMessages((prev) => [
          ...prev,
          { role: 'error', content: '浏览器不支持流式渲染' },
        ]);
        return;
      }

      const decoder = new TextDecoderStream();
      const readableStream = new ReadableStream({
        start(controller) {
          function pump(): Promise<void> {
            return reader.read().then(({ done, value }) => {
              if (done) {
                controller.close();
                return;
              }
              controller.enqueue(value);
              return pump();
            });
          }
          return pump();
        },
      });

      const textStream = readableStream.pipeThrough(decoder);
      const textReader = textStream.getReader();

      const processor = createSseProcessor((event: SseEvent) => {
        switch (event.type) {
          case 'answer':
            currentAnswer += (event.data as { delta: string }).delta;
            currentToolLabel = '';
            break;
          case 'sql':
            currentSql = (event.data as { sql: string }).sql;
            break;
          case 'tool': {
            const toolName = (event.data as { name: string }).name;
            currentToolLabel = toolLabels[toolName] ?? toolName;
            break;
          }
          case 'g2_spec':
            currentG2Spec = event.data as Record<string, unknown>;
            break;
          case 'done':
            currentToolLabel = '';
            break;
          case 'error': {
            const errorData = event.data as { info?: string; error?: { message: string } };
            const errorMsg = errorData.info ?? errorData.error?.message ?? '未知错误';
            setMessages((prev) => [
              ...prev,
              { role: 'error', content: errorMsg },
            ]);
            break;
          }
        }
      });

      while (true) {
        const { done, value } = await textReader.read();
        if (done) break;
        processor.processChunk(value);
      }
      processor.flush();

      // Commit final message
      if (currentAnswer || currentSql || currentG2Spec) {
        setMessages((prev) => [
          ...prev,
          {
            role: 'ai',
            content: currentAnswer || '分析完成',
            sql: currentSql || undefined,
            g2_spec: currentG2Spec,
          },
        ]);
      }
    } catch (err: unknown) {
      if (err instanceof DOMException && err.name === 'AbortError') {
        return;
      }
      setMessages((prev) => [
        ...prev,
        { role: 'error', content: '网络错误，请稍后重试' },
      ]);
    } finally {
      setLoading(false);
      abortRef.current = null;
    }
  };

  const handleAskOnce = async (value: string): Promise<void> => {
    const q = value.trim();
    if (!q || loading) {
      return;
    }

    setMessages((prev) => [...prev, { role: 'user', content: q }]);
    setQuestion('');
    setLoading(true);

    try {
      const res = await fetch('/extends/Chat2Viz/api_ask', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ question: q }),
      });
      const json: ApiResponse = await res.json();

      if (isApiSuccess(json)) {
        setMessages((prev) => [
          ...prev,
          {
            role: 'ai',
            content: json.data.answer || '分析完成',
            sql: json.data.sql || undefined,
            g2_spec: json.data.g2_spec ?? undefined,
          },
        ]);
      } else {
        setMessages((prev) => [
          ...prev,
          { role: 'error', content: json.info || '请求失败' },
        ]);
      }
    } catch {
      setMessages((prev) => [
        ...prev,
        { role: 'error', content: '网络错误，请稍后重试' },
      ]);
    } finally {
      setLoading(false);
    }
  };

  // Use streaming when ReadableStream + TextDecoderStream are available
  const supportsStreaming = typeof ReadableStream !== 'undefined'
    && typeof TextDecoderStream !== 'undefined';
  const handleAsk = supportsStreaming ? handleAskStream : handleAskOnce;

  return (
    <div style={{ padding: 24, maxWidth: 900, margin: '0 auto' }}>
      <h2>{metaTitle}</h2>

      <List
        dataSource={messages as AiMessage[]}
        renderItem={(item: AiMessage) => (
          <List.Item key={`${item.role}-${item.content}`}>
            {item.role === 'user' && (
              <div><strong>你:</strong> {item.content}</div>
            )}
            {item.role === 'ai' && (
              <div style={{ width: '100%' }}>
                {item.toolLabel && (
                  <div style={{ color: '#888', fontSize: 12 }}>{item.toolLabel}</div>
                )}
                <div>{item.content}</div>
                {item.sql && (
                  <pre
                    style={{
                      background: '#f5f5f5',
                      padding: 8,
                      marginTop: 8,
                      borderRadius: 4,
                      overflow: 'auto',
                    }}
                  >
                    {item.sql}
                  </pre>
                )}
                {item.g2_spec && <G2Renderer spec={item.g2_spec} />}
              </div>
            )}
            {item.role === 'error' && (
              <Text type="danger">{item.content}</Text>
            )}
          </List.Item>
        )}
      />

      <Input.Search
        value={question}
        onChange={(e) => setQuestion(e.target.value)}
        onSearch={handleAsk}
        enterButton="提问"
        loading={loading}
        disabled={loading}
        placeholder="输入你的数据问题..."
        style={{ marginTop: 16 }}
      />
    </div>
  );
};

export default Index;
