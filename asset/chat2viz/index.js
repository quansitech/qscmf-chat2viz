(function () {
  var mountEl = document.getElementById('chat2viz-app');
  if (!mountEl) return;

  var messages = [];
  var loading = false;
  var conversationId = null;
  var chartInstances = {};
  var serviceUnavailable = false;
  // Feedback / implicit tracking state
  var convId = '';
  var lastMessageId = null;
  var feedbackUrl = '/extends/Chat2Viz/api_feedback';
  var industryOverride = '';  // '' = auto-detect

  // --- Socket Health Check ---
  function checkSocketHealth() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/extends/Chat2Viz/api_socket_health', true);
    xhr.timeout = 6000;
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4) return;
      if (xhr.status !== 200) {
        serviceUnavailable = true;
        messages.push({ role: 'error', content: '分析服务不可用，请检查后端服务状态' });
        render();
        disableControls();
      }
    };
    xhr.onerror = xhr.ontimeout = function () {
      serviceUnavailable = true;
      messages.push({ role: 'error', content: '分析服务不可用，请检查后端服务状态' });
      render();
      disableControls();
    };
    xhr.send();
  }

  function disableControls() {
    var inputEl = document.getElementById('chat2viz-input');
    var btnEl = document.getElementById('chat2viz-btn');
    if (inputEl) inputEl.disabled = true;
    if (btnEl) btnEl.disabled = true;
  }

  // --- SSE Parser Utilities (T3) ---

  function parseSseBlock(block) {
    var eventType = '';
    var dataStr = '';
    var lines = block.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (line.indexOf('event:') === 0) {
        eventType = line.substring(6).trim();
      } else if (line.indexOf('data:') === 0) {
        dataStr = dataStr ? (dataStr + '\n' + line.substring(5).trim()) : line.substring(5).trim();
      }
    }
    var data = {};
    if (dataStr) {
      try { data = JSON.parse(dataStr); } catch (e) { /* ignore bad JSON */ }
    }
    return { type: eventType, data: data };
  }

  function createSseProcessor(onEvent) {
    var buffer = '';

    function processChunk(text) {
      buffer += text;
      var parts = buffer.split('\n\n');
      buffer = parts.pop();
      for (var i = 0; i < parts.length; i++) {
        var block = parts[i].trim();
        if (!block || block.charAt(0) === ':') continue;
        var evt = parseSseBlock(block);
        onEvent(evt.type, evt.data);
      }
    }

    function flush() {
      if (buffer.trim()) {
        var block = buffer.trim();
        if (block.charAt(0) !== ':') {
          var evt = parseSseBlock(block);
          onEvent(evt.type, evt.data);
        }
        buffer = '';
      }
    }

    return { processChunk: processChunk, flush: flush };
  }

  // --- End SSE Parser ---

  // --- Tool Labels (T5) ---

  var toolLabels = {
    'search_objects': '搜索相关表...',
    'describe_table': '查看表结构...',
    'execute_sql': '执行查询...'
  };

  // --- DOM Helpers ---

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = String(str == null ? '' : str);
    return div.innerHTML;
  }

  function destroyCharts() {
    Object.keys(chartInstances).forEach(function (key) {
      if (chartInstances[key] && typeof chartInstances[key].destroy === 'function') {
        chartInstances[key].destroy();
      }
      delete chartInstances[key];
    });
  }

  function hasChartSpec(spec) {
    if (!spec || typeof spec !== 'object') return false;
    if (spec.type) return true;
    if (Array.isArray(spec.children) && spec.children.length) return true;
    return false;
  }

  // --- Full Render (used on init, after message_start, after error) ---

  function renderShell() {
    var html = '';
    html += '<div style="padding:24px;max-width:900px;margin:0 auto">';
    html += '<h2>智能分析</h2>';
    // Industry override dropdown (manual override of auto-detected industry)
    html += '<div style="margin-bottom:12px;font-size:13px;color:#666">';
    html += '行业：<select id="chat2viz-industry" style="padding:2px 6px">';
    html += '<option value="">自动识别</option>';
    html += '<option value="ecommerce"' + (industryOverride === 'ecommerce' ? ' selected' : '') + '>电商零售</option>';
    html += '<option value="saas"' + (industryOverride === 'saas' ? ' selected' : '') + '>SaaS 软件</option>';
    html += '<option value="manufacturing"' + (industryOverride === 'manufacturing' ? ' selected' : '') + '>制造业</option>';
    html += '<option value="finance"' + (industryOverride === 'finance' ? ' selected' : '') + '>金融</option>';
    html += '</select>';
    html += '</div>';

    html += '<div id="chat2viz-messages">';
    for (var i = 0; i < messages.length; i++) {
      var m = messages[i];
      if (m.role === 'user') {
        html += '<div style="margin:8px 0"><strong>你:</strong> ' + escapeHtml(m.content) + '</div>';
      } else if (m.role === 'ai') {
        html += '<div style="margin:8px 0">';
        html += '<span id="chat2viz-text-' + i + '">' + escapeHtml(m.content) + '</span>';
        if (m.sql) {
          html += '<pre id="chat2viz-sql-' + i + '" style="background:#f5f5f5;padding:8px;margin-top:8px;border-radius:4px;overflow:auto">' + escapeHtml(m.sql) + '</pre>';
        }
        if (m.dataInfo) {
          html += '<div id="chat2viz-data-' + i + '" style="margin-top:4px;color:#888;font-size:12px">' + escapeHtml(m.dataInfo) + '</div>';
        }
        if (m.g2_spec && hasChartSpec(m.g2_spec)) {
          html += '<div id="g2-chart-' + i + '" style="min-height:300px;margin-top:8px"></div>';
        }
        if (m.status) {
          html += '<div id="chat2viz-status-' + i + '" style="margin-top:4px;color:#1890ff;font-size:12px">' + escapeHtml(m.status) + '</div>';
        }
        // Feedback controls (👍/👎 + optional comment) — only for completed AI messages
        if (m.messageId && !m.streaming) {
          html += '<div id="chat2viz-feedback-' + i + '" style="margin-top:6px;display:flex;align-items:center;gap:8px;font-size:13px">';
          html += '<span style="color:#999">这个回答有帮助吗？</span>';
          html += '<button data-feedback="up" data-msg="' + m.messageId + '" data-idx="' + i + '" style="border:none;background:none;cursor:pointer;font-size:16px;padding:2px 4px" title="有帮助">👍</button>';
          html += '<button data-feedback="down" data-msg="' + m.messageId + '" data-idx="' + i + '" style="border:none;background:none;cursor:pointer;font-size:16px;padding:2px 4px" title="需改进">👎</button>';
          html += '<span style="color:#bbb;font-size:11px">反馈用于改进 AI 质量</span>';
          html += '</div>';
        }
        html += '</div>';
      } else if (m.role === 'error') {
        html += '<div style="margin:8px 0;color:#ff4d4f">' + escapeHtml(m.content) + '</div>';
      }
    }
    html += '</div>';

    html += '<div style="margin-top:16px;display:flex;gap:8px">';
    html += '<input id="chat2viz-input" type="text" placeholder="输入你的数据问题..." style="flex:1;padding:8px" />';
    html += '<button id="chat2viz-btn"' + (loading ? ' disabled' : '') + '>' + (loading ? '分析中...' : '提问') + '</button>';
    html += '</div></div>';

    mountEl.innerHTML = html;

    var inputEl = document.getElementById('chat2viz-input');
    if (inputEl) {
      inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !loading) {
          window.__chat2viz_ask();
        }
      });
    }
    var btnEl = document.getElementById('chat2viz-btn');
    if (btnEl) {
      btnEl.addEventListener('click', function () {
        if (!loading) window.__chat2viz_ask();
      });
    }
    // Wire feedback buttons (👍/👎)
    var fbBtns = document.querySelectorAll('[data-feedback]');
    for (var j = 0; j < fbBtns.length; j++) {
      fbBtns[j].addEventListener('click', function (ev) {
        var thumbs = ev.target.getAttribute('data-feedback');
        var msgId = ev.target.getAttribute('data-msg');
        sendFeedback({ message_id: msgId, thumbs: thumbs, conversation_id: convId });
        // Visual feedback
        ev.target.style.opacity = '1';
        var siblings = ev.target.parentNode.querySelectorAll('[data-feedback]');
        for (var k = 0; k < siblings.length; k++) {
          if (siblings[k] !== ev.target) siblings[k].style.opacity = '0.3';
        }
      });
    }
  }

  // --- Feedback submission ---
  function sendFeedback(payload) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', feedbackUrl, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify(payload));
    } catch (e) {
      // Silent failure — feedback is best-effort, must not disrupt the UI
    }
  }

  // --- Implicit signal tracking ---
  var lastQuestion = '';
  var lastQuestionTime = 0;
  function trackImplicit(signal) {
    if (!lastMessageId) return;
    sendFeedback({
      message_id: lastMessageId,
      conversation_id: convId,
      implicit_signals: signal
    });
  }

  function renderCharts() {
    if (typeof G2 === 'undefined') return;
    for (var i = 0; i < messages.length; i++) {
      var m = messages[i];
      if (m.role === 'ai' && m.g2_spec && hasChartSpec(m.g2_spec) && !chartInstances['chart-' + i]) {
        var el = document.getElementById('g2-chart-' + i);
        if (!el) continue;
        var chart = new G2.Chart({ container: el, autoFit: true });
        chart.options(m.g2_spec).render();
        chartInstances['chart-' + i] = chart;
      }
    }
  }

  function render() {
    destroyCharts();
    renderShell();
    renderCharts();
  }

  // --- Stream Endpoint (T4 + T5) ---
  // Graceful degradation: if response.body.getReader is unavailable,
  // reads full response as text and parses SSE events in one batch.
  // The sync api_ask endpoint remains available as a server-side fallback.

  function callStreamEndpoint(question) {
    var payload = { question: question };
    if (conversationId) payload.conversation_id = conversationId;
    // Read industry override from dropdown (empty = auto-detect)
    var indEl = document.getElementById('chat2viz-industry');
    if (indEl) {
      industryOverride = indEl.value;
      if (industryOverride) payload.industry = industryOverride;
    }

    var streamEndedCleanly = false;
    var msgIdx = -1;

    function handleEvent(type, data) {
      switch (type) {
        case 'message_start':
          if (data.conversation_id) conversationId = data.conversation_id;
          if (data.conversation_id) convId = data.conversation_id;
          if (data.message_id) lastMessageId = data.message_id;
          messages.push({ role: 'ai', content: '', sql: null, g2_spec: null, status: '', dataInfo: '', streaming: true, messageId: data.message_id || null });
          msgIdx = messages.length - 1;
          render();
          break;

        case 'tool_start':
          if (msgIdx < 0) break;
          messages[msgIdx].status = toolLabels[data.tool] || '处理中...';
          updateStatusEl(msgIdx);
          break;

        case 'tool_result':
          if (msgIdx < 0) break;
          messages[msgIdx].status = data.summary || '';
          updateStatusEl(msgIdx);
          break;

        case 'message_delta':
          if (msgIdx < 0) break;
          messages[msgIdx].status = data.status || '';
          updateStatusEl(msgIdx);
          break;

        case 'sql_ready':
          if (msgIdx < 0) break;
          messages[msgIdx].sql = data.sql || '';
          updateSqlEl(msgIdx);
          break;

        case 'data_ready':
          if (msgIdx < 0) break;
          messages[msgIdx].dataInfo = '返回 ' + (data.total || 0) + ' 行数据';
          updateDataEl(msgIdx);
          break;

        case 'content_block_start':
          break;

        case 'content_block_delta':
          if (msgIdx < 0) break;
          messages[msgIdx].content += (data.delta && data.delta.text) || '';
          updateTextEl(msgIdx);
          break;

        case 'content_block_stop':
          break;

        case 'chart_ready':
          if (msgIdx < 0) break;
          messages[msgIdx].g2_spec = data.g2_spec || null;
          renderChartForMsg(msgIdx);
          break;

        case 'message_stop':
          streamEndedCleanly = true;
          if (msgIdx >= 0) {
            messages[msgIdx].status = '';
            messages[msgIdx].complete = true;
            messages[msgIdx].streaming = false;  // show feedback buttons
          }
          loading = false;
          render();
          break;

        case 'error':
          var errMsg = (data.error && data.error.message) || (typeof data.error === 'string' ? data.error : '分析服务错误');
          if (msgIdx >= 0) {
            // Preserve any partial content already received
            if (messages[msgIdx].content) {
              messages[msgIdx].content += '\n\n' + errMsg;
            } else {
              messages[msgIdx].content = errMsg;
            }
            messages[msgIdx].status = '';
            messages[msgIdx].complete = true;
          } else {
            messages.push({ role: 'error', content: errMsg });
          }
          loading = false;
          render();
          break;

        default:
          break;
      }
    }

    function updateStatusEl(idx) {
      var el = document.getElementById('chat2viz-status-' + idx);
      if (el) el.textContent = messages[idx].status || '';
    }

    function updateSqlEl(idx) {
      var existing = document.getElementById('chat2viz-sql-' + idx);
      if (existing) {
        existing.textContent = messages[idx].sql || '';
        return;
      }
      // Insert SQL block after the text span
      var textEl = document.getElementById('chat2viz-text-' + idx);
      if (textEl && textEl.parentNode) {
        var pre = document.createElement('pre');
        pre.id = 'chat2viz-sql-' + idx;
        pre.style.cssText = 'background:#f5f5f5;padding:8px;margin-top:8px;border-radius:4px;overflow:auto';
        pre.textContent = messages[idx].sql || '';
        textEl.parentNode.insertBefore(pre, textEl.nextSibling);
      }
    }

    function updateDataEl(idx) {
      var existing = document.getElementById('chat2viz-data-' + idx);
      if (existing) {
        existing.textContent = messages[idx].dataInfo || '';
        return;
      }
      var sqlEl = document.getElementById('chat2viz-sql-' + idx);
      if (sqlEl && sqlEl.parentNode) {
        var div = document.createElement('div');
        div.id = 'chat2viz-data-' + idx;
        div.style.cssText = 'margin-top:4px;color:#888;font-size:12px';
        div.textContent = messages[idx].dataInfo || '';
        sqlEl.parentNode.insertBefore(div, sqlEl.nextSibling);
      }
    }

    function updateTextEl(idx) {
      var el = document.getElementById('chat2viz-text-' + idx);
      if (el) el.textContent = messages[idx].content;
    }

    function renderChartForMsg(idx) {
      var m = messages[idx];
      if (!m.g2_spec || !hasChartSpec(m.g2_spec)) return;
      if (typeof G2 === 'undefined') return;

      // Ensure container exists
      var containerId = 'g2-chart-' + idx;
      var existingEl = document.getElementById(containerId);
      if (!existingEl) {
        var parent = document.getElementById('chat2viz-text-' + idx);
        if (parent) parent = parent.parentNode;
        if (parent) {
          var div = document.createElement('div');
          div.id = containerId;
          div.style.cssText = 'min-height:300px;margin-top:8px';
          parent.appendChild(div);
        }
      }

      var el = document.getElementById(containerId);
      if (!el) return;

      // Destroy previous chart for this message if any
      if (chartInstances['chart-' + idx]) {
        if (typeof chartInstances['chart-' + idx].destroy === 'function') {
          chartInstances['chart-' + idx].destroy();
        }
        delete chartInstances['chart-' + idx];
      }

      var chart = new G2.Chart({ container: el, autoFit: true });
      chart.options(m.g2_spec).render();
      chartInstances['chart-' + idx] = chart;
    }

    // Start the streaming fetch
    fetch('/extends/Chat2Viz/api_ask_stream', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (response) {
        var contentType = response.headers.get('Content-Type') || '';

        // Non-SSE response (validation error, etc.)
        if (contentType.indexOf('text/event-stream') === -1) {
          return response.json().then(function (json) {
            loading = false;
            messages.push({ role: 'error', content: (json && json.info) || '请求失败' });
            render();
            throw new Error('_handled');
          });
        }

        // Check ReadableStream support (T6)
        if (!response.body || typeof response.body.getReader !== 'function') {
          // Fallback: read as text and parse manually
          return response.text().then(function (text) {
            var processor = createSseProcessor(handleEvent);
            processor.processChunk(text);
            processor.flush();
            if (!streamEndedCleanly) {
              loading = false;
              render();
            }
          });
        }

        var reader = response.body.getReader();
        var decoder = new TextDecoder();
        var processor = createSseProcessor(handleEvent);

        function readChunk() {
          return reader.read().then(function (result) {
            if (result.done) {
              processor.flush();
              if (!streamEndedCleanly) {
                // Stream ended without message_stop
                handleEvent('error', { error: { message: '连接中断，请重新提问' } });
              }
              return;
            }
            var text = decoder.decode(result.value, { stream: true });
            processor.processChunk(text);
            return readChunk();
          });
        }

        return readChunk();
      })
      .catch(function (err) {
        if (err && err.message === '_handled') return;
        loading = false;
        messages.push({ role: 'error', content: '网络错误，请稍后重试' });
        render();
      });
  }

  // --- Main Ask Function ---

  window.__chat2viz_ask = function () {
    if (serviceUnavailable) return;
    var input = document.getElementById('chat2viz-input');
    if (!input) return;
    var q = input.value.trim();
    if (!q || loading) return;

    // Implicit feedback: detect re-ask within 30s (user likely dissatisfied)
    var now = Date.now();
    if (lastQuestion === q && (now - lastQuestionTime) < 30000) {
      trackImplicit({ regenerated: true });
    }
    lastQuestion = q;
    lastQuestionTime = now;

    messages.push({ role: 'user', content: q });
    loading = true;
    render();

    // Always try streaming first; callStreamEndpoint handles fallback
    // internally when response.body or getReader is unavailable
    callStreamEndpoint(q);
  };

  render();
  checkSocketHealth();
})();
