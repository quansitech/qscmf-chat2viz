// tests/e2e-browser/lib/selectors.mjs
// 稳定锚点：从 .claude/workflows/test/0X-*.mjs 提取。
// 选择器优先级：data-testid > role > CSS class > text。
// 长期方向：推动前端补 data-testid，逐步淘汰 class 锚点。

export const SELECTORS = {
  // 登录（字段/路由核实自 app/Admin/View/default/Public/login.html + PublicController::login）
  // 后台用 uid/pwd（非 username/password）；verify 验证码可选（isShowVerify()）
  loginUid: 'input[name="uid"]',
  loginPwd: 'input[name="pwd"]',
  loginVerify: 'input[name="verify"]',
  loginSubmit: 'button[type="submit"].btn-mtx',

  // 编辑页 chat 面板（来源：02-chat-single-turn.mjs）
  chatPane: '.dashboard-edit-chat-pane',
  chatTextarea: '.dashboard-edit-chat-pane textarea',
  chatSendBtn: '.dashboard-edit-chat-pane button[class*="send"], .dashboard-edit-chat-pane .ant-btn-primary',
  chatSendIcon: '.dashboard-edit-chat-pane .anticon-send',
  exampleButtons: '.dashboard-edit-chat-pane button',

  // widget 渲染
  widgetCard: '.widget-card',
  widgetCanvas: '.widget-card canvas',
  emptyPreview: '.ant-empty',

  // 状态指示
  errorText: '.ant-typography-danger',
  loadingIndicator: 'text=分析中',
};

// 路由（与 src/Chat2VizServiceProvider.php 注册一致）
export const ROUTES = {
  login: '/Admin/Public/login.html',
  dashboardList: '/admin/Chat2VizDashboard/index',
  dashboardEdit: (id) => `/admin/Chat2VizDashboard/edit?id=${id}`,
  // extends 入口（02-*.mjs 使用的编辑页路径）
  extendsEdit: '/extends/Chat2VizDashboard/edit',
  publicView: (uid) => `/extends/Chat2VizDashboard/view/uid/${uid}`,
  // SSE 流式端点（src/Controller/Chat2VizController.php:164）
  apiAskStream: '/extends/Chat2Viz/api_ask_stream',
};

// 默认 BASE_URL —— 强制 env 注入，避免本地开发域名硬编码进仓库。
// 跑测试前必须 export BASE_URL=http://your-test-host（CI 注入或本地 .env）。
export const DEFAULT_BASE_URL = process.env.BASE_URL || '';
