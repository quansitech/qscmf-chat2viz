// tests/e2e-browser/lib/sse.mjs
// 前端 SSE 帧序工具。
//
// 重要：浏览器看到的事件经 src/Sse/Nl2sqlEventTransformer.php 转换，
// 与 Python 原始事件不同：
//   message_start      -> conversation_id
//   content_block_delta-> answer
//   message_stop       -> done
//   tool_start/result  -> 透传
//   DASHBOARD_REPLACE  -> 透传（整树唯一交付）
//   WIDGET_ERROR       -> 透传（mid-stream 单 widget 降级）
// 锚点：src/Sse/Nl2sqlEventTransformer.php:43-83

export const SSE_EVENTS = {
  CONVERSATION_ID: 'conversation_id',
  ANSWER: 'answer',
  TOOL_START: 'tool_start',
  TOOL_RESULT: 'tool_result',
  DASHBOARD_REPLACE: 'DASHBOARD_REPLACE',
  WIDGET_ERROR: 'WIDGET_ERROR',
  DASHBOARD_NOTICE: 'dashboard_notice',
  DONE: 'done',
  ERROR: 'error',
};

// 典型完整帧序（前端视角，PHP 转换后；answer 可重复）
export const TYPICAL_FRAME_ORDER = [
  SSE_EVENTS.CONVERSATION_ID,
  SSE_EVENTS.ANSWER,
  SSE_EVENTS.DASHBOARD_REPLACE,
  SSE_EVENTS.DONE,
];

const DEFAULT_TIMEOUT_MS = 60000;

/**
 * 安装 SSE 拦截器：覆盖 window.fetch，记录 api_ask_stream 调用，
 * 并 best-effort tee 解析流，记录 event 帧序列（不破坏真实流）。
 */
export async function installSseInterceptor(page) {
  await page.evaluate(() => {
    window.__testSseLog = [];
    window.__testSseFrames = [];
    const origFetch = window.fetch;
    window.fetch = async function (...args) {
      const url = typeof args[0] === 'string' ? args[0] : args[0]?.url;
      if (url && url.includes('api_ask_stream')) {
        window.__testSseLog.push({ url, time: Date.now() });
        const resp = await origFetch.apply(this, args);
        try {
          const [a, b] = resp.body.tee();
          (async () => {
            const reader = b.getReader();
            const decoder = new TextDecoder();
            let buf = '';
            while (true) {
              const { done, value } = await reader.read();
              if (done) break;
              buf += decoder.decode(value, { stream: true });
              let idx;
              while ((idx = buf.indexOf('\n\n')) >= 0) {
                const frame = buf.slice(0, idx);
                buf = buf.slice(idx + 2);
                const m = frame.match(/^event:\s*(\S+)/m);
                if (m) window.__testSseFrames.push(m[1]);
              }
            }
          })();
          return new Response(a, { headers: resp.headers, status: resp.status });
        } catch {
          return resp;
        }
      }
      return origFetch.apply(this, args);
    };
  });
}

export async function getSseLog(page) {
  return page.evaluate(() => window.__testSseLog || []);
}

export async function getFrameLog(page) {
  return page.evaluate(() => window.__testSseFrames || []);
}

/** 断言至少一次 api_ask_stream 调用 */
export async function assertSseCalled(page) {
  const log = await getSseLog(page);
  if (log.length === 0) throw new Error('No api_ask_stream SSE request was made');
  return log;
}

/** 等待指定 event 帧出现（前端视角） */
export async function waitForFrame(page, eventType, timeout = DEFAULT_TIMEOUT_MS) {
  await page.waitForFunction(
    (ev) => (window.__testSseFrames || []).includes(ev),
    eventType,
    { timeout },
  );
}

/**
 * 断言帧序：expectedSequence 作为子序列出现在实际帧流中。
 * answer/tool_result 等可重复帧在序列中只占一位（子序列匹配）。
 */
export async function assertFrameOrder(page, expectedSequence) {
  const frames = await getFrameLog(page);
  let i = 0;
  for (const f of frames) {
    if (i < expectedSequence.length && f === expectedSequence[i]) i += 1;
  }
  if (i < expectedSequence.length) {
    throw new Error(
      `Frame order mismatch: expected subsequence ${JSON.stringify(expectedSequence)}, ` +
      `got ${JSON.stringify(frames)}`,
    );
  }
  return frames;
}
