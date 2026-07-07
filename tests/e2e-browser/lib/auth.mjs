// tests/e2e-browser/lib/auth.mjs
// 登录复用 local-dev-credentials memory 文件（运行时读取，凭据不入 git）。
// 字段/路由核实自 app/Admin/View/default/Public/login.html + PublicController::login。
import { readFileSync } from 'node:fs';
import { SELECTORS, ROUTES, DEFAULT_BASE_URL } from './selectors.mjs';

const DEFAULT_CRED_PATH =
  '/root/.claude/projects/-var-www-qs-chat2viz/memory/local-dev-credentials.md';

// 解析 memory 文件提取账号/密码（格式："- 账号：`xxx`" / "- 密码：`xxx`"）
function parseCredentials(text) {
  const uid = text.match(/账号[：:]\s*`?([^`\s]+)`?/)?.[1];
  const pwd = text.match(/密码[：:]\s*`?([^`\s]+)`?/)?.[1];
  if (!uid || !pwd) {
    throw new Error('Could not parse uid/pwd from credentials file (expected "- 账号：`x`" / "- 密码：`x`")');
  }
  return { uid, pwd };
}

export function getCredentials() {
  // 1. 环境变量优先（CI 可注入，避免依赖 memory 文件路径）
  if (process.env.CHAT2VIZ_TEST_USER && process.env.CHAT2VIZ_TEST_PASS) {
    return { uid: process.env.CHAT2VIZ_TEST_USER, pwd: process.env.CHAT2VIZ_TEST_PASS };
  }
  // 2. memory 文件（本地开发）
  const path = process.env.LOCAL_DEV_CREDENTIALS_PATH || DEFAULT_CRED_PATH;
  return parseCredentials(readFileSync(path, 'utf8'));
}

/**
 * 登录 admin 后台。返回已认证的 page（调用方负责 page 生命周期）。
 * @param {import('playwright').Page} page
 * @param {string} [baseUrl] 默认 DEFAULT_BASE_URL
 */
export async function loginAdmin(page, baseUrl = DEFAULT_BASE_URL) {
  if (!baseUrl) {
    throw new Error(
      'BASE_URL 未设置。请 export BASE_URL=http://your-test-host（或 CI 注入）。',
    );
  }
  const { uid, pwd } = getCredentials();
  await page.goto(`${baseUrl}${ROUTES.login}`);
  // 验证码兜底：本地开发库应关闭（isShowVerify()）。若出现则报错，避免静默失败
  const verifyVisible = await page
    .locator(SELECTORS.loginVerify)
    .isVisible()
    .catch(() => false);
  if (verifyVisible) {
    throw new Error(
      '登录页要求验证码（isShowVerify=true）。请关闭本地验证码，或扩展 lib/auth.mjs 处理。',
    );
  }
  await page.locator(SELECTORS.loginUid).fill(uid);
  await page.locator(SELECTORS.loginPwd).fill(pwd);
  await page.locator(SELECTORS.loginSubmit).click();
  // 登录成功跳转 Dashboard/index（PublicController::login success → U('Dashboard/index')）
  await page.waitForURL(/Dashboard\/index|Chat2VizDashboard/i, { timeout: 30000 });
  return page;
}
