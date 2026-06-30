/**
 * 剪贴板复制: 优先 navigator.clipboard, 在非安全上下文(HTTP / 非 localhost,
 * navigator.clipboard 为 undefined)下 fallback 到 execCommand('copy') 的隐藏
 * textarea 方案. 三处复制(复制回答 / 复制公开链接 / 列表复制链接)统一走这里.
 *
 * code-review MED-3: 之前 DashboardList / PublishDialog / ChatPanel 各自内联了
 * 逐字相同的 fallbackCopy, 抽公共 util 统一, 缩小回归面.
 */

export function copyText(
  text: string,
  done: () => void,
  fail: () => void,
): void {
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopy(text, done, fail));
  } else {
    fallbackCopy(text, done, fail);
  }
}

function fallbackCopy(text: string, done: () => void, fail: () => void): void {
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
    fail();
  }
}
