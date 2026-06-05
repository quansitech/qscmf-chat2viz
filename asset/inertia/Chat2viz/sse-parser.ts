/**
 * SSE parser module — pure TypeScript, no React dependencies.
 *
 * Provides `parseSseBlock` for parsing a single SSE block and `createSseProcessor`
 * for streaming chunk-by-chunk consumption over a ReadableStream.
 *
 * Event types mirror the Python qs-chat2viz service wire protocol:
 *   answer | sql | tool | g2_spec | done | error
 */

// --- Types ---

export type SseEventType = 'answer' | 'sql' | 'tool' | 'g2_spec' | 'done' | 'error';

export interface AnswerEvent {
  readonly type: 'answer';
  readonly data: { delta: string };
}

export interface SqlEvent {
  readonly type: 'sql';
  readonly data: { sql: string };
}

export interface ToolEvent {
  readonly type: 'tool';
  readonly data: { name: string };
}

export interface G2Event {
  readonly type: 'g2_spec';
  readonly data: Record<string, unknown>;
}

export interface DoneEvent {
  readonly type: 'done';
  readonly data: Record<string, unknown>;
}

export interface ErrorEvent {
  readonly type: 'error';
  readonly data: { type: string; info?: string; error?: { code: string; message: string } };
}

export type SseEvent = AnswerEvent | SqlEvent | ToolEvent | G2Event | DoneEvent | ErrorEvent;

// --- Parser ---

/**
 * Parse a single raw SSE block (text between `\n\n` delimiters) into a typed SseEvent.
 *
 * Handles:
 * - `event:` line → type
 * - `data:` line(s) → parsed JSON or text fallback; multi-line data joined with `\n`
 * - `:` comment lines → returns null
 * - Empty blocks → returns null
 */
export function parseSseBlock(raw: string): SseEvent | null {
  const trimmed = raw.trim();
  if (trimmed === '') {
    return null;
  }

  // Comment-only block (heartbeat)
  const lines = trimmed.split('\n');
  const hasContent = lines.some((l) => l.trim() !== '');
  const isComment = lines.every((l) => {
    const t = l.trim();
    return t === '' || t.startsWith(':');
  });
  if (isComment || !hasContent) {
    return null;
  }

  let eventType = '';
  const dataLines: string[] = [];

  for (const line of lines) {
    const l = line.trim();
    if (l === '' || l.startsWith(':')) {
      continue;
    }
    if (l.startsWith('event:')) {
      eventType = l.substring(6).trim();
    } else if (l.startsWith('data:')) {
      dataLines.push(l.substring(5).trimStart());
    }
  }

  if (dataLines.length === 0) {
    return null;
  }

  const rawJson = dataLines.join('\n');
  let data: Record<string, unknown>;
  try {
    const parsed = JSON.parse(rawJson);
    data = typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
      ? parsed
      : { text: rawJson };
  } catch {
    data = { text: rawJson };
  }

  return { type: eventType as SseEventType, data } as SseEvent;
}

// --- Streaming processor ---

/**
 * Create an SSE streaming processor that buffers partial chunks and emits
 * complete events via the `onEvent` callback.
 */
export function createSseProcessor(onEvent: (e: SseEvent) => void): {
  processChunk: (text: string) => void;
  flush: () => void;
} {
  let buffer = '';

  function processChunk(text: string): void {
    buffer += text;
    const parts = buffer.split('\n\n');
    // The last element is the remainder (possibly incomplete)
    buffer = parts.pop()!;

    for (const part of parts) {
      const trimmed = part.trim();
      if (trimmed === '' || trimmed.startsWith(':')) {
        continue;
      }
      const evt = parseSseBlock(trimmed);
      if (evt !== null) {
        onEvent(evt);
      }
    }
  }

  function flush(): void {
    const trimmed = buffer.trim();
    if (trimmed !== '' && !trimmed.startsWith(':')) {
      const evt = parseSseBlock(trimmed);
      if (evt !== null) {
        onEvent(evt);
      }
    }
    buffer = '';
  }

  return { processChunk, flush };
}
