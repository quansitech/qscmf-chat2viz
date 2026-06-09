import { useRef, useCallback } from 'react';
import { useDashboardStore } from '../store/dashboardStore';
import type {
  Widget,
  ActionCall,
  ActionCallResult,
  DashboardPatch,
} from '../store/dashboardStore';
import { parseSseEvent, type SseEvent } from '../sse-parser';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const SSE_ENDPOINT = '/extends/Chat2Viz/api_ask_stream';
const MAX_RETRIES = 2;
const RETRY_DELAY_MS = 1000;

// ---------------------------------------------------------------------------
// Helpers for extracting typed values from untyped SSE data
// ---------------------------------------------------------------------------

function str(val: unknown, fallback = ''): string {
  return typeof val === 'string' ? val : fallback;
}

function bool(val: unknown, fallback = false): boolean {
  return typeof val === 'boolean' ? val : fallback;
}

function obj<T = Record<string, unknown>>(val: unknown, fallback = {} as T): T {
  return val !== null && typeof val === 'object' && !Array.isArray(val)
    ? (val as T)
    : fallback;
}

function arr<T>(val: unknown, fallback: T[] = []): T[] {
  return Array.isArray(val) ? (val as T[]) : fallback;
}

// ---------------------------------------------------------------------------
// Hook
// ---------------------------------------------------------------------------

export function useSseStream() {
  const abortRef = useRef<AbortController | null>(null);

  const sendQuestion = useCallback(async (question: string) => {
    const store = useDashboardStore.getState();

    // Cancel any in-flight request
    if (abortRef.current) {
      abortRef.current.abort();
    }

    const controller = new AbortController();
    abortRef.current = controller;

    store.startConversation(question);

    const payload: Record<string, unknown> = {
      question,
      dashboard_context: store.getDashboardContext(),
    };

    if (store.conversationId) {
      payload.conversation_id = store.conversationId;
    }

    let attempt = 0;

    while (attempt <= MAX_RETRIES) {
      try {
        const response = await fetch(SSE_ENDPOINT, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        if (!response.body) {
          throw new Error('Response body is null; streaming not supported');
        }

        // Consume the SSE stream
        await consumeStream(response.body, controller.signal);

        useDashboardStore.getState().completeConversation();

        // Auto-save is handled by useDashboardDraft's store subscription,
        // which detects isDirty transitions and triggers debounced saves.

        return;
      } catch (err) {
        if (controller.signal.aborted) {
          return;
        }

        attempt++;

        if (attempt > MAX_RETRIES) {
          const message = err instanceof Error ? err.message : '流式请求失败';
          useDashboardStore.getState().setError(message);
          return;
        }

        // Wait before retrying
        await delay(RETRY_DELAY_MS, controller.signal);
      }
    }
  }, []);

  const cancel = useCallback(() => {
    if (abortRef.current) {
      abortRef.current.abort();
      abortRef.current = null;
      useDashboardStore.getState().completeConversation();
    }
  }, []);

  return { sendQuestion, cancel };
}

// ---------------------------------------------------------------------------
// Stream consumer
// ---------------------------------------------------------------------------

async function consumeStream(body: ReadableStream<Uint8Array>, signal: AbortSignal): Promise<void> {
  const reader = body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  try {
    while (true) {
      if (signal.aborted) {
        reader.cancel();
        return;
      }

      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      // SSE frames are separated by double newlines.
      const frames = buffer.split('\n\n');
      // Keep the last incomplete frame in the buffer
      buffer = frames.pop() ?? '';

      for (const frame of frames) {
        if (!frame.trim()) continue;
        dispatchEvent(parseSseEvent(frame));
      }
    }

    // Process any remaining data in the buffer
    if (buffer.trim()) {
      dispatchEvent(parseSseEvent(buffer));
    }
  } finally {
    reader.releaseLock();
  }
}

// ---------------------------------------------------------------------------
// Event dispatch
// ---------------------------------------------------------------------------

function dispatchEvent(event: SseEvent | null): void {
  if (!event) return;

  const store = useDashboardStore.getState();

  switch (event.type) {
    case 'chart_ready': {
      if (!validateWidget(event.data)) {
        // Silently skip invalid chart data to prevent UI disruption
        break;
      }
      store.addPanel(event.data as unknown as Widget);
      break;
    }

    case 'action_call': {
      const action: ActionCall = {
        action_type: str(event.data.action_type),
        params: obj<Record<string, unknown>>(event.data.params),
      };
      store.executeAction(action);
      break;
    }

    case 'action_call_result': {
      const result: ActionCallResult = {
        success: bool(event.data.success),
        result: event.data.result,
      };
      applyActionResult(result);
      break;
    }

    case 'dashboard_patch': {
      const patches = arr<DashboardPatch>(event.data.patches);
      applyPatches(patches);
      break;
    }

    case 'answer': {
      store.appendAnswer(str(event.data.text));
      break;
    }

    case 'conversation_id': {
      store.setConversationId(str(event.data.conversation_id));
      break;
    }

    case 'error': {
      store.setError(str(event.data.info) || str(event.data.message) || '未知错误');
      break;
    }

    default: {
      // Unknown event types are silently ignored
      break;
    }
  }
}

// ---------------------------------------------------------------------------
// SSE data validation
// ---------------------------------------------------------------------------

function validateWidget(data: unknown): data is Widget {
  if (typeof data !== 'object' || data === null) return false;
  const d = data as Record<string, unknown>;
  return typeof d.id === 'string' && d.id.length > 0;
}

// ---------------------------------------------------------------------------
// Patch application
// ---------------------------------------------------------------------------

function applyPatches(patches: DashboardPatch[]): void {
  if (patches.length === 0) return;

  const store = useDashboardStore.getState();

  for (const patch of patches) {
    // path format: "/widgets/{id}/field" or "/title" etc.
    const segments = patch.path.split('/').filter(Boolean);

    if (segments[0] === 'widgets' && segments.length >= 2) {
      const widgetId = segments[1];

      if (patch.op === 'remove') {
        store.removePanel(widgetId);
        continue;
      }

      if (patch.op === 'add' || patch.op === 'replace') {
        if (segments.length === 2) {
          if (patch.op === 'replace' && patch.value) {
            store.updateWidget(widgetId, patch.value as unknown as Partial<Widget>);
          } else if (patch.op === 'add' && patch.value) {
            store.addPanel(patch.value as unknown as Widget);
          }
        } else {
          const field = segments[2];
          store.updateWidget(widgetId, { [field]: patch.value } as unknown as Partial<Widget>);
        }
      }
    } else if (segments[0] === 'title' && patch.value !== undefined) {
      useDashboardStore.setState({ title: String(patch.value) });
    }
  }
}

function applyActionResult(result: ActionCallResult): void {
  // Use the immer-enabled setState to record the action result on the
  // last assistant message.
  const state = useDashboardStore.getState();
  const messages = [...state.messages];
  const lastAssistant = [...messages]
    .reverse()
    .find((m) => m.role === 'assistant');
  if (lastAssistant) {
    const metadata = {
      ...(lastAssistant.metadata ?? {}),
    };
    const actionResults = [...(metadata.actionResults ?? []), result];
    metadata.actionResults = actionResults;
    lastAssistant.metadata = metadata;
    useDashboardStore.setState({ messages });
  }
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function delay(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms);
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer);
        reject(new DOMException('Aborted', 'AbortError'));
      },
      { once: true },
    );
  });
}
