(() => {
  'use strict';

  const DEFAULTS = { baseUrl: 'https://digital-store.top', token: '', scopeAdminId: '' };

  let booted = false, observer = null, pollTimer = null;
  let debounceTimer = null, loadRunning = false, loadQueued = null;
  let _lastKey = '';
  let _lastChatSignal = '__none__';
  let _bootInfo = null, _bootInfoAt = 0;
  const _workflowStates = new Map();

  // ── Utilities ──────────────────────────────────────────────────────────────
  const esc = str => String(str ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

  const digits = str => (String(str || '').match(/\d+/g) || []).join('');

  const normalizePhone = raw => {
    const d = digits(raw);
    return (d.length >= 7 && d.length <= 15) ? d : '';
  };

  const nk = key => String(key || '').trim().toLowerCase();

  const _duesCache = new Map();
  const _tasksCache = new Map();
  let _currentCustomer = null;

  async function pasteToChat(text, autoSend = false) {
    if (!text) return false;
    try {
      // Seamlessly copy to clipboard as backup
      try {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
          navigator.clipboard.writeText(text).catch(() => {});
        }
      } catch (e) {}

      const composer =
        document.querySelector('#main footer div[contenteditable="true"]') ||
        document.querySelector('footer div[contenteditable="true"]') ||
        document.querySelector('div[contenteditable="true"][role="textbox"]:not([aria-label*="Search"])');

      if (!composer) {
        toast('টেক্সট কপি হয়েছে (Ctrl+V চাপুন)');
        return false;
      }

      composer.focus();

      // Clear any leftover range and place cursor
      const sel = window.getSelection();
      if (sel) {
        sel.removeAllRanges();
        const range = document.createRange();
        range.selectNodeContents(composer);
        range.collapse(false);
        sel.addRange(range);
      }

      // Insert text line-by-line using standard execCommand with insertLineBreak to preserve newlines cleanly in WhatsApp Web
      const lines = text.split('\n');
      for (let i = 0; i < lines.length; i++) {
        if (lines[i]) {
          document.execCommand('insertText', false, lines[i]);
        }
        if (i < lines.length - 1) {
          document.execCommand('insertLineBreak');
        }
      }

      // Trigger lightweight input event for UI sync
      composer.dispatchEvent(new Event('input', { bubbles: true }));

      if (autoSend) {
        setTimeout(() => {
          const sendBtn =
            document.querySelector('button span[data-icon="send"]')?.closest('button') ||
            document.querySelector('[data-testid="compose-btn-send"]') ||
            document.querySelector('[data-testid="send"]') ||
            document.querySelector('footer button[aria-label*="Send"]');
          if (sendBtn) {
            sendBtn.click();
          } else {
            composer.dispatchEvent(new KeyboardEvent('keydown', {
              key: 'Enter',
              code: 'Enter',
              keyCode: 13,
              which: 13,
              bubbles: true,
              cancelable: true
            }));
          }
        }, 180);
      }

      return true;
    } catch (err) {
      return false;
    }
  }

  // ── Extension guard ────────────────────────────────────────────────────────
  const alive = () => { try { return !!(chrome?.runtime?.id); } catch { return false; } };
  const isCtxErr = e => String(e?.message || e || '').toLowerCase().includes('extension context invalidated');
  const cleanup = () => {
    try {
      clearInterval(pollTimer); pollTimer = null;
      clearTimeout(debounceTimer); debounceTimer = null;
      observer?.disconnect(); observer = null;
      booted = false;
    } catch { /**/ }
  };

  // ── Storage ────────────────────────────────────────────────────────────────
  async function getConfig() {
    if (!alive()) { cleanup(); return { ...DEFAULTS }; }
    try {
      const c = await chrome.storage.sync.get(DEFAULTS);
      return {
        baseUrl: (c.baseUrl || DEFAULTS.baseUrl).trim().replace(/\/+$/, '') || DEFAULTS.baseUrl,
        token: (c.token || '').trim(),
        scopeAdminId: (c.scopeAdminId || '').trim()
      };
    } catch (e) { if (isCtxErr(e)) cleanup(); return { ...DEFAULTS }; }
  }

  async function getBindings() {
    if (!alive()) { cleanup(); return {}; }
    try {
      const r = await chrome.storage.local.get({ chatBindings: {} });
      return (r?.chatBindings && typeof r.chatBindings === 'object') ? r.chatBindings : {};
    } catch (e) { if (isCtxErr(e)) cleanup(); return {}; }
  }

  async function saveBinding(keys, phone) {
    const p = normalizePhone(phone);
    if (!p) return;
    const map = await getBindings();
    for (const k of (Array.isArray(keys) ? keys : [keys])) {
      const kk = String(k || '').trim();
      if (!kk) continue;
      map[kk] = p; map[nk(kk)] = p;
    }
    if (!alive()) { cleanup(); return; }
    try { await chrome.storage.local.set({ chatBindings: map }); }
    catch (e) { if (isCtxErr(e)) cleanup(); }
  }

  // ── WhatsApp detection ─────────────────────────────────────────────────────
  //
  // PRIMARY: Ask main-world.js (which runs in the PAGE's main world) to read
  // WhatsApp's internal webpack modules. We communicate via CustomEvents on
  // window — safe, no CSP issues, no inline script injection needed.
  //
  // main-world.js listens for '__ds_req_jid' and fires '__ds_res_jid' back.
  // ──────────────────────────────────────────────────────────────────────────

  function readActiveJidFromStore() {
    return new Promise(function (resolve) {
      var reqId = Math.random().toString(36).slice(2);
      var timeout = setTimeout(function () { resolve(''); }, 600);

      window.addEventListener('__ds_res_jid', function handler(e) {
        if (!e.detail || e.detail.reqId !== reqId) return;
        window.removeEventListener('__ds_res_jid', handler);
        clearTimeout(timeout);
        resolve(e.detail.jid || '');
      });

      window.dispatchEvent(new CustomEvent('__ds_req_jid', {
        detail: { reqId: reqId }
      }));
    });
  }

  // JID → phone.  Only @c.us (individual). Groups @g.us → ignored.
  const jidPhone = jid => {
    const m = String(jid || '').match(/^(\d{7,15})@c\.us$/i);
    return m ? normalizePhone(m[1]) : '';
  };

  // Is a string a phone number (not a name)?
  const looksLikePhone = txt => {
    const t = (txt || '').trim();
    if (!t || t.length > 22) return false;
    if (!/^\+?[\d][\d\s\-().]{4,18}$/.test(t)) return false;
    return !!normalizePhone(t);
  };

  // ── DOM fallback (when webpack injection returns nothing) ──────────────────
  const getHeader = () =>
    document.querySelector('#main header') ||
    document.querySelector('[data-testid="conversation-header"]');

  function getHeaderTitle() {
    const h = getHeader();
    if (!h) return '';
    for (const sel of [
      '[data-testid="conversation-info-header-chat-title"]',
      '[data-testid="conversation-info-header"] span[dir]',
      'div[role="heading"]', 'span[dir="auto"]', 'span[dir="ltr"]'
    ]) {
      const el = h.querySelector(sel);
      if (!el) continue;
      const t = (el.getAttribute('title') || el.textContent || '').trim();
      if (t && t.length < 80) return t;
    }
    return '';
  }

  // Walk ALL text nodes — finds phone numbers in any span regardless of attrs
  function textWalkPhone(el) {
    if (!el) return '';
    const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    let n;
    while ((n = w.nextNode())) {
      const t = (n.textContent || '').trim();
      if (t.length < 6 || t.length > 22) continue;
      const p = looksLikePhone(t) ? normalizePhone(t) : '';
      if (p) return p;
    }
    return '';
  }

  // Scan element + descendants for JID in data-id / data-jid attributes
  function attrPhone(el, max = 60) {
    if (!el?.querySelectorAll) return '';
    const chk = n => {
      for (const a of ['data-id', 'data-jid']) {
        const p = jidPhone(n?.getAttribute?.(a) || '');
        if (p) return p;
      }
      return '';
    };
    const d = chk(el); if (d) return d;
    const nodes = el.querySelectorAll('[data-id],[data-jid]');
    for (let i = 0; i < Math.min(nodes.length, max); i++) {
      const p = chk(nodes[i]); if (p) return p;
    }
    return '';
  }

  function domFallbackPhone() {
    // 1. Header title = phone number text (unsaved contacts)
    const title = getHeaderTitle();
    if (looksLikePhone(title)) return normalizePhone(title);

    // 2. Sidebar selected item JID attribute
    const sel = document.querySelector('[aria-selected="true"]');
    if (sel) { const p = attrPhone(sel, 50); if (p) return p; }

    // 3. Header element JID attribute
    const h = getHeader();
    if (h) { const p = attrPhone(h, 30); if (p) return p; }

    // 4. Contact info drawer — walk every text node (most aggressive)
    const drawer =
      document.querySelector('[data-testid="contact-info-drawer"]') ||
      document.querySelector('[data-testid="drawer-right"]');
    if (drawer) {
      const p = attrPhone(drawer, 60) || textWalkPhone(drawer);
      if (p) return p;
    }

    return '';
  }

  function isSelfChat() {
    const h = getHeader();
    if (!h) return false;
    const full = (h.textContent || '').toLowerCase();
    return full.includes('message yourself') || /\(you\)/i.test(full);
  }

  function getSelectedChatRow() {
    return document.querySelector('#pane-side [aria-selected="true"]') ||
      document.querySelector('[data-testid="cell-frame-container"][aria-selected="true"]') ||
      document.querySelector('[aria-selected="true"]');
  }

  function getSelectedChatMeta() {
    const row = getSelectedChatRow();
    if (!row) return { unreadCount: 0, unreadLabel: '', muted: false };

    let unreadCount = 0;
    let unreadLabel = '';
    const attrNodes = row.querySelectorAll('[aria-label],[title],[data-testid]');
    for (const el of attrNodes) {
      const raw = [
        el.getAttribute('aria-label') || '',
        el.getAttribute('title') || '',
        el.getAttribute('data-testid') || '',
        el.textContent || ''
      ].join(' ').trim();
      if (!raw || !/unread/i.test(raw)) continue;
      const m = raw.match(/(\d{1,3})/);
      unreadCount = m ? Number(m[1]) : 1;
      unreadLabel = raw;
      break;
    }

    if (!unreadCount) {
      const textNodes = Array.from(row.querySelectorAll('span, div'));
      for (const el of textNodes) {
        const t = (el.textContent || '').trim();
        if (!/^\d{1,3}$/.test(t)) continue;
        unreadCount = Number(t);
        unreadLabel = `${t} unread`;
        break;
      }
    }

    return {
      unreadCount,
      unreadLabel: unreadLabel || (unreadCount ? `${unreadCount} unread` : ''),
      muted: !!row.querySelector('[data-icon="muted"], [aria-label*="muted" i], [title*="muted" i]')
    };
  }

  // ── Master identity (async because webpack injection is async) ─────────────
  async function getIdentity() {
    if (!getHeader()) return null;
    if (isSelfChat()) return null;

    // Try webpack store first
    const storeJid = await readActiveJidFromStore();
    let phone = jidPhone(storeJid);

    // Fallback to DOM
    if (!phone) phone = domFallbackPhone();

    // Name from header (only for saved contacts whose header shows a name)
    const title = getHeaderTitle();
    const name = (!looksLikePhone(title) && !/\(you\)/i.test(title)) ? title.trim() : '';

    if (!phone && !name) return null;

    const key = phone ? `phone:${phone}` : `name:${nk(name)}`;
    return { phone, name, key, titleKey: name };
  }

  // ── API ────────────────────────────────────────────────────────────────────
  async function apiGet(page, params = {}) {
    const cfg = await getConfig();
    if (!cfg.token) return { ok: false, error: 'token_missing' };
    try {
      const url = new URL(`${cfg.baseUrl}/index.php`);
      url.searchParams.set('page', page);
      if (cfg.scopeAdminId) url.searchParams.set('scope_admin_id', cfg.scopeAdminId);
      for (const [k, v] of Object.entries(params))
        if (v != null && v !== '') url.searchParams.set(k, v);
      const r = await fetch(url.toString(), { headers: { Authorization: `Bearer ${cfg.token}` } });
      const t = await r.text();
      try { return JSON.parse(t); } catch { return { ok: false, error: `bad_${r.status}`, raw: t.slice(0, 2000) }; }
    } catch (e) { if (isCtxErr(e)) cleanup(); return { ok: false, error: 'network' }; }
  }

  async function apiPost(page, data) {
    const cfg = await getConfig();
    if (!cfg.token) return { ok: false, error: 'token_missing' };
    try {
      const url = new URL(`${cfg.baseUrl}/index.php`);
      url.searchParams.set('page', page);
      if (cfg.scopeAdminId) url.searchParams.set('scope_admin_id', cfg.scopeAdminId);
      const r = await fetch(url.toString(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${cfg.token}` },
        body: JSON.stringify(data || {})
      });
      const t = await r.text();
      try { return JSON.parse(t); } catch { return { ok: false, error: `bad_${r.status}`, raw: t.slice(0, 2000) }; }
    } catch (e) { if (isCtxErr(e)) cleanup(); return { ok: false, error: 'network' }; }
  }

  async function apiBootstrap(force = false) {
    if (!force && _bootInfo && (Date.now() - _bootInfoAt) < 30000) return _bootInfo;
    const res = await apiGet('ajax_extension_bootstrap');
    if (res && res.ok) {
      _bootInfo = res;
      _bootInfoAt = Date.now();
    }
    return res;
  }

  const fmt = n => Number(n || 0).toFixed(2);

  function parseAmount(val) {
    if (typeof val === 'number') return isNaN(val) ? 0 : val;
    if (!val) return 0;
    let s = String(val).trim();
    const bnDigits = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };
    s = s.replace(/[০-৯]/g, d => bnDigits[d]);
    s = s.replace(/[^0-9.-]/g, '');
    const num = parseFloat(s);
    return isNaN(num) ? 0 : num;
  }

  function getLatestChatMessages(limit = 10) {
    const main = document.querySelector('#main');
    if (!main) return [];
    const nodes = main.querySelectorAll(
      '.message-in .selectable-text, [data-testid="msg-container"] .selectable-text, .message-in .copyable-text'
    );
    const results = [];
    for (let i = nodes.length - 1; i >= 0 && results.length < limit; i--) {
      const text = (nodes[i].innerText || nodes[i].textContent || '').trim();
      if (text && !results.includes(text)) {
        results.push(text);
      }
    }
    return results;
  }

  function parseApplicationText(text) {
    if (!text || typeof text !== 'string') return null;
    const hasAppId = /(?:application\s*id|আবেদন\s*(?:আইডি|নং)|app\s*id)/i.test(text);
    const hasDob = /(?:date\s*of\s*birth|dob|জন্ম\s*তারিখ)/i.test(text);
    const hasName = /(?:name|নাম)\s*[:\-]/i.test(text);

    if (!hasAppId && !hasDob && !hasName) return null;

    const appIdMatch = text.match(/(?:application\s*id|আবেদন\s*(?:আইডি|নং)|app\s*id)\s*[:\-]?\s*([A-Za-z0-9\-]+)/i);
    const nameEnMatch = text.match(/(?:name\s*\(english\)|english\s*name|name\s*in\s*english)\s*[:\-]?\s*([^\n\r]+)/i);
    const nameBnMatch = text.match(/(?:^|\n)\s*(?:name|নাম)(?:\s*\(bangla\))?\s*[:\-]?\s*([^\n\r]+)/i);
    const dobMatch = text.match(/(?:date\s*of\s*birth|dob|জন্ম\s*তারিখ)\s*[:\-]?\s*([0-9\/\-\.]+)/i);
    const addrMatch = text.match(/(?:office\s*address|present\s*address|permanent\s*address|address|ঠিকানা)\s*[:\-]?\s*([\s\S]+?)(?=(?:\n\s*[A-Za-z\u0980-\u09FF\s]+:|$))/i);

    const appId = appIdMatch ? appIdMatch[1].trim() : '';
    const nameEn = nameEnMatch ? nameEnMatch[1].trim() : '';
    let nameBn = nameBnMatch ? nameBnMatch[1].trim() : '';
    if (nameBn === nameEn || /^name\s*\(english\)/i.test(nameBn)) nameBn = '';

    const dob = dobMatch ? dobMatch[1].trim() : '';
    const address = addrMatch ? addrMatch[1].trim().replace(/\n+/g, ', ') : '';

    const bestName = nameEn || nameBn || '';
    if (!appId && (!bestName || !dob)) return null;

    return {
      raw: text,
      appId,
      name: bestName,
      nameEn,
      nameBn,
      dob,
      address,
      isBirth: hasDob || /birth|জন্ম/i.test(text)
    };
  }

  function detectChatApplication() {
    const messages = getLatestChatMessages(10);
    for (const msg of messages) {
      const parsed = parseApplicationText(msg);
      if (parsed) return parsed;
    }
    return null;
  }

  const _appIdCache = new Map();
  async function checkApplicationStatus(appId) {
    if (!appId) return { exists: false };
    const clean = String(appId).replace(/[^A-Za-z0-9\-]/g, '');
    if (clean.length < 3) return { exists: false };
    if (_appIdCache.has(clean)) return _appIdCache.get(clean);
    try {
      const res = await apiGet('ajax_check_application_id', { app_id: clean });
      const info = (res && res.ok) ? res : { exists: false };
      _appIdCache.set(clean, info);
      return info;
    } catch {
      return { exists: false };
    }
  }

  function shortDate(value) {
    const raw = String(value || '').trim();
    if (!raw) return '--';
    const dt = new Date(`${raw}T00:00:00`);
    if (Number.isNaN(dt.getTime())) return esc(raw);
    return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
  }

  function workflowKey(customerId, phone) {
    const cid = Number(customerId || 0);
    if (cid > 0) return `c:${cid}`;
    const normalized = normalizePhone(phone || '');
    return normalized ? `p:${normalized}` : '';
  }

  function setWorkflowState(key, state) {
    if (!key || !state) return;
    _workflowStates.set(key, { ...state, at: Date.now() });
  }

  function getWorkflowState(key) {
    const state = key ? _workflowStates.get(key) : null;
    if (!state) return null;
    if ((Date.now() - Number(state.at || 0)) > 900000) {
      _workflowStates.delete(key);
      return null;
    }
    return state;
  }

  function dismissWorkflowState(key, panel) {
    if (key) _workflowStates.delete(key);
    panel?.querySelector('#ds-flow-state-wrap')?.replaceChildren();
  }

  function workflowHtml(state) {
    if (!state) return '';
    const kind = ['success', 'warning', 'info'].includes(String(state.kind || '')) ? state.kind : 'info';
    const rows = Array.isArray(state.items) ? state.items : [];
    return `
      <div class="ds-flow-state ds-flow-${kind}">
        <div class="ds-flow-head">
          <div>
            <div class="ds-flow-title">${esc(state.title || 'Workflow updated')}</div>
            <div class="ds-flow-time">${esc(state.meta || 'Just now')}</div>
          </div>
          <button class="ds-flow-dismiss" type="button" id="ds-flow-dismiss">×</button>
        </div>
        ${rows.map((row) => `
          <div class="ds-flow-row">
            <span>${esc(row.label || '')}</span>
            <b class="${row.stateClass || ''}">${esc(row.value || '')}</b>
          </div>`).join('')}
      </div>`;
  }

  function customerTimelineHtml(events) {
    const rows = Array.isArray(events) ? events.slice(0, 6).reverse() : [];
    if (!rows.length) return '';
    const maxAmount = Math.max(...rows.map((item) => Number(item.amount || 0)), 1);
    return `
      <div class="ds-subtitle">Customer timeline</div>
      <div class="ds-timeline-card">
        <div class="ds-timeline-chart">
          ${rows.map((item) => {
      const amount = Number(item.amount || 0);
      const height = Math.max(18, Math.round((amount / maxAmount) * 72));
      const tone = item.type === 'payment' ? 'payment' : 'sale';
      return `
              <div class="ds-timeline-item">
                <div class="ds-timeline-value ${tone}">${fmt(amount)}</div>
                <div class="ds-timeline-bar-wrap">
                  <div class="ds-timeline-bar ${tone}" style="height:${height}px"></div>
                </div>
                <div class="ds-timeline-date">${shortDate(item.date)}</div>
                <div class="ds-timeline-kind">${item.type === 'payment' ? 'PAY' : 'SALE'}</div>
              </div>`;
    }).join('')}
        </div>
      </div>`;
  }

  function customerChatStateHtml(id, chatMeta) {
    const pills = [];
    if (chatMeta.unreadCount > 0) {
      pills.push(`<span class="ds-unread-pill">${chatMeta.unreadCount} unread</span>`);
    } else {
      pills.push('<span class="ds-status-pill">Read</span>');
    }
    pills.push(`<span class="ds-status-pill">${id?.nameMatch ? 'Name match' : 'Auto lookup'}</span>`);
    if (chatMeta.muted) pills.push('<span class="ds-status-pill">Muted</span>');
    return `<div class="ds-status-row">${pills.join('')}</div>`;
  }

  function noticeHtml(text) {
    const msg = String(text || '').trim();
    if (!msg) return '';
    return `<div class="ds-notice-banner"><span class="ds-notice-icon">📢</span><div>${esc(msg)}</div></div>`;
  }

  async function refreshToolsOverview(panel, force = false) {
    const mount = panel.querySelector('#ds-tools-overview');
    if (!mount) return { ok: false };
    mount.innerHTML = '<div class="ds-muted">ওয়ার্কস্পেস লোড হচ্ছে...</div>';
    const res = await apiBootstrap(force);
    if (!(res && res.ok)) {
      mount.innerHTML = '<div class="ds-muted">ওয়ার্কস্পেস ডেটা লোড করা যায়নি</div>';
      return { ok: false };
    }

    const stats = res.stats || {};
    const scopeName = res.profile?.scope?.name || 'N/A';
    const userName = res.profile?.user?.name || res.profile?.user?.email || 'Connected';
    mount.innerHTML = `
      <div class="ds-card ds-hero-card">
        <div class="ds-hero-top">
          <div>
            <div class="ds-subtitle" style="margin-top:0;">Workspace</div>
            <div class="ds-hero-title">${esc(userName)}</div>
            <div class="ds-hero-meta">Scope: ${esc(scopeName)}${res.company_name ? ` • ${esc(res.company_name)}` : ''}</div>
          </div>
          <div class="ds-hero-badge ${res.can_write ? 'ok' : 'warn'}">${res.can_write ? 'Write' : 'Read only'}</div>
        </div>
        <div class="ds-hero-grid">
          <div class="ds-hero-stat"><span>আজ বিক্রয়</span><b>${fmt(stats.sales)}</b></div>
          <div class="ds-hero-stat"><span>কালেকশন</span><b>${fmt(stats.payments)}</b></div>
          <div class="ds-hero-stat"><span>খরচ</span><b>${fmt(stats.expenses)}</b></div>
          <div class="ds-hero-stat"><span>নেট</span><b class="${Number(stats.net || 0) >= 0 ? 'ds-green' : 'ds-red'}">${fmt(stats.net)}</b></div>
        </div>
        ${noticeHtml(res.global_notice)}
      </div>`;

    panel.querySelectorAll('[data-write-action]').forEach((el) => {
      el.disabled = !res.can_write;
      el.classList.toggle('ds-is-disabled', !res.can_write);
      el.title = !res.can_write ? 'Select a user scope to enable write actions' : '';
    });

    return res;
  }

  // ── Toast / Modal ──────────────────────────────────────────────────────────
  function toast(msg) {
    const el = document.createElement('div');
    Object.assign(el.style, {
      position: 'fixed', right: '16px', bottom: '92px', zIndex: '1000002',
      background: '#111', color: '#fff', padding: '10px 12px',
      borderRadius: '10px', boxShadow: '0 10px 24px rgba(0,0,0,.22)', fontSize: '13px'
    });
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2400);
  }

  function modal(title, body, onBind) {
    // Render inside the panel itself — not a full-page overlay
    const panel = document.getElementById('ds-wa-panel');
    const host = panel || document.body;

    const wrap = document.createElement('div');
    wrap.className = 'ds-modal-wrap';
    wrap.innerHTML = `
      <div class="ds-modal">
        <div class="ds-modal-header">
          <div class="ds-modal-title">${esc(title)}</div>
          <button class="ds-modal-close" type="button">×</button>
        </div>
        <div class="ds-modal-body">${body}</div>
      </div>`;
    host.appendChild(wrap);

    const close = () => wrap.remove();
    wrap.addEventListener('click', e => { if (e.target === wrap) close(); });
    wrap.querySelector('.ds-modal-close').addEventListener('click', close);
    onBind?.(wrap, close);
    return { ov: wrap, close };
  }

  function apiError(title, res) {
    modal(title, `
      <div style="font-weight:800;margin-bottom:8px;">${esc(res?.error || 'error')}</div>
      ${res?.raw ? `<pre style="white-space:pre-wrap;word-break:break-word;font-size:12px;
        background:#f6f7f8;padding:10px;border-radius:10px;border:1px solid #eee;
        max-height:220px;overflow:auto;">${esc(res.raw)}</pre>` : ''}
      <div class="ds-modal-actions">
        <button class="ds-btn ds-btn-block" type="button" id="ds-err-ok">ঠিক আছে</button>
      </div>`,
      (ov, close) => { ov.querySelector('#ds-err-ok').onclick = close; });
  }

  // ── Load queue ─────────────────────────────────────────────────────────────
  async function loadSafe(panel, id) {
    if (loadRunning) { loadQueued = id; return; }
    loadRunning = true;
    try { await loadCustomer(panel, id); }
    finally {
      loadRunning = false;
      if (loadQueued) { const n = loadQueued; loadQueued = null; await loadSafe(panel, n); }
    }
  }

  // ── Customer load ──────────────────────────────────────────────────────────
  async function loadCustomer(panel, id) {
    _currentCustomer = null;
    applyChatBadges();
    const cEl = panel.querySelector('#ds-customer');
    const aEl = panel.querySelector('#ds-actions');
    const rEl = panel.querySelector('#ds-recent');
    cEl.innerHTML = '<div class="ds-loading"><div class="ds-spinner"></div>লোড হচ্ছে...</div>';
    aEl.innerHTML = ''; rEl.innerHTML = '';

    const ph = normalizePhone(id?.phone || '');
    const name = (id?.name || '').trim();

    // 1. Phone
    if (ph) {
      const res = await apiGet('ajax_customer_lookup', { phone: ph });
      if (res.ok && res.found) {
        const ledger = await apiGet('ajax_customer_ledger_events', { customer_id: res.customer.id });
        render(panel, { ...id, phone: ph }, res, ledger); return;
      }
    }
    // 2. Name
    if (name.length >= 2) {
      const res = await apiGet('ajax_customer_lookup', { name });
      if (res.ok && res.found) {
        const dp = normalizePhone(res.customer.phone || '');
        const ledger = await apiGet('ajax_customer_ledger_events', { customer_id: res.customer.id });
        render(panel, { ...id, phone: dp || ph, nameMatch: true }, res, ledger); return;
      }
    }
    // 3. Stored binding
    const bkeys = [id?.key, id?.titleKey, id?.name].filter(Boolean).map(String);
    if (bkeys.length) {
      const map = await getBindings();
      for (const k of bkeys) {
        const bp = normalizePhone(map[k] || map[nk(k)] || '');
        if (bp && bp !== ph) {
          const res = await apiGet('ajax_customer_lookup', { phone: bp });
          if (res.ok && res.found) {
            const ledger = await apiGet('ajax_customer_ledger_events', { customer_id: res.customer.id });
            render(panel, { ...id, phone: bp }, res, ledger); return;
          }
        }
      }
    }
    // 4. Not found
    render(panel, id, { ok: true, found: false }, null);
  }

  // ── Render customer ────────────────────────────────────────────────────────
  async function render(panel, id, lookup, ledger) {
    const cEl = panel.querySelector('#ds-customer');
    const aEl = panel.querySelector('#ds-actions');
    const rEl = panel.querySelector('#ds-recent');

    if (!lookup.ok) {
      cEl.innerHTML = `<div class="ds-muted">${lookup.error === 'token_missing' ? 'Popup থেকে API token সেট করুন' :
        lookup.error === 'Unauthorized' ? 'Token ভুল — Settings থেকে নতুন নিন' :
          'ত্রুটি: ' + esc(lookup.error || 'error')}</div>`;
      aEl.innerHTML = ''; rEl.innerHTML = ''; return;
    }

    if (!lookup.found) {
      _currentCustomer = null;
      applyChatBadges();
      const ip = esc(id?.phone || ''), iname = esc(id?.name || '');
      cEl.innerHTML = `
        <div class="ds-empty">
          <div class="ds-empty-icon">👤</div>
          <b style="color:var(--ds-text2);display:block;margin-bottom:4px;">${iname || ip || 'নতুন কাস্টমার'}</b>
          এই কাস্টমারটি ডাটাবেসে নেই
        </div>`;
      aEl.innerHTML = `<button class="ds-btn ds-btn-primary ds-btn-block" id="ds-add">+ কাস্টমার যোগ করুন</button>`;
      aEl.querySelector('#ds-add').onclick = () => {
        modal('নতুন কাস্টমার', `
          <div class="ds-field"><label class="ds-label">ফোন</label>
            <input class="ds-input" id="nph" value="${ip}" placeholder="+880..."></div>
          <div class="ds-field"><label class="ds-label">নাম</label>
            <input class="ds-input" id="nnm" value="${iname}" placeholder="কাস্টমারের নাম"></div>
          <div class="ds-field"><label class="ds-label">ইমেইল</label>
            <input class="ds-input" id="nem" placeholder="ঐচ্ছিক"></div>
          <div class="ds-field"><label class="ds-label">ঠিকানা</label>
            <textarea class="ds-textarea" id="nad" placeholder="ঐচ্ছিক"></textarea></div>
          <div class="ds-field"><label class="ds-label">ক্যাটাগরি</label>
            <select class="ds-select" id="nct"><option value="Regular">Regular</option><option value="VIP">VIP</option></select></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="nc1">বাতিল</button>
            <button class="ds-btn ds-btn-primary ds-btn-block" type="button" id="nc2">সেভ করুন</button>
          </div>`,
          (ov, close) => {
            ov.querySelector('#nc1').onclick = close;
            ov.querySelector('#nc2').onclick = async () => {
              const phone = (ov.querySelector('#nph').value || '').trim();
              const name = (ov.querySelector('#nnm').value || '').trim();
              const d = normalizePhone(phone);
              if (!d && !name) { toast('ফোন বা নাম দিন'); return; }
              const res = await apiPost('ajax_customer_quick_add', {
                name, phone,
                email: (ov.querySelector('#nem').value || '').trim(),
                address: (ov.querySelector('#nad').value || '').trim(),
                category: ov.querySelector('#nct').value || 'Regular'
              });
              if (res.ok) { close(); await loadSafe(panel, { ...id, phone: d || phone, name: name || id?.name || '' }); }
              else toast(esc(res.error || 'যোগ করতে ব্যর্থ'));
            };
          });
      };
      rEl.innerHTML = ''; return;
    }

    const c = lookup.customer, s = lookup.summary;
    const notes = Array.isArray(lookup.notes) ? lookup.notes : [];
    const pendingTasks = Array.isArray(lookup.pending_tasks) ? lookup.pending_tasks : [];
    const pendingTaskCount = Number(lookup.pending_task_count || pendingTasks.length || 0);

    _currentCustomer = { id: c.id, name: c.name, phone: c.phone, due: s?.total_due, category: c.category, taskCount: pendingTaskCount };
    if (c.phone) {
      _duesCache.set(normalizePhone(c.phone), Number(s?.total_due || 0));
      _tasksCache.set(normalizePhone(c.phone), pendingTaskCount);
    }
    if (c.name) {
      _duesCache.set(nk(c.name), Number(s?.total_due || 0));
      _tasksCache.set(nk(c.name), pendingTaskCount);
    }
    applyChatBadges();

    const workspace = await apiBootstrap();
    const canWrite = !!(workspace && workspace.ok && workspace.can_write);
    const sales = Array.isArray(lookup.recent_sales) ? lookup.recent_sales : [];
    const evts = (ledger?.ok && Array.isArray(ledger.events)) ? ledger.events : [];
    const chatMeta = getSelectedChatMeta();
    const flowKey = workflowKey(c.id, c.phone || id?.phone || '');
    const flowState = getWorkflowState(flowKey);

    const initials = (c.name || 'C').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() || 'C';
    const isVip = c.category === 'VIP';
    const dueNumber = Number(s.total_due || 0);

    cEl.innerHTML = `
      ${workspace?.ok ? noticeHtml(workspace.global_notice) : ''}
      ${id?.nameMatch ? '<div class="ds-warn-banner">⚠️ ফোন ডিটেক্ট হয়নি — নাম দিয়ে মিলেছে</div>' : ''}
      
      <!-- Customer Hero -->
      <div class="ds-cust-hero">
        <div class="ds-cust-avatar" style="${isVip ? 'background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 4px 10px rgba(245,158,11,0.3);' : ''}">${esc(initials)}</div>
        <div class="ds-cust-info">
          <div class="ds-customer-name">
            <span>${esc(c.name)}</span>
            <span class="ds-badge ${isVip ? 'ds-badge-vip' : ''}">${esc(c.category || 'Regular')}</span>
          </div>
          ${customerChatStateHtml(id, chatMeta)}
        </div>
      </div>

      <!-- Quick Contact Info Chips -->
      <div class="ds-info-chips">
        <div class="ds-info-chip" title="Click to copy phone" id="ds-copy-phone" style="cursor:pointer;">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.1 1.18 2 2 0 012.11 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 7.09a16 16 0 006 6l.46-.46a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
          <span>${esc(c.phone || '—')}</span>
        </div>
        ${c.email ? `<div class="ds-info-chip" title="Click to copy email" id="ds-copy-email" style="cursor:pointer;">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 7L2 7"/></svg>
          <span>${esc(c.email)}</span>
        </div>` : ''}
        ${c.address ? `<div class="ds-info-chip" title="${esc(c.address)}">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <span>${esc(c.address.slice(0, 18))}${c.address.length > 18 ? '...' : ''}</span>
        </div>` : ''}
      </div>

      <!-- 4-metric KPI Grid -->
      <div class="ds-kpis">
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">🛒 বিক্রয়</div>
          <div class="ds-kpi-value">${fmt(s.total_sell)}</div>
        </div>
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">💳 পরিশোধ</div>
          <div class="ds-kpi-value green">${fmt(s.total_paid)}</div>
        </div>
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">⚠️ মোট বাকি</div>
          <div class="ds-kpi-value ${dueNumber > 0.01 ? 'red' : 'green'}">${fmt(s.total_due)}</div>
        </div>
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">📋 পেন্ডিং কাজ</div>
          <div class="ds-kpi-value ${pendingTaskCount > 0 ? 'orange' : ''}">${pendingTaskCount}</div>
        </div>
      </div>`;

    cEl.querySelector('#ds-copy-phone')?.addEventListener('click', () => {
      if (c.phone) { navigator.clipboard.writeText(c.phone); toast('ফোন নম্বর কপি হয়েছে'); }
    });
    cEl.querySelector('#ds-copy-email')?.addEventListener('click', () => {
      if (c.email) { navigator.clipboard.writeText(c.email); toast('ইমেইল কপি হয়েছে'); }
    });

    const cfg = await getConfig();
    const writeHint = canWrite ? '' : '<div class="ds-soft-banner">Read-only scope active. Actions বন্ধ আছে।</div>';
    const detectedApp = detectChatApplication();
    let appStatus = { exists: false };
    if (detectedApp?.appId) {
      appStatus = await checkApplicationStatus(detectedApp.appId);
    }

    let appBannerHtml = '';
    if (detectedApp) {
      if (appStatus && appStatus.exists) {
        appBannerHtml = `
          <div class="ds-soft-banner" style="background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.25);margin-bottom:8px;padding:8px 10px;border-radius:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
              <span style="font-size:11px;font-weight:700;color:#10b981;display:flex;align-items:center;gap:4px;">
                ✓ আবেদনটি সিস্টেমে এন্ট্রি আছে
              </span>
              <span style="font-size:10px;background:rgba(16,185,129,0.12);color:#059669;padding:1px 6px;border-radius:4px;font-weight:700;">ID: ${esc(detectedApp.appId)}</span>
            </div>
            <div style="font-size:11px;color:var(--text2);margin-top:2px;">
              ${esc(detectedApp.name || '')}${appStatus.data ? ` (${appStatus.type === 'task' ? `কাজ #${appStatus.data.id}` : `ইনভয়েস ${appStatus.data.invoice_no}`})` : ''}
            </div>
          </div>`;
      } else {
        appBannerHtml = `
          <div class="ds-soft-banner" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.38);margin-bottom:8px;padding:8px 10px;border-radius:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px;">
              <span style="font-size:11px;font-weight:800;color:#ef4444;display:flex;align-items:center;gap:4px;">
                🚨 নতুন আবেদন — এন্ট্রি করা হয়নি!
              </span>
              ${detectedApp.appId ? `<span style="font-size:10px;background:rgba(239,68,68,0.18);color:#dc2626;padding:1px 6px;border-radius:4px;font-weight:800;">ID: ${esc(detectedApp.appId)}</span>` : ''}
            </div>
            <div style="font-size:12px;font-weight:700;color:var(--text);">${esc(detectedApp.name || 'জন্ম নিবন্ধন আবেদন')}</div>
            ${detectedApp.dob ? `<div style="font-size:10.5px;color:var(--text2);margin-top:2px;">জন্ম তারিখ: <b>${esc(detectedApp.dob)}</b></div>` : ''}
            <div style="display:flex;gap:6px;margin-top:8px;">
              <button class="ds-btn ds-btn-primary ds-btn-sm" id="ds-detected-add" type="button" style="flex:1;" ${canWrite ? '' : 'disabled'}>
                ⚡ এখনই কাজ যোগ করুন
              </button>
              <button class="ds-btn ds-btn-outline ds-btn-sm" id="ds-detected-open" type="button" style="flex:1;" ${canWrite ? '' : 'disabled'}>
                মডালে এডিট
              </button>
            </div>
          </div>`;
      }
    }

    aEl.innerHTML = `
      <div id="ds-flow-state-wrap">${workflowHtml(flowState)}</div>
      ${writeHint}
      ${appBannerHtml}
      <div class="ds-inline-actions">
        <button class="ds-btn ds-btn-primary" id="ds-sale-fast" type="button" ${canWrite ? '' : 'disabled'}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
          Quick Sale
        </button>
        <button class="ds-btn ds-btn-indigo" id="ds-deliver-fast" type="button">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          Deliver
        </button>
        <button class="ds-btn ds-btn-outline" id="ds-pay-fast" type="button" ${canWrite ? '' : 'disabled'}>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
          Collect Due
        </button>
        <button class="ds-btn ds-btn-outline" id="ds-sinv-fast" type="button">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
          Invoice
        </button>
        ${dueNumber > 0 ? `<button class="ds-btn ds-btn-warn" id="ds-sdue-fast" type="button" style="grid-column: span 2;">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
          ⏰ বকেয়া রিমাইন্ডার পাঠান (${fmt(s.total_due)})
        </button>` : ''}
      </div>
      <div class="ds-actionbar">
        <div class="ds-menu-wrap">
          <button class="ds-btn ds-btn-secondary ds-menu-btn" id="ds-ambt" type="button">
            <span>অন্যান্য অ্যাকশন</span>
            <span style="font-size:10px;">▾</span>
          </button>
          <div class="ds-menu ds-hidden" id="ds-amenu">
            <button class="ds-menu-item" id="ds-sale"  type="button" ${canWrite ? '' : 'disabled'}>🛒 নতুন সেল (Cart)</button>
            <button class="ds-menu-item" id="ds-deliver" type="button">📦 অ্যাকাউন্ট ডেলিভারি</button>
            <button class="ds-menu-item" id="ds-pay"   type="button" ${canWrite ? '' : 'disabled'}>💳 পেমেন্ট গ্রহণ</button>
            <button class="ds-menu-item" id="ds-task-add"  type="button" ${canWrite ? '' : 'disabled'}>📝 নতুন কাজ যোগ</button>
            <button class="ds-menu-item" id="ds-note"  type="button" ${canWrite ? '' : 'disabled'}>📌 নোট যোগ</button>
            <button class="ds-menu-item" id="ds-upd"   type="button" ${canWrite ? '' : 'disabled'}>✏️ কাস্টমার আপডেট</button>
            <button class="ds-menu-item" id="ds-ssum"  type="button">📊 সামারি পাঠান</button>
            <button class="ds-menu-item" id="ds-sdue"  type="button">⏰ বকেয়া রিমাইন্ডার</button>
            <button class="ds-menu-item" id="ds-sinv"  type="button">🧾 ইনভয়েস পাঠান</button>
          </div>
        </div>
        <a class="ds-link-btn" target="_blank"
           href="${esc(cfg.baseUrl)}/index.php?page=customer_ledger&id=${encodeURIComponent(c.id)}">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
          লেজার
        </a>
      </div>
      ${id?.nameMatch ? '<button class="ds-btn ds-btn-secondary ds-btn-block" id="ds-bindph" type="button">এই চ্যাটে ফোন লিংক করুন</button>' : ''}`;

    aEl.querySelector('#ds-flow-dismiss')?.addEventListener('click', () => dismissWorkflowState(flowKey, aEl));

    const menu = aEl.querySelector('#ds-amenu');
    const closeMenu = () => menu?.classList.add('ds-hidden');
    aEl.querySelector('#ds-ambt')?.addEventListener('click', e => {
      e.stopPropagation();
      menu?.classList.toggle('ds-hidden');
    });
    document.addEventListener('click', closeMenu, { once: true });
    menu?.addEventListener('click', e => e.stopPropagation());

    const guardWrite = () => {
      if (canWrite) return true;
      toast('Write action এর জন্য user scope select করুন');
      return false;
    };

    // ── Delivery Credentials Modal ──────────────────────────────────────────
    const openDeliveryModal = () => {
      closeMenu();
      modal('অ্যাকাউন্ট ডেলিভারি (Credentials)', `
        <div class="ds-field"><label class="ds-label">প্রোডাক্ট / সার্ভিস</label>
          <input class="ds-input" id="del-pname" placeholder="যেমন: Netflix Premium / Canva Pro"></div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">ইমেইল / আইডি *</label>
            <input class="ds-input" id="del-email" placeholder="user@gmail.com"></div>
          <div class="ds-field"><label class="ds-label">পাসওয়ার্ড *</label>
            <input class="ds-input" id="del-pass" placeholder="Pass@1234"></div>
        </div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">প্রোফাইল / PIN</label>
            <input class="ds-input" id="del-pin" placeholder="Profile 2 / PIN 1234"></div>
          <div class="ds-field"><label class="ds-label">মেয়াদ / Validity</label>
            <input class="ds-input" id="del-exp" value="1 Month"></div>
        </div>
        <div class="ds-field"><label class="ds-label">বিশেষ নির্দেশনা / নিয়মাবলী</label>
          <textarea class="ds-textarea" id="del-rules" rows="2">পাসওয়ার্ড বা প্রোফাইল কোনো কিছু পরিবর্তন করবেন না। সমস্যা হলে আমাদের জানান।</textarea></div>
        <div class="ds-field">
          <label class="ds-checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="del-savenote" style="width:16px;height:16px;margin:0;" checked>
            <span>কাস্টমার নোটে সেভ রাখুন</span></label></div>
        <div class="ds-modal-actions" style="grid-template-columns:1fr 1fr;">
          <button class="ds-btn ds-btn-secondary" type="button" id="del-paste">💬 চ্যাটে পেস্ট</button>
          <button class="ds-btn ds-btn-primary" type="button" id="del-send">🚀 সরাসরি পাঠান</button>
        </div>`,
        (ov, close) => {
          const handleDeliver = async (autoSend) => {
            const prod = (ov.querySelector('#del-pname').value || '').trim() || 'ডিজিটাল প্রোডাক্ট';
            const email = (ov.querySelector('#del-email').value || '').trim();
            const pass = (ov.querySelector('#del-pass').value || '').trim();
            const pin = (ov.querySelector('#del-pin').value || '').trim();
            const exp = (ov.querySelector('#del-exp').value || '').trim();
            const rules = (ov.querySelector('#del-rules').value || '').trim();
            const company = _bootInfo?.company_name || 'Digital Store';

            if (!email && !pass) { toast('ইমেইল বা পাসওয়ার্ড দিন'); return; }

            let msg = `🎉 *আপনার অর্ডার ডেলিভারি সম্পন্ন হয়েছে!*\n\n`;
            msg += `📦 *প্রোডাক্ট:* ${prod}\n`;
            if (email) msg += `📧 *আইডি:* ${email}\n`;
            if (pass) msg += `🔑 *পাসওয়ার্ড:* ${pass}\n`;
            if (pin) msg += `📌 *প্রোফাইল/পিন:* ${pin}\n`;
            if (exp) msg += `📅 *মেয়াদ:* ${exp}\n`;
            if (rules) msg += `\n⚠️ *নিয়মাবলী:* ${rules}\n`;
            msg += `\nধন্যবাদ,\n*${company}*`;

            if (ov.querySelector('#del-savenote').checked && c?.id) {
              apiPost('ajax_customer_note_add', { customer_id: c.id, note: `[ডেলিভারি] ${prod}: ${email}` }).catch(() => {});
            }

            close();
            await pasteToChat(msg, autoSend);
            toast(autoSend ? 'ডেলিভারি পাঠানো হয়েছে' : 'ডেলিভারি চ্যাটে পেস্ট হয়েছে');
            await loadSafe(panel, id);
          };

          ov.querySelector('#del-paste').onclick = () => handleDeliver(false);
          ov.querySelector('#del-send').onclick = () => handleDeliver(true);
        });
    };

    // ── Multi-Product Quick Sale ─────────────────────────────────────────────
    const openSaleModal = () => {
      if (!guardWrite()) return;
      closeMenu();

      let cart = [];

      modal('নতুন সেল (Quick Cart)', `
        <div class="ds-field"><label class="ds-label">প্রোডাক্ট যোগ করুন</label>
          <div style="display:flex;gap:6px;">
            <input class="ds-input" id="sq" placeholder="নাম বা SKU খুঁজুন">
            <button class="ds-btn ds-btn-secondary" id="s-add-item-btn" type="button" style="white-space:nowrap;padding:0 12px;">+ যোগ</button>
          </div>
          <div class="ds-suggest" id="ss"></div>
        </div>

        <div id="s-cart-container"></div>

        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">পেমেন্ট গ্রহণ</label>
            <input class="ds-input" id="sp" inputmode="decimal" value="0"></div>
          <div class="ds-field"><label class="ds-label">পদ্ধতি</label>
            <select class="ds-select" id="sm"><option>Cash</option><option>bKash</option><option>Nagad</option><option>Bank</option><option>Other</option></select></div>
        </div>
        <div class="ds-quick-fill-row">
          <button class="ds-chip-btn" type="button" id="spfull">Full Paid</button>
          <button class="ds-chip-btn" type="button" id="sphalf">Half</button>
          <button class="ds-chip-btn" type="button" id="spzero">Due Sale</button>
        </div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">সেল তারিখ</label>
            <input class="ds-input" id="sdt" type="date"></div>
          <div class="ds-field"><label class="ds-label">পেমেন্ট তারিখ</label>
            <input class="ds-input" id="spdt" type="date"></div>
        </div>
        <div class="ds-field"><label class="ds-label">নোট / TrxID</label>
          <textarea class="ds-textarea" id="sn" placeholder="ঐচ্ছিক"></textarea></div>
        <div class="ds-field">
          <label class="ds-checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="si-chat" style="width:16px;height:16px;margin:0;" checked>
            <span>ইনভয়েস সরাসরি চ্যাটে পেস্ট করুন</span></label></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="sc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="sv">সেভ ও সম্পন্ন</button>
        </div>`,
        (ov, close) => {
          const qEl = ov.querySelector('#sq'), sgEl = ov.querySelector('#ss');
          const cartContainer = ov.querySelector('#s-cart-container');
          const payEl = ov.querySelector('#sp');
          const today = new Date().toISOString().slice(0, 10);
          ov.querySelector('#sdt').value = today;
          ov.querySelector('#spdt').value = today;

          const calcTotal = () => {
            return cart.reduce((sum, item) => sum + (parseAmount(item.price) * (parseAmount(item.qty) || 1)), 0);
          };

          const updateCartUI = () => {
            if (!cart.length) {
              cartContainer.innerHTML = `
                <div class="ds-soft-banner" style="margin-bottom:8px;">কার্ট খালি — প্রোডাক্ট সিলেক্ট করে যোগ করুন অথবা কুইক পরিমাণ দিন:
                  <div style="display:flex;gap:6px;margin-top:6px;">
                    <input class="ds-input" id="s-quick-amt" type="text" inputmode="decimal" placeholder="পরিমাণ (যেমন: 500)">
                    <input class="ds-input" id="s-quick-qty" type="number" value="1" style="width:50px;">
                  </div>
                </div>`;
              const qAmt = cartContainer.querySelector('#s-quick-amt');
              const qQty = cartContainer.querySelector('#s-quick-qty');
              const syncQuick = () => {
                const total = (parseAmount(qAmt?.value) * (parseAmount(qQty?.value) || 1));
                payEl.value = String(total);
              };
              qAmt?.addEventListener('input', syncQuick);
              qQty?.addEventListener('input', syncQuick);
              return;
            }

            const total = calcTotal();
            cartContainer.innerHTML = `
              <table class="ds-cart-table">
                <thead><tr><th>আইটেম</th><th style="width:50px;">Qty</th><th style="width:75px;">দর</th><th>মোট</th><th style="width:24px;"></th></tr></thead>
                <tbody>
                  ${cart.map((it, idx) => `
                    <tr>
                      <td style="font-weight:700;">${esc(it.name)}</td>
                      <td><input class="ds-cart-qty-input" data-idx="${idx}" type="number" min="1" max="999" value="${it.qty}"></td>
                      <td><input class="ds-cart-price-input" data-idx="${idx}" type="text" inputmode="decimal" value="${it.price}"></td>
                      <td class="ds-row-total" style="font-weight:700;color:var(--ds-accent);">${fmt(it.price * it.qty)}</td>
                      <td><button class="ds-cart-remove-btn" data-del="${idx}" type="button">✕</button></td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
              <div class="ds-cart-total-box">
                <span>মোট বিল:</span>
                <span class="ds-green ds-grand-total" style="font-size:13px;">${fmt(total)}</span>
              </div>`;

            payEl.value = String(total);

            cartContainer.querySelectorAll('.ds-cart-qty-input').forEach(inp => {
              inp.addEventListener('input', (e) => {
                const idx = Number(e.target.dataset.idx);
                cart[idx].qty = Math.max(1, Math.round(parseAmount(e.target.value)) || 1);
                const tr = e.target.closest('tr');
                if (tr) {
                  const lineTotalEl = tr.querySelector('.ds-row-total');
                  if (lineTotalEl) lineTotalEl.textContent = fmt(cart[idx].price * cart[idx].qty);
                }
                const grandTotalEl = cartContainer.querySelector('.ds-grand-total');
                if (grandTotalEl) grandTotalEl.textContent = fmt(calcTotal());
                payEl.value = String(calcTotal());
              });
            });

            cartContainer.querySelectorAll('.ds-cart-price-input').forEach(inp => {
              inp.addEventListener('input', (e) => {
                const idx = Number(e.target.dataset.idx);
                cart[idx].price = Math.max(0, parseAmount(e.target.value));
                const tr = e.target.closest('tr');
                if (tr) {
                  const lineTotalEl = tr.querySelector('.ds-row-total');
                  if (lineTotalEl) lineTotalEl.textContent = fmt(cart[idx].price * cart[idx].qty);
                }
                const grandTotalEl = cartContainer.querySelector('.ds-grand-total');
                if (grandTotalEl) grandTotalEl.textContent = fmt(calcTotal());
                payEl.value = String(calcTotal());
              });
            });

            cartContainer.querySelectorAll('.ds-cart-remove-btn').forEach(btn => {
              btn.addEventListener('click', (e) => {
                const idx = Number(btn.dataset.del);
                cart.splice(idx, 1);
                updateCartUI();
              });
            });
          };

          updateCartUI();

          const addProductToCart = (pId, name, price) => {
            const existing = cart.find(x => x.product_id === pId && pId !== null);
            if (existing) {
              existing.qty += 1;
            } else {
              cart.push({ product_id: pId, name, price: Number(price || 0), qty: 1 });
            }
            qEl.value = '';
            sgEl.innerHTML = '';
            updateCartUI();
          };

          ov.querySelector('#s-add-item-btn').onclick = () => {
            const raw = qEl.value.trim();
            if (!raw) { toast('নাম লিখুন'); return; }
            addProductToCart(null, raw, 0);
          };

          let t = null;
          const search = async () => {
            const q = qEl.value.trim(); if (!q) { sgEl.innerHTML = ''; return; }
            const res = await apiGet('ajax_products_lookup', { q, customer_id: c.id });
            if (!res.ok || !Array.isArray(res.products)) { sgEl.innerHTML = ''; return; }
            sgEl.innerHTML = res.products.slice(0, 6).map(p => `
              <button type="button" class="ds-suggest-item"
                data-id="${encodeURIComponent(p.id)}" data-name="${esc(p.name)}" data-price="${Number(p.price || 0)}">
                <div style="font-weight:800;">${esc(p.name)}</div>
                <div class="ds-muted" style="text-align:left;padding:0;">SKU:${esc(p.sku || '')} • ${fmt(p.price)}</div>
              </button>`).join('');
            sgEl.querySelectorAll('.ds-suggest-item').forEach(it => {
              it.addEventListener('click', () => {
                const pId = Number(decodeURIComponent(it.dataset.id || '')) || null;
                const pName = it.dataset.name || 'Product';
                const pPrice = Number(it.dataset.price || 0);
                addProductToCart(pId, pName, pPrice);
              });
            });
          };

          qEl.addEventListener('input', () => { clearTimeout(t); t = setTimeout(search, 250); });

          ov.querySelector('#spfull').onclick = () => {
            const total = cart.length ? calcTotal() : Number(cartContainer.querySelector('#s-quick-amt')?.value || 0);
            payEl.value = String(total);
          };
          ov.querySelector('#sphalf').onclick = () => {
            const total = cart.length ? calcTotal() : Number(cartContainer.querySelector('#s-quick-amt')?.value || 0);
            payEl.value = String(total / 2);
          };
          ov.querySelector('#spzero').onclick = () => { payEl.value = '0'; };

          ov.querySelector('#sc').onclick = close;
          ov.querySelector('#sv').onclick = async () => {
            let salePayload = {};
            let totalAmount = 0;

            if (cart.length > 0) {
              totalAmount = calcTotal();
              if (totalAmount <= 0) { toast('প্রোডাক্টের মূল্য নির্ধারণ করুন'); return; }
              salePayload = {
                customer_id: c.id,
                items: cart.map(it => ({
                  product_id: it.product_id,
                  name: it.name,
                  qty: it.qty,
                  sell_price: it.price
                })),
                payment_amount: Number(payEl.value || 0),
                payment_method: ov.querySelector('#sm').value || 'Cash',
                sale_date: ov.querySelector('#sdt').value || today,
                payment_date: ov.querySelector('#spdt').value || today,
                notes: (ov.querySelector('#sn').value || '').trim()
              };
            } else {
              const qAmt = parseAmount(cartContainer.querySelector('#s-quick-amt')?.value || 0);
              const qQty = Math.max(1, Math.round(parseAmount(cartContainer.querySelector('#s-quick-qty')?.value || 1)));
              if (!(qAmt > 0)) { toast('প্রোডাক্ট যোগ করুন বা পরিমাণ দিন'); return; }
              totalAmount = qAmt * qQty;
              salePayload = {
                customer_id: c.id,
                amount: totalAmount,
                qty: qQty,
                payment_amount: parseAmount(payEl.value || 0),
                payment_method: ov.querySelector('#sm').value || 'Cash',
                sale_date: ov.querySelector('#sdt').value || today,
                payment_date: ov.querySelector('#spdt').value || today,
                notes: (ov.querySelector('#sn').value || '').trim(),
                product_name: 'WhatsApp Quick Sale'
              };
            }

            const res = await apiPost('ajax_quick_sale', salePayload);
            if (res && res.ok) {
              const paymentAmount = parseAmount(payEl.value || 0);
              const dueAmount = Math.max(0, totalAmount - paymentAmount);
              const invoiceNo = res.invoice_no || `INV-${res.sale_id}`;
              const company = _bootInfo?.company_name || 'Digital Store';

              let invoiceMsg = `🧾 *ইনভয়েস:* ${invoiceNo}\n📅 *তারিখ:* ${salePayload.sale_date}\n🛒 *মোট বিল:* ${fmt(totalAmount)}\n💵 *পরিশোধ:* ${fmt(paymentAmount)}\n⚠️ *বকেয়া:* ${fmt(dueAmount)}\n\nধন্যবাদ,\n*${company}*`;

              close();

              if (ov.querySelector('#si-chat')?.checked) {
                await pasteToChat(invoiceMsg, false);
                toast('ইনভয়েস চ্যাটে পেস্ট হয়েছে');
              } else {
                toast('সেল সংরক্ষিত হয়েছে');
              }

              setWorkflowState(flowKey, {
                kind: 'success',
                title: 'Sale saved',
                meta: `${invoiceNo} • Total: ${fmt(totalAmount)}`,
                items: [
                  { label: 'Invoice', value: invoiceNo },
                  { label: 'Total', value: fmt(totalAmount) },
                  { label: 'Paid', value: fmt(paymentAmount) }
                ]
              });

              await loadSafe(panel, id);
            } else {
              toast(esc(res?.error || 'ব্যর্থ'));
            }
          };
        });
    };

    // ── Payment Modal ────────────────────────────────────────────────────────
    const openPaymentModal = () => {
      if (!guardWrite()) return;
      closeMenu();
      const dueAmt = Number(s.total_due || 0);
      modal('পেমেন্ট গ্রহণ', `
        <div class="ds-soft-banner">বর্তমান বকেয়া: <b>${fmt(dueAmt)}</b></div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">পরিমাণ</label>
            <input class="ds-input" id="pa" inputmode="decimal" value="${dueAmt > 0 ? fmt(dueAmt) : ''}" placeholder="500"></div>
          <div class="ds-field"><label class="ds-label">পদ্ধতি</label>
            <select class="ds-select" id="pm"><option>Cash</option><option>bKash</option>
              <option>Nagad</option><option>Bank</option><option>Other</option></select></div>
        </div>
        <div class="ds-quick-fill-row">
          <button class="ds-chip-btn" type="button" id="pfull">Full Due</button>
          <button class="ds-chip-btn" type="button" id="phalf">Half</button>
        </div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">তারিখ</label>
            <input class="ds-input" id="pdt" type="date"></div>
          <div class="ds-field"><label class="ds-label">চ্যাটে রসিদ পেস্ট</label>
            <label class="ds-checkbox-label" style="display:flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer;"><input type="checkbox" id="p-paste" checked style="width:16px;height:16px;margin:0;"> <span>Paste Receipt</span></label></div>
        </div>
        <div class="ds-field"><label class="ds-label">নোট / TrxID</label>
          <textarea class="ds-textarea" id="pn" placeholder="ঐচ্ছিক"></textarea></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="pc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="ps">সেভ</button>
        </div>`,
        (ov, close) => {
          const payInput = ov.querySelector('#pa');
          const today = new Date().toISOString().slice(0, 10);
          ov.querySelector('#pdt').value = today;
          ov.querySelector('#pfull').onclick = () => { payInput.value = fmt(dueAmt); };
          ov.querySelector('#phalf').onclick = () => { payInput.value = fmt(dueAmt / 2); };
          ov.querySelector('#pc').onclick = close;
          const psBtn = ov.querySelector('#ps');
          psBtn.onclick = async () => {
            if (psBtn.disabled) return;
            const amt = parseAmount(ov.querySelector('#pa').value || 0);
            if (!(amt > 0)) { toast('পরিমাণ দিন'); return; }
            psBtn.disabled = true;
            try {
              const method = ov.querySelector('#pm').value || 'Cash';
              const res = await apiPost('ajax_quick_payment', {
                customer_id: c.id, amount: amt,
                method,
                payment_date: ov.querySelector('#pdt').value || today,
                note: (ov.querySelector('#pn').value || '').trim()
              });
              if (res && res.ok) {
                const company = _bootInfo?.company_name || 'Digital Store';
                const payReceipt = `✅ *পেমেন্ট প্রাপ্তি রসিদ*\n👤 *গ্রাহক:* ${c.name}\n💵 *পরিশোধ:* ${fmt(amt)}\n📅 *তারিখ:* ${today}\n💳 *পদ্ধতি:* ${method}\n\nধন্যবাদ,\n*${company}*`;

                if (ov.querySelector('#p-paste').checked) {
                  await pasteToChat(payReceipt, false);
                }

                setWorkflowState(flowKey, {
                  kind: 'success',
                  title: 'Payment recorded',
                  meta: `Amount: ${fmt(amt)} (${method})`,
                  items: [
                    { label: 'Collected', value: fmt(amt) },
                    { label: 'Method', value: method }
                  ]
                });
                close();
                await loadSafe(panel, id);
                toast('পেমেন্ট সংরক্ষিত হয়েছে');
              } else {
                toast(esc(res?.error || 'ব্যর্থ'));
                psBtn.disabled = false;
              }
            } catch (err) {
              psBtn.disabled = false;
            }
          };
        });
    };

    // ── Invoice Picker ───────────────────────────────────────────────────────
    const openInvoicePicker = () => {
      closeMenu();
      if (!sales.length) { toast('কোনো ইনভয়েস নেই'); return; }
      modal('ইনভয়েস পাঠান', `
        <div class="ds-suggest">
          ${sales.slice(0, 8).map(r => {
            const due = Math.max(0, Number(r.total_sell || 0) - Number(r.paid_amount || 0));
            return `
              <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 10px;margin-bottom:6px;">
                <div style="font-weight:900;color:var(--text);">${esc(r.invoice_no || '')}</div>
                <div class="ds-muted" style="text-align:left;padding:2px 0;">তারিখ: ${esc(r.sale_date || '')} • মোট:${fmt(r.total_sell)} • বাকি:${fmt(due)}</div>
                <div style="display:flex;gap:5px;margin-top:6px;">
                  <button type="button" class="ds-btn ds-btn-compact ds-btn-paste" data-inv-paste="${r.id}" data-invno="${esc(r.invoice_no)}" data-sell="${Number(r.total_sell)}" data-paid="${Number(r.paid_amount)}" data-dt="${esc(r.sale_date)}">💬 চ্যাটে পেস্ট</button>
                  <button type="button" class="ds-btn ds-btn-compact ds-btn-primary" data-inv-send="${r.id}" data-invno="${esc(r.invoice_no)}" data-sell="${Number(r.total_sell)}" data-paid="${Number(r.paid_amount)}" data-dt="${esc(r.sale_date)}">🚀 পাঠান</button>
                  <button type="button" class="ds-btn ds-btn-compact ds-btn-outline" data-inv-api="${r.id}">🌐 API</button>
                </div>
              </div>`;
          }).join('')}
        </div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="ic">বন্ধ করুন</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#ic').onclick = close;

          const getInvoiceMsg = (invno, sell, paid, dt) => {
            const company = _bootInfo?.company_name || 'Digital Store';
            const due = Math.max(0, sell - paid);
            return `🧾 *ইনভয়েস:* ${invno}\n📅 *তারিখ:* ${dt}\n👤 *গ্রাহক:* ${c.name}\n🛒 *মোট বিল:* ${fmt(sell)}\n💵 *পরিশোধ:* ${fmt(paid)}\n⚠️ *বকেয়া:* ${fmt(due)}\n\nধন্যবাদ,\n*${company}*`;
          };

          ov.querySelectorAll('[data-inv-paste]').forEach(btn => {
            btn.onclick = async () => {
              const msg = getInvoiceMsg(btn.dataset.invno, Number(btn.dataset.sell), Number(btn.dataset.paid), btn.dataset.dt);
              close();
              await pasteToChat(msg, false);
              toast('ইনভয়েস চ্যাটে পেস্ট হয়েছে');
            };
          });

          ov.querySelectorAll('[data-inv-send]').forEach(btn => {
            btn.onclick = async () => {
              const msg = getInvoiceMsg(btn.dataset.invno, Number(btn.dataset.sell), Number(btn.dataset.paid), btn.dataset.dt);
              close();
              await pasteToChat(msg, true);
              toast('ইনভয়েস পাঠানো হয়েছে');
            };
          });

          ov.querySelectorAll('[data-inv-api]').forEach(btn => {
            btn.onclick = async () => {
              const sid = Number(btn.dataset.invApi);
              const res = await apiPost('ajax_send_invoice', { sale_id: sid });
              if (res.ok) {
                close();
                toast('ইনভয়েস API দিয়ে পাঠানো হয়েছে');
                await loadSafe(panel, id);
              } else {
                apiError('API পাঠাতে ব্যর্থ', res);
              }
            };
          });
        });
    };

    aEl.querySelector('#ds-sale-fast')?.addEventListener('click', openSaleModal);
    aEl.querySelector('#ds-deliver-fast')?.addEventListener('click', openDeliveryModal);
    aEl.querySelector('#ds-deliver')?.addEventListener('click', openDeliveryModal);
    aEl.querySelector('#ds-pay-fast')?.addEventListener('click', openPaymentModal);
    aEl.querySelector('#ds-sinv-fast')?.addEventListener('click', openInvoicePicker);

    if (id?.nameMatch) {
      aEl.querySelector('#ds-bindph')?.addEventListener('click', async () => {
        const dp = normalizePhone(c.phone || '');
        if (!dp) { toast('ফোন পাওয়া যায়নি'); return; }
        const ks = [id.key, id.titleKey, id.name].filter(Boolean);
        if (!ks.length) { toast('চ্যাট কী পাওয়া যায়নি'); return; }
        await saveBinding(ks, dp);
        toast('✓ লিংক হয়েছে');
        await loadSafe(panel, { ...id, phone: dp, nameMatch: false });
      });
    }

    rEl.innerHTML = `
      ${pendingTasksHtml(pendingTasks)}
      ${customerTimelineHtml(evts)}
      <div class="ds-subtitle">সাম্প্রতিক লেনদেন</div>
      <div class="ds-list">
        ${evts.map(e => `
          <div class="ds-list-item">
            <div>
              <div class="ds-list-label">${e.type === 'sale' ? 'বিক্রয়' : 'পেমেন্ট'} · ${esc(e.invoice_no || '')}</div>
              <div class="ds-list-sub">${esc(e.date || '')}</div>
            </div>
            <div class="ds-list-amount ${e.type === 'sale' ? 'ds-red' : 'ds-green'}">
              ${e.type === 'sale' ? '−' : '+'}${fmt(e.amount)}
            </div>
          </div>`).join('') || '<div class="ds-muted" style="padding:16px 0;">কোনো লেনদেন পাওয়া যায়নি</div>'}
      </div>
      ${notes.length ? `
        <div class="ds-subtitle">নোটসমূহ</div>
        <div class="ds-list">
          ${notes.map(n => `<div class="ds-list-item" style="flex-direction:column;align-items:flex-start;">
            <div class="ds-list-sub" style="margin-bottom:3px;">${esc(n.created_at || '')}</div>
            <div class="ds-list-label" style="font-weight:400;">${esc(n.note || '')}</div>
          </div>`).join('')}
        </div>`: ''}`;

    // ── Pending tasks interaction ─────────────────────────────────────────────
    rEl.querySelectorAll('[data-task-view]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tid = Number(btn.dataset.taskView || 0);
        const t = pendingTasks.find(x => x.id === tid);
        if (!t) return;
        modal('📋 কাজের বিবরণ', `
          <div class="ds-field"><label class="ds-label">কাজের নাম</label>
            <div style="font-weight:800;font-size:14px;padding:6px 0;">${esc(t.task_name)}</div></div>
          <div class="ds-field"><label class="ds-label">মূল্য</label>
            <div style="font-weight:700;color:var(--ds-accent);padding:6px 0;">${fmt(t.amount)}</div></div>
          <div class="ds-field"><label class="ds-label">তারিখ</label>
            <div class="ds-muted" style="padding:6px 0;">${esc(t.created_at || '')}</div></div>
          ${t.notes ? `<div class="ds-field"><label class="ds-label">বিস্তারিত নোট</label>
            <div style="background:var(--ds-bg2);padding:10px 12px;border-radius:10px;border:1px solid var(--ds-border);
              font-size:12px;line-height:1.7;white-space:pre-wrap;word-break:break-word;max-height:220px;overflow:auto;">${esc(t.notes)}</div></div>` : ''}
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="tv-close">বন্ধ করুন</button>
          </div>`,
          (ov, close) => { ov.querySelector('#tv-close').onclick = close; });
      });
    });

    rEl.querySelectorAll('[data-task-deliver]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tid = Number(btn.dataset.taskDeliver || 0);
        const t = pendingTasks.find(x => x.id === tid);
        if (!t || !guardWrite()) return;
        modal('ডেলিভারি — ' + esc(t.task_name), `
          <div class="ds-soft-banner">কাজ: <b>${esc(t.task_name)}</b></div>
          <div class="ds-row">
            <div class="ds-field"><label class="ds-label">মূল্য / রেট (৳)</label>
              <input class="ds-input" id="td-amt" inputmode="decimal" value="${t.amount}"></div>
            <div class="ds-field"><label class="ds-label">পেমেন্ট গ্রহণ (৳)</label>
              <input class="ds-input" id="td-pay" inputmode="decimal" value="${t.amount}" placeholder="0"></div>
          </div>
          <div class="ds-field"><label class="ds-label">পেমেন্ট পদ্ধতি</label>
            <select class="ds-select" id="td-method"><option>Cash</option><option>bKash</option><option>Nagad</option><option>Bank</option><option>Other</option></select></div>
          <div class="ds-quick-fill-row">
            <button class="ds-chip-btn" type="button" id="td-full">Full Paid</button>
            <button class="ds-chip-btn" type="button" id="td-due">Due Sale</button>
          </div>
          <div class="ds-row">
            <div class="ds-field"><label class="ds-label">সেল তারিখ</label>
              <input class="ds-input" id="td-sdt" type="date"></div>
            <div class="ds-field"><label class="ds-label">পেমেন্ট তারিখ</label>
              <input class="ds-input" id="td-pdt" type="date"></div>
          </div>
          <div class="ds-field"><label class="ds-label">নোট</label>
            <textarea class="ds-textarea" id="td-notes" placeholder="ঐচ্ছিক"></textarea></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="td-cancel">বাতিল</button>
            <button class="ds-btn ds-btn-block" type="button" id="td-save">ডেলিভারি ✓</button>
          </div>`,
          (ov, close) => {
            const today = new Date().toISOString().slice(0, 10);
            ov.querySelector('#td-sdt').value = today;
            ov.querySelector('#td-pdt').value = today;
            const amtInp = ov.querySelector('#td-amt');
            const payInp = ov.querySelector('#td-pay');
            ov.querySelector('#td-full').onclick = () => { payInp.value = amtInp.value; };
            ov.querySelector('#td-due').onclick = () => { payInp.value = '0'; };
            amtInp.addEventListener('input', () => {
              if (parseAmount(payInp.value) === parseAmount(t.amount)) {
                payInp.value = amtInp.value;
              }
            });
            ov.querySelector('#td-cancel').onclick = close;
            ov.querySelector('#td-save').onclick = async () => {
              const finalAmt = parseAmount(amtInp.value || t.amount);
              const finalPay = parseAmount(payInp.value || 0);
              const res = await apiPost('ajax_task_deliver', {
                task_id: t.id,
                amount: finalAmt,
                payment_amount: finalPay,
                payment_method: ov.querySelector('#td-method').value || 'Cash',
                sale_date: ov.querySelector('#td-sdt').value || today,
                payment_date: ov.querySelector('#td-pdt').value || today,
                notes: (ov.querySelector('#td-notes').value || '').trim(),
              });
              if (res.ok) {
                setWorkflowState(flowKey, {
                  kind: 'success', title: 'Task delivered',
                  meta: esc(t.task_name),
                  items: [
                    { label: 'Invoice', value: String(res.invoice_no || '') },
                    { label: 'Amount', value: fmt(finalAmt) },
                    { label: 'Paid', value: fmt(finalPay) },
                  ]
                });
                close(); toast('ডেলিভারি সম্পন্ন'); await loadSafe(panel, id);
              } else toast(esc(res.error || 'ব্যর্থ'));
            };
          });
      });
    });

    rEl.querySelectorAll('[data-task-edit]').forEach(btn => {
      btn.addEventListener('click', () => {
        const tid = Number(btn.dataset.taskEdit || 0);
        const t = pendingTasks.find(x => x.id === tid);
        if (!t || !guardWrite()) return;
        modal('কাজ এডিট', `
          <div class="ds-field"><label class="ds-label">কাজের নাম</label>
            <input class="ds-input" id="te-name" value="${esc(t.task_name)}"></div>
          <div class="ds-field"><label class="ds-label">মূল্য / রেট (৳)</label>
            <input class="ds-input" id="te-amt" inputmode="decimal" value="${t.amount}"></div>
          <div class="ds-field"><label class="ds-label">বিস্তারিত নোট</label>
            <textarea class="ds-textarea" id="te-notes" rows="5" style="min-height:100px;">${esc(t.notes || '')}</textarea></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="te-cancel">বাতিল</button>
            <button class="ds-btn ds-btn-block" type="button" id="te-save">সেভ</button>
          </div>`,
          (ov, close) => {
            ov.querySelector('#te-cancel').onclick = close;
            ov.querySelector('#te-save').onclick = async () => {
              const name = (ov.querySelector('#te-name').value || '').trim();
              if (!name) { toast('কাজের নাম দিন'); return; }
              const finalAmt = parseAmount(ov.querySelector('#te-amt').value || 0);
              const res = await apiPost('ajax_task_update', {
                task_id: t.id,
                task_name: name,
                amount: finalAmt,
                notes: (ov.querySelector('#te-notes').value || '').trim(),
              });
              if (res.ok) { close(); toast('আপডেট হয়েছে'); await loadSafe(panel, id); }
              else toast(esc(res.error || 'ব্যর্থ'));
            };
          });
      });
    });

    // ── Sale ─────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-sale')?.addEventListener('click', openSaleModal);

    // ── Payment ───────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-pay')?.addEventListener('click', openPaymentModal);

    // ── Note ──────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-note')?.addEventListener('click', () => {
      closeMenu();
      modal('নোট যোগ', `
        <div class="ds-field"><label class="ds-label">নোট</label>
          <textarea class="ds-textarea" id="nt" placeholder="নোট লিখুন"></textarea></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="nc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="ns">সেভ</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#nc')?.addEventListener('click', close);
          ov.querySelector('#ns')?.addEventListener('click', async () => {
            const note = (ov.querySelector('#nt')?.value || '').trim();
            if (!note) { toast('নোট লিখুন'); return; }
            const res = await apiPost('ajax_customer_note_add', { customer_id: c.id, note });
            if (res.ok) { close(); await loadSafe(panel, id); toast('নোট যোগ হয়েছে'); }
            else toast(esc(res.error || 'ব্যর্থ'));
          });
        });
    });

    // ── Update ────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-upd')?.addEventListener('click', () => {
      closeMenu();
      modal('কাস্টমার আপডেট', `
        <div class="ds-field"><label class="ds-label">নাম</label>
          <input class="ds-input" id="un" value="${esc(c.name || '')}"></div>
        <div class="ds-field"><label class="ds-label">ফোন</label>
          <input class="ds-input" id="up" value="${esc(c.phone || '')}"></div>
        <div class="ds-field"><label class="ds-label">ইমেইল</label>
          <input class="ds-input" id="ue" value="${esc(c.email || '')}"></div>
        <div class="ds-field"><label class="ds-label">ঠিকানা</label>
          <textarea class="ds-textarea" id="ua">${esc(c.address || '')}</textarea></div>
        <div class="ds-field"><label class="ds-label">ক্যাটাগরি</label>
          <select class="ds-select" id="uc">
            <option value="Regular" ${c.category !== 'VIP' ? 'selected' : ''}>Regular</option>
            <option value="VIP" ${c.category === 'VIP' ? 'selected' : ''}>VIP</option>
          </select></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="ux">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="us">সেভ</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#ux')?.addEventListener('click', close);
          ov.querySelector('#us')?.addEventListener('click', async () => {
            const res = await apiPost('ajax_customer_update', {
              id: c.id,
              name: (ov.querySelector('#un')?.value || '').trim(),
              phone: (ov.querySelector('#up')?.value || '').trim(),
              email: (ov.querySelector('#ue')?.value || '').trim(),
              address: (ov.querySelector('#ua')?.value || '').trim(),
              category: ov.querySelector('#uc')?.value || 'Regular'
            });
            if (res.ok) { close(); await loadSafe(panel, id); toast('আপডেট হয়েছে'); }
            else toast(esc(res.error || 'ব্যর্থ'));
          });
        });
    });

    aEl.querySelector('#ds-ssum')?.addEventListener('click', async () => {
      closeMenu();
      const r = await apiPost('ajax_send_notification', { customer_id: c.id, type: 'summary' });
      r.ok ? toast('সামারি পাঠানো হয়েছে') : apiError('সামারি পাঠাতে ব্যর্থ', r);
    });

    const openDueReminderModal = () => {
      closeMenu();
      const duePayInfo = String(_bootInfo?.payment_info || '').trim();
      const dueAmt2 = Number(s.total_due || 0);
      const company = _bootInfo?.company_name || 'Digital Store';
      
      const defaultReminderText = `সম্মানিত গ্রাহক ${c.name || ''},\nআপনার ডিজিটাল স্টোর অ্যাকাউন্টে বর্তমান বকেয়া রয়েছে: *${fmt(dueAmt2)}* টাকা।\nঅনুগ্রহ করে বকেয়া পরিশোধ করার অনুরোধ করা হলো।\n${duePayInfo ? `\nপেমেন্ট পদ্ধতি:\n${duePayInfo}\n` : ''}\nধন্যবাদ,\n*${company}*`;

      modal('বকেয়া রিমাইন্ডার', `
        <div class="ds-card" style="margin-top:0;margin-bottom:10px;">
          <div class="ds-kpi-label">বর্তমান মোট বকেয়া</div>
          <div class="ds-kpi-value red" style="font-size:16px;">${fmt(dueAmt2)}</div>
        </div>
        <div class="ds-field">
          <label class="ds-label">রিমাইন্ডার মেসেজ</label>
          <textarea class="ds-textarea" id="due-msg" rows="5">${esc(defaultReminderText)}</textarea>
        </div>
        <div class="ds-modal-actions" style="grid-template-columns: 1fr 1fr;">
          <button class="ds-btn ds-btn-secondary" type="button" id="drc-paste">💬 চ্যাটে পেস্ট</button>
          <button class="ds-btn ds-btn-primary" type="button" id="drc-send">🚀 সরাসরি পাঠান</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#drc-paste')?.addEventListener('click', async () => {
            const txt = ov.querySelector('#due-msg')?.value || '';
            close();
            await pasteToChat(txt, false);
            toast('রিমাইন্ডার চ্যাটে পেস্ট হয়েছে');
          });
          ov.querySelector('#drc-send')?.addEventListener('click', async () => {
            const txt = ov.querySelector('#due-msg')?.value || '';
            close();
            await pasteToChat(txt, true);
            toast('রিমাইন্ডার পাঠানো হয়েছে');
          });
        });
    };

    aEl.querySelector('#ds-sdue')?.addEventListener('click', openDueReminderModal);
    aEl.querySelector('#ds-sdue-fast')?.addEventListener('click', openDueReminderModal);
    aEl.querySelector('#ds-sinv')?.addEventListener('click', openInvoicePicker);

    // ── Task Add (from menu) ──────────────────────────────────────────────────
    aEl.querySelector('#ds-task-add')?.addEventListener('click', () => {
      if (!guardWrite()) return;
      closeMenu();
      openTaskAddModal(panel, c, id, flowKey, canWrite);
    });

    // ── Auto-detected application handlers ────────────────────────────────────
    aEl.querySelector('#ds-detected-add')?.addEventListener('click', () => {
      if (!guardWrite()) return;
      openTaskAddModal(panel, c, id, flowKey, canWrite, detectedApp);
    });
    aEl.querySelector('#ds-detected-open')?.addEventListener('click', () => {
      if (!guardWrite()) return;
      openTaskAddModal(panel, c, id, flowKey, canWrite, detectedApp);
    });

    // ── Tasks button (inline & KPI box) ──────────────────────────────────────
    const handleTasksClick = () => {
      if (pendingTaskCount > 0) {
        const tasksSection = rEl.querySelector('.ds-pending-tasks-section');
        if (tasksSection) tasksSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        if (!guardWrite()) return;
        openTaskAddModal(panel, c, id, flowKey, canWrite);
      }
    };
    aEl.querySelector('#ds-tasks-fast')?.addEventListener('click', handleTasksClick);
    cEl.querySelector('#ds-kpi-tasks')?.addEventListener('click', handleTasksClick);
  }

  // ── Task helpers ──────────────────────────────────────────────────────────
  function pendingTasksHtml(tasks) {
    if (!tasks || !tasks.length) return '';
    return `
      <div class="ds-pending-tasks-section">
        <div class="ds-subtitle" style="display:flex;align-items:center;gap:6px;">
          📋 অপেক্ষমান কাজ
          <span style="background:var(--ds-accent);color:#fff;font-size:10px;font-weight:800;
            padding:1px 7px;border-radius:10px;">${tasks.length}</span>
        </div>
        <div class="ds-list">
          ${tasks.map(t => `
            <div class="ds-list-item" style="flex-direction:column;align-items:stretch;gap:6px;padding:10px 0;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                  <div style="font-weight:800;font-size:12px;">${esc(t.task_name)}</div>
                  <div class="ds-list-sub">${esc(t.created_at || '')}${t.notes ? ' • নোট আছে' : ''}</div>
                </div>
                <div style="font-weight:700;color:var(--ds-accent);font-size:12px;white-space:nowrap;">${fmt(t.amount)}</div>
              </div>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">
                <button class="ds-chip-btn" data-task-view="${t.id}" type="button">বিস্তারিত</button>
                <button class="ds-chip-btn" data-task-edit="${t.id}" type="button" style="color:var(--ds-text2);">এডিট</button>
                <button class="ds-chip-btn" data-task-deliver="${t.id}" type="button"
                  style="background:var(--ds-accent);color:#fff;">ডেলিভারি ✓</button>
              </div>
            </div>
          `).join('')}
        </div>
      </div>`;
  }

  function openTaskAddModal(panel, c, id, flowKey, canWrite, initialApp = null, onDone = null) {
    let selProduct = { id: null, name: '', price: 0 };
    let currentApp = initialApp;

    modal('নতুন কাজ যোগ', `
      <div style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap;">
        <button class="ds-chip-btn" id="ta-btn-autofill" type="button" style="background:var(--ds-accent);color:#fff;">⚡ চ্যাট থেকে অটোফিল</button>
        <button class="ds-chip-btn" id="ta-btn-paste" type="button">📋 টেক্সট পেস্ট করুন</button>
      </div>
      <div id="ta-paste-box" class="ds-hidden" style="margin-bottom:10px;background:var(--ds-bg2);padding:8px;border-radius:8px;border:1px solid var(--ds-border);">
        <textarea class="ds-textarea" id="ta-paste-input" rows="3" placeholder="কাস্টমারের পাঠানো মেসেজ এখানে পেস্ট করুন..."></textarea>
        <button class="ds-btn ds-btn-sm ds-btn-block" type="button" id="ta-paste-apply" style="margin-top:5px;">অটোফিল করুন</button>
      </div>
      <div class="ds-field"><label class="ds-label">প্রোডাক্ট / সার্ভিস</label>
        <input class="ds-input" id="ta-pq" placeholder="নাম বা SKU দিয়ে খুঁজুন (যেমন: New Birth)">
        <div class="ds-suggest" id="ta-ps"></div></div>
      <div class="ds-field"><label class="ds-label">কাজের নাম *</label>
        <input class="ds-input" id="ta-name" placeholder="যেমন: New Birth - DR. MD. NAJEEBUR RAHMAN (265979535)"></div>
      <div class="ds-field"><label class="ds-label">মূল্য / রেট (৳)</label>
        <input class="ds-input" id="ta-amt" inputmode="decimal" placeholder="0">
        <label style="display:flex;align-items:center;gap:6px;font-size:11px;margin-top:5px;cursor:pointer;">
          <input type="checkbox" id="ta-save-custom-rate">
          <span>এই কাস্টমারের জন্য এই রেট সেভ রাখুন (Custom Rate)</span>
        </label>
      </div>
      <div class="ds-field"><label class="ds-label">বিস্তারিত নোট / ডেটা</label>
        <textarea class="ds-textarea" id="ta-notes" rows="5" style="min-height:100px;"
          placeholder="কাজের বিবরণ, ফাইল তথ্য, নির্দেশনা ইত্যাদি..."></textarea></div>
      <div id="ta-rename-wrap" class="ds-hidden" style="margin-bottom:10px;">
        <label style="display:flex;align-items:center;gap:6px;font-size:11px;cursor:pointer;">
          <input type="checkbox" id="ta-cust-rename" checked>
          <span id="ta-rename-label">কাস্টমার নামও আপডেট করুন</span>
        </label>
      </div>
      <div class="ds-modal-actions">
        <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="ta-cancel">বাতিল</button>
        <button class="ds-btn ds-btn-block" type="button" id="ta-save">সেভ</button>
      </div>`,
      (ov, close) => {
        const pqEl = ov.querySelector('#ta-pq'), psEl = ov.querySelector('#ta-ps');
        const nameEl = ov.querySelector('#ta-name'), amtEl = ov.querySelector('#ta-amt');
        let searchTimer = null;

        const applyAppToForm = async (app) => {
          if (!app) return;
          currentApp = app;

          // Set task name
          const taskTitle = (app.isBirth ? 'New Birth' : 'কাজ') +
            (app.name ? ` - ${app.name}` : '') +
            (app.appId ? ` (${app.appId})` : '');
          nameEl.value = taskTitle;

          // Build notes
          const lines = [];
          lines.push('আবেদনের তথ্য:');
          if (app.appId) lines.push(`Application ID: ${app.appId}`);
          if (app.nameBn) lines.push(`নাম (বাংলা): ${app.nameBn}`);
          if (app.nameEn) lines.push(`Name (English): ${app.nameEn}`);
          if (app.dob) lines.push(`Date of Birth: ${app.dob}`);
          if (app.address) lines.push(`Office Address: ${app.address}`);
          ov.querySelector('#ta-notes').value = lines.join('\n');

          // If customer name is empty or phone number, suggest renaming
          if (app.name && (!c.name || c.name === c.phone || /^\d+$/.test(c.name.trim()))) {
            const renameWrap = ov.querySelector('#ta-rename-wrap');
            const renameLabel = ov.querySelector('#ta-rename-label');
            if (renameWrap && renameLabel) {
              renameLabel.textContent = `কাস্টমার নাম "${app.name}" হিসেবে সেভ করুন`;
              renameWrap.classList.remove('ds-hidden');
            }
          }

          // Auto lookup product price (e.g. New Birth)
          const searchKeyword = app.isBirth ? 'New Birth' : 'Birth';
          pqEl.value = searchKeyword;
          try {
            const res = await apiGet('ajax_products_lookup', { q: searchKeyword, customer_id: c.id });
            if (res.ok && Array.isArray(res.products) && res.products.length > 0) {
              const p = res.products.find(x => x.name.toLowerCase().includes('new birth')) || res.products[0];
              selProduct = { id: p.id, name: p.name, price: Number(p.price || 0) };
              pqEl.value = p.name;
              amtEl.value = String(p.price);
            } else if (!amtEl.value || amtEl.value === '0') {
              amtEl.value = '650';
            }
          } catch {
            if (!amtEl.value || amtEl.value === '0') amtEl.value = '650';
          }
          toast('অটোফিল সফল হয়েছে!');
        };

        ov.querySelector('#ta-btn-autofill')?.addEventListener('click', async () => {
          const app = detectChatApplication();
          if (app) {
            await applyAppToForm(app);
          } else {
            toast('চ্যাটে কোনো আবেদন বার্তা পাওয়া যায়নি। পেস্ট বাটন ব্যবহার করুন।');
          }
        });

        ov.querySelector('#ta-btn-paste')?.addEventListener('click', () => {
          ov.querySelector('#ta-paste-box')?.classList.toggle('ds-hidden');
        });

        ov.querySelector('#ta-paste-apply')?.addEventListener('click', async () => {
          const raw = ov.querySelector('#ta-paste-input')?.value || '';
          const parsed = parseApplicationText(raw);
          if (parsed) {
            await applyAppToForm(parsed);
            ov.querySelector('#ta-paste-box')?.classList.add('ds-hidden');
          } else {
            toast('মেসেজটি সঠিক ফরম্যাটে নেই (Application ID, Name ইত্যাদি দিন)');
          }
        });

        if (initialApp) {
          applyAppToForm(initialApp);
        }

        const doSearch = async () => {
          const q = pqEl.value.trim();
          if (!q) { psEl.innerHTML = ''; return; }
          const res = await apiGet('ajax_products_lookup', { q, customer_id: c.id });
          if (!res.ok || !Array.isArray(res.products)) { psEl.innerHTML = ''; return; }
          psEl.innerHTML = res.products.slice(0, 6).map(p => `
            <button type="button" class="ds-suggest-item"
              data-id="${encodeURIComponent(p.id)}" data-name="${esc(p.name)}" data-price="${Number(p.price || 0)}">
              <div style="font-weight:800;">${esc(p.name)}</div>
              <div class="ds-muted" style="text-align:left;padding:0;">SKU:${esc(p.sku || '')} • ${fmt(p.price)}</div>
            </button>`).join('');
          psEl.querySelectorAll('.ds-suggest-item').forEach(it => {
            it.addEventListener('click', () => {
              selProduct = {
                id: Number(decodeURIComponent(it.dataset.id || '')) || null,
                name: it.dataset.name || '',
                price: Number(it.dataset.price || 0),
              };
              nameEl.value = selProduct.name;
              amtEl.value = String(selProduct.price);
              pqEl.value = selProduct.name;
              psEl.innerHTML = '';
            });
          });
        };
        pqEl.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 250); });
        ov.querySelector('#ta-cancel').onclick = close;
        ov.querySelector('#ta-save').onclick = async () => {
          const name = (nameEl.value || '').trim();
          if (!name) { toast('কাজের নাম দিন'); return; }
          const finalAmount = parseAmount(amtEl.value || 0);
          const res = await apiPost('ajax_task_add', {
            customer_id: c.id,
            task_name: name,
            amount: finalAmount,
            notes: (ov.querySelector('#ta-notes').value || '').trim(),
          });
          if (res.ok) {
            if (ov.querySelector('#ta-save-custom-rate')?.checked && selProduct.id) {
              apiPost('ajax_custom_price_save', {
                customer_id: c.id,
                product_id: selProduct.id,
                custom_price: finalAmount,
              }).catch(() => {});
            }
            if (ov.querySelector('#ta-cust-rename')?.checked && currentApp && currentApp.name) {
              apiPost('ajax_customer_update', {
                customer_id: c.id,
                name: currentApp.name,
              }).catch(() => {});
            }
            setWorkflowState(flowKey, {
              kind: 'info', title: 'কাজ যোগ হয়েছে',
              meta: 'Pending task',
              items: [
                { label: 'কাজ', value: name },
                { label: 'মূল্য', value: fmt(finalAmount) },
              ]
            });
            if (currentApp && currentApp.appId) {
              _appIdCache.set(currentApp.appId, { ok: true, exists: true, data: { id: res.task_id, task_name: name } });
            }
            document.getElementById('ds-app-floating-pill')?.remove();
            if (typeof onDone === 'function') onDone(res);
            close(); toast('কাজ যোগ হয়েছে'); await loadSafe(panel, id);
          } else toast(esc(res.error || 'ব্যর্থ'));
        };
      });
  }

  // ── Templates Tab ───────────────────────────────────────────────────────────
  async function renderTemplatesTab(panel, currentCustomer = null) {
    const mount = panel.querySelector('#ds-templates-mount');
    if (!mount) return;
    mount.innerHTML = '<div class="ds-loading"><div class="ds-spinner"></div>টেমপ্লেট লোড হচ্ছে...</div>';
    const res = await apiGet('ajax_quick_templates');
    if (!res || !res.ok || !Array.isArray(res.templates)) {
      mount.innerHTML = '<div class="ds-muted">টেমপ্লেট লোড করা যায়নি</div>';
      return;
    }

    const tpls = res.templates;
    const custName = currentCustomer?.name || 'সম্মানিত গ্রাহক';
    const custDue = currentCustomer?.due ? fmt(currentCustomer.due) : '০';
    const company = _bootInfo?.company_name || 'Digital Store';
    const custPhone = currentCustomer?.phone || '';

    const replacePlaceholders = (text, extra = {}) => {
      return String(text || '')
        .replace(/\{customer_name\}/gi, custName)
        .replace(/\{due_amount\}/gi, custDue)
        .replace(/\{company_name\}/gi, company)
        .replace(/\{phone\}/gi, custPhone)
        .replace(/\{product_name\}/gi, extra.product_name || 'Digital Subscription')
        .replace(/\{email\}/gi, extra.email || 'user@example.com')
        .replace(/\{password\}/gi, extra.password || '******')
        .replace(/\{expiry\}/gi, extra.expiry || '1 Month');
    };

    const renderList = (filterText = '', activeCat = 'all') => {
      let filtered = tpls;
      if (filterText.trim()) {
        const q = filterText.toLowerCase().trim();
        filtered = filtered.filter(t => (t.title && t.title.toLowerCase().includes(q)) || (t.content && t.content.toLowerCase().includes(q)) || (t.shortcut && t.shortcut.toLowerCase().includes(q)));
      }
      if (activeCat !== 'all') {
        filtered = filtered.filter(t => {
          const combined = `${t.title || ''} ${t.content || ''}`.toLowerCase();
          if (activeCat === 'payment') return combined.includes('বিকাশ') || combined.includes('নগদ') || combined.includes('পেমেন্ট') || combined.includes('bank') || combined.includes('bkash');
          if (activeCat === 'delivery') return combined.includes('ডেলিভারি') || combined.includes('অর্ডার') || combined.includes('পাসওয়ার্ড') || combined.includes('pass');
          if (activeCat === 'due') return combined.includes('বকেয়া') || combined.includes('বাকি') || combined.includes('due');
          return true;
        });
      }

      const listEl = mount.querySelector('#ds-tpl-items-container');
      if (!listEl) return;

      if (!filtered.length) {
        listEl.innerHTML = '<div class="ds-empty"><span class="ds-empty-icon">🔍</span>কোনো টেমপ্লেট মেলেনি</div>';
        return;
      }

      listEl.innerHTML = `
        <div class="ds-tpl-list">
          ${filtered.map(t => {
            const processed = replacePlaceholders(t.content);
            return `
              <div class="ds-tpl-card" data-tid="${esc(t.id)}">
                <div class="ds-tpl-head">
                  <span class="ds-tpl-title">${esc(t.title)}</span>
                  ${t.shortcut ? `<span class="ds-tpl-shortcut">/${esc(t.shortcut)}</span>` : ''}
                </div>
                <div class="ds-tpl-preview">${esc(processed)}</div>
                <div class="ds-tpl-actions">
                  <button class="ds-btn ds-btn-secondary ds-btn-compact" data-action="paste" type="button">💬 পেস্ট</button>
                  <button class="ds-btn ds-btn-primary ds-btn-compact" data-action="send" type="button">🚀 পাঠান</button>
                  <button class="ds-btn ds-btn-outline ds-btn-compact" data-action="copy" type="button" style="grid-column: span 2;">📋 কপি করুন</button>
                </div>
              </div>`;
          }).join('')}
        </div>`;

      listEl.querySelectorAll('.ds-tpl-card').forEach(card => {
        const tid = Number(card.dataset.tid);
        const tpl = tpls.find(x => Number(x.id) === tid);
        if (!tpl) return;
        const text = replacePlaceholders(tpl.content);

        card.querySelector('[data-action="paste"]')?.addEventListener('click', async () => {
          await pasteToChat(text, false);
          toast('চ্যাটে পেস্ট করা হয়েছে');
        });
        card.querySelector('[data-action="send"]')?.addEventListener('click', async () => {
          await pasteToChat(text, true);
          toast('মেসেজ পাঠানো হয়েছে');
        });
        card.querySelector('[data-action="copy"]')?.addEventListener('click', async () => {
          await navigator.clipboard.writeText(text);
          toast('ক্লিপবোর্ডে কপি হয়েছে');
        });
      });
    };

    mount.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span class="ds-subtitle" style="margin:0;">কুইক টেমপ্লেট (${tpls.length})</span>
        <button class="ds-chip-btn" id="ds-add-tpl-btn" type="button">+ নতুন</button>
      </div>

      <!-- Live Search & Category Filter -->
      <div style="margin-bottom: 8px;">
        <input class="ds-input" id="ds-tpl-search-input" placeholder="🔍 টেমপ্লেট খুঁজুন..." style="padding:6px 10px;font-size:11.5px;margin-bottom:6px;">
        <div class="ds-tpl-filter-bar">
          <button class="ds-tpl-filter-btn active" data-cat="all" type="button">সব</button>
          <button class="ds-tpl-filter-btn" data-cat="payment" type="button">পেমেন্ট</button>
          <button class="ds-tpl-filter-btn" data-cat="delivery" type="button">ডেলিভারি</button>
          <button class="ds-tpl-filter-btn" data-cat="due" type="button">বকেয়া</button>
        </div>
      </div>

      <div id="ds-tpl-items-container"></div>`;

    renderList();

    let curFilter = '';
    let curCat = 'all';

    mount.querySelector('#ds-tpl-search-input')?.addEventListener('input', (e) => {
      curFilter = e.target.value;
      renderList(curFilter, curCat);
    });

    mount.querySelectorAll('.ds-tpl-filter-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        mount.querySelectorAll('.ds-tpl-filter-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        curCat = btn.dataset.cat || 'all';
        renderList(curFilter, curCat);
      });
    });

    mount.querySelector('#ds-add-tpl-btn')?.addEventListener('click', () => {
      openAddTemplateModal(panel, currentCustomer);
    });
  }

  function openAddTemplateModal(panel, currentCustomer) {
    modal('নতুন টেমপ্লেট', `
      <div class="ds-field"><label class="ds-label">টাইটেল</label>
        <input class="ds-input" id="tpl-title" placeholder="যেমন: পেমেন্ট নির্দেশনা"></div>
      <div class="ds-field"><label class="ds-label">শর্টকাট (ঐচ্ছিক)</label>
        <input class="ds-input" id="tpl-sc" placeholder="pay"></div>
      <div class="ds-field"><label class="ds-label">মেসেজ কন্টেন্ট</label>
        <textarea class="ds-textarea" id="tpl-cnt" rows="6" placeholder="মেসেজ লিখুন... {customer_name}, {due_amount}, {company_name} ইত্যাদি ডাইনামিক ট্যাগ ব্যবহার করতে পারেন"></textarea></div>
      <div class="ds-modal-actions">
        <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="tpl-cx">বাতিল</button>
        <button class="ds-btn ds-btn-block" type="button" id="tpl-sv">সেভ</button>
      </div>`,
      (ov, close) => {
        ov.querySelector('#tpl-cx').onclick = close;
        ov.querySelector('#tpl-sv').onclick = async () => {
          const title = (ov.querySelector('#tpl-title').value || '').trim();
          const content = (ov.querySelector('#tpl-cnt').value || '').trim();
          const shortcut = (ov.querySelector('#tpl-sc').value || '').trim();
          if (!title || !content) { toast('টাইটেল ও কন্টেন্ট দিন'); return; }
          const res = await apiPost('ajax_quick_templates', { title, content, shortcut });
          if (res && res.ok) {
            close();
            toast('টেমপ্লেট সেভ হয়েছে');
            renderTemplatesTab(panel, currentCustomer);
          } else {
            toast(esc(res?.error || 'ব্যর্থ'));
          }
        };
      });
  }

  // ── Panel ──────────────────────────────────────────────────────────────────
  function ensurePanel() {
    let panel = document.getElementById('ds-wa-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'ds-wa-panel';
      panel.innerHTML = `
        <div class="ds-resize-handle" id="ds-resize"></div>
        <div class="ds-header" id="ds-drag-handle">
          <div class="ds-header-left">
            <div class="ds-logo-dot">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
            </div>
            <div class="ds-title-wrap">
              <div class="ds-title">Digital Store</div>
              <div class="ds-title-sub">CRM Assistant</div>
            </div>
          </div>
          <div class="ds-header-actions">
            <button class="ds-search-toggle-btn" id="ds-search-toggle" title="কাস্টমার সার্চ">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <button class="ds-refresh-btn" id="ds-rfr" title="রিফ্রেশ">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
            </button>
            <button class="ds-close" title="হাইড (Alt+D)">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>

        <!-- Collapsible Inline Customer Search Bar -->
        <div class="ds-quick-search-bar ds-hidden" id="ds-quick-search-bar">
          <input class="ds-quick-search-input" id="ds-header-quick-search" placeholder="ফোন বা নাম দিয়ে সার্চ করুন..." spellcheck="false">
          <button class="ds-btn ds-btn-primary" id="ds-header-search-btn" type="button" style="padding: 6px 10px; font-size: 11px;">খুঁজুন</button>
        </div>

        <div class="ds-tabs">
          <button class="ds-tab-btn active" data-tab="customer">
            <span class="ds-tab-indicator"></span>কাস্টমার
          </button>
          <button class="ds-tab-btn" data-tab="templates">
            <span class="ds-tab-indicator ds-hidden"></span>টেমপ্লেট
          </button>
          <button class="ds-tab-btn" data-tab="tools">
            <span class="ds-tab-indicator ds-hidden"></span>টুলস
          </button>
        </div>
        <div class="ds-body">
          <div class="ds-tab-content" id="ds-tab-customer">
            <div id="ds-customer">
              <div class="ds-empty"><span class="ds-empty-icon">💬</span>একটি চ্যাট সিলেক্ট করুন</div>
            </div>
            <div id="ds-actions"></div>
            <div id="ds-recent"></div>
          </div>
          <div class="ds-tab-content ds-hidden" id="ds-tab-templates">
            <div id="ds-templates-mount"></div>
          </div>
          <div class="ds-tab-content ds-hidden" id="ds-tab-tools">
            <div id="ds-tools-overview"></div>
            <div class="ds-card">
              <div class="ds-subtitle" style="margin-top:0;">কুইক অ্যাকশন</div>
              <div class="ds-tools-grid">
                <button class="ds-btn ds-btn-primary" id="ds-exp" data-write-action="1">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:11px;height:11px;display:block;flex-shrink:0;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>খরচ যোগ
                </button>
                <button class="ds-btn ds-btn-outline" id="ds-prc">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:11px;height:11px;display:block;flex-shrink:0;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>প্রাইস চেক
                </button>
                <button class="ds-btn ds-btn-outline" id="ds-due">বাকি তালিকা</button>
                <button class="ds-btn ds-btn-outline" id="ds-all-tasks">📋 বাকি কাজ</button>
                <button class="ds-btn ds-btn-outline" id="ds-stat" style="grid-column: span 2;">📊 আজকের রিপোর্ট</button>
              </div>
            </div>
            <div class="ds-card">
              <div class="ds-subtitle" style="margin-top:0;">ড্যাশবোর্ড শর্টকাট</div>
              <div style="display:grid;grid-template-columns: repeat(4, 1fr);gap:5px;">
                <a class="ds-btn ds-btn-outline" style="padding:6px 4px;font-size:10.5px;text-decoration:none;" id="ds-la" target="_blank" href="#">Admin</a>
                <a class="ds-btn ds-btn-outline" style="padding:6px 4px;font-size:10.5px;text-decoration:none;" id="ds-lc" target="_blank" href="#">Clients</a>
                <a class="ds-btn ds-btn-outline" style="padding:6px 4px;font-size:10.5px;text-decoration:none;" id="ds-lp" target="_blank" href="#">Products</a>
                <a class="ds-btn ds-btn-outline" style="padding:6px 4px;font-size:10.5px;text-decoration:none;" id="ds-ls" target="_blank" href="#">Settings</a>
              </div>
            </div>
            <div id="ds-tools-output"></div>
          </div>
        </div>`;
      document.body.appendChild(panel);

      // ── Search Toggle & Search Input Handler ─────────────────────────────
      const searchToggleBtn = panel.querySelector('#ds-search-toggle');
      const searchBar = panel.querySelector('#ds-quick-search-bar');
      const searchInput = panel.querySelector('#ds-header-quick-search');
      const searchBtn = panel.querySelector('#ds-header-search-btn');

      searchToggleBtn?.addEventListener('click', () => {
        searchBar?.classList.toggle('ds-hidden');
        if (!searchBar?.classList.contains('ds-hidden')) {
          searchInput?.focus();
        }
      });

      const executeHeaderSearch = async () => {
        const query = (searchInput?.value || '').trim();
        if (!query) { toast('সার্চ করার জন্য ফোন বা নাম দিন'); return; }
        
        // Switch to customer tab
        panel.querySelectorAll('.ds-tab-btn').forEach(b => b.classList.remove('active'));
        panel.querySelectorAll('.ds-tab-content').forEach(c => c.classList.add('ds-hidden'));
        panel.querySelector('[data-tab="customer"]')?.classList.add('active');
        document.getElementById('ds-tab-customer')?.classList.remove('ds-hidden');

        const norm = normalizePhone(query);
        await loadSafe(panel, { phone: norm || query, name: query, raw: query });
      };

      searchBtn?.addEventListener('click', executeHeaderSearch);
      searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          executeHeaderSearch();
        }
      });

      // ── Drag to move ──────────────────────────────────────────────────────
      (function initDrag() {
        const handle = panel.querySelector('#ds-drag-handle');
        let dragging = false, startX = 0, startY = 0, startRight = 0, startTop = 0;

        handle.addEventListener('mousedown', function (e) {
          // Don't drag when clicking buttons or inputs
          if (e.target.closest('button') || e.target.closest('input')) return;
          dragging = true;
          startX = e.clientX;
          startY = e.clientY;
          const rect = panel.getBoundingClientRect();
          startRight = window.innerWidth - rect.right;
          startTop = rect.top;
          panel.classList.add('ds-dragging');
          panel.style.transition = 'none';
          e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
          if (!dragging) return;
          const dx = e.clientX - startX;
          const dy = e.clientY - startY;
          let newRight = startRight - dx;
          let newTop = startTop + dy;
          // Clamp within viewport
          newRight = Math.max(0, Math.min(window.innerWidth - 200, newRight));
          newTop = Math.max(0, Math.min(window.innerHeight - 80, newTop));
          panel.style.right = newRight + 'px';
          panel.style.top = newTop + 'px';
        });

        document.addEventListener('mouseup', function () {
          if (!dragging) return;
          dragging = false;
          panel.classList.remove('ds-dragging');
          panel.style.transition = '';
        });
      })();

      // ── Resize (left edge drag) ───────────────────────────────────────────
      (function initResize() {
        const resizeEl = panel.querySelector('#ds-resize');
        let resizing = false, startX = 0, startW = 0;

        resizeEl.addEventListener('mousedown', function (e) {
          resizing = true;
          startX = e.clientX;
          startW = panel.getBoundingClientRect().width;
          panel.style.transition = 'none';
          e.preventDefault();
          e.stopPropagation();
        });

        document.addEventListener('mousemove', function (e) {
          if (!resizing) return;
          const dx = startX - e.clientX;   // drag left = wider
          const newW = Math.max(260, Math.min(500, startW + dx));
          panel.style.width = newW + 'px';
        });

        document.addEventListener('mouseup', function () {
          if (!resizing) return;
          resizing = false;
          panel.style.transition = '';
        });
      })();

      panel.querySelectorAll('.ds-tab-btn').forEach(btn => {
        btn.onclick = () => {
          panel.querySelectorAll('.ds-tab-btn').forEach(b => {
            b.classList.remove('active');
            b.querySelector('.ds-tab-indicator')?.classList.add('ds-hidden');
          });
          panel.querySelectorAll('.ds-tab-content').forEach(c => c.classList.add('ds-hidden'));
          btn.classList.add('active');
          btn.querySelector('.ds-tab-indicator')?.classList.remove('ds-hidden');
          const target = document.getElementById('ds-tab-' + btn.dataset.tab);
          if (target) target.classList.remove('ds-hidden');
          if (btn.dataset.tab === 'templates') {
            renderTemplatesTab(panel, _currentCustomer);
          }
        };
      });
      panel.querySelector('.ds-close').addEventListener('click', () => panel.classList.toggle('ds-hidden'));
      panel.querySelector('#ds-rfr').addEventListener('click', () => {
        const icon = panel.querySelector('#ds-rfr svg');
        if (icon) {
          icon.style.transform = 'rotate(360deg)';
          icon.style.transition = 'transform 0.5s ease';
          setTimeout(() => { icon.style.transform = 'none'; icon.style.transition = ''; }, 500);
        }
        boot(true);
      });

      getConfig().then(cfg => {
        const b = cfg.baseUrl || DEFAULTS.baseUrl;
        const lk = (id, pg) => { const a = panel.querySelector(id); if (a) a.href = `${b}/index.php?page=${pg}`; };
        lk('#ds-la', 'admin_panel'); lk('#ds-lc', 'customers'); lk('#ds-lp', 'products'); lk('#ds-ls', 'settings');
      }).catch(() => { });

      panel.querySelector('#ds-exp')?.addEventListener('click', () => {
        const expBtn = panel.querySelector('#ds-exp');
        if (expBtn && expBtn.disabled) { toast('Write action এর জন্য user scope select করুন'); return; }
        modal('খরচ যোগ করুন', `
          <div class="ds-field"><label class="ds-label">পরিমাণ</label>
            <input class="ds-input" id="ea" inputmode="decimal" placeholder="500"></div>
          <div class="ds-field"><label class="ds-label">ক্যাটাগরি</label>
            <input class="ds-input" id="ec" placeholder="General"></div>
          <div class="ds-field"><label class="ds-label">নোট</label>
            <textarea class="ds-textarea" id="en" placeholder="ঐচ্ছিক"></textarea></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="ex">বাতিল</button>
            <button class="ds-btn ds-btn-block" type="button" id="es">সেভ</button>
          </div>`,
          (ov, close) => {
            ov.querySelector('#ex')?.addEventListener('click', close);
            ov.querySelector('#es')?.addEventListener('click', async () => {
              const amt = Number(ov.querySelector('#ea')?.value || 0);
              if (!(amt > 0)) { toast('পরিমাণ দিন'); return; }
              const res = await apiPost('ajax_expense_add', {
                amount: amt,
                category: (ov.querySelector('#ec')?.value || 'General').trim() || 'General',
                note: (ov.querySelector('#en')?.value || '').trim()
              });
              if (res.ok) { close(); toast('খরচ যোগ হয়েছে'); } else toast(esc(res.error || 'ব্যর্থ'));
            });
          });
      });

      panel.querySelector('#ds-prc')?.addEventListener('click', () => {
        modal('প্রাইস চেক', `
          <div class="ds-field"><label class="ds-label">নাম বা SKU</label>
            <input class="ds-input" id="pq" placeholder="নাম লিখুন"></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="px">বাতিল</button>
            <button class="ds-btn ds-btn-block" type="button" id="ps2">খুঁজুন</button>
          </div>
          <div id="po" style="margin-top:10px;"></div>`,
          (ov, close) => {
            ov.querySelector('#px')?.addEventListener('click', close);
            ov.querySelector('#ps2')?.addEventListener('click', async () => {
              const q = (ov.querySelector('#pq')?.value || '').trim();
              const out = ov.querySelector('#po');
              if (!q) { toast('নাম লিখুন'); return; }
              if (out) out.innerHTML = '<div class="ds-muted">খুঁজছি...</div>';
              const res = await apiGet('ajax_products_lookup', { q });
              if (out) {
                out.innerHTML = (res.ok && Array.isArray(res.products) && res.products.length)
                  ? `<div class="ds-list">${res.products.map(p =>
                    `<div><b>${esc(p.name)}</b><br>
                      <span class="ds-muted">SKU:${esc(p.sku || '')} • ${fmt(p.price)}</span></div>`
                  ).join('')}</div>`
                  : '<div class="ds-muted">কোনো প্রোডাক্ট পাওয়া যায়নি</div>';
              }
            });
          });
      });

      panel.querySelector('#ds-all-tasks')?.addEventListener('click', async () => {
        const out = document.getElementById('ds-tools-output'); if (!out) return;
        out.innerHTML = '<div class="ds-muted">লোড হচ্ছে...</div>';
        const res = await apiGet('ajax_task_list', { status: 'pending' });
        if (!(res.ok && Array.isArray(res.tasks))) {
          out.innerHTML = '<div class="ds-muted">লোড করা যায়নি</div>'; return;
        }
        if (!res.tasks.length) {
          out.innerHTML = '<div class="ds-muted" style="padding:16px 0;">কোনো বাকি কাজ নেই 🎉</div>'; return;
        }
        out.innerHTML = `
          <div class="ds-subtitle">সব বাকি কাজ (${res.tasks.length})</div>
          <div class="ds-list">
            ${res.tasks.map(t => `
              <div class="ds-list-item" style="flex-direction:column;align-items:stretch;gap:4px;padding:10px 0;">
                <div style="display:flex;justify-content:space-between;">
                  <div>
                    <div style="font-weight:800;font-size:12px;">${esc(t.task_name)}</div>
                    <div class="ds-muted" style="font-size:10px;">${esc(t.customer_name || '')} • ${esc(t.customer_phone || '')}</div>
                    <div class="ds-list-sub">${esc(t.created_at || '')}</div>
                  </div>
                  <div style="font-weight:700;color:var(--ds-accent);font-size:12px;white-space:nowrap;">${fmt(t.amount)}</div>
                </div>
                ${t.notes ? `<div style="font-size:10px;color:var(--ds-text2);background:var(--ds-bg2);
                  padding:6px 8px;border-radius:8px;white-space:pre-wrap;word-break:break-word;
                  max-height:80px;overflow:auto;margin-top:2px;">${esc(t.notes)}</div>` : ''}
              </div>
            `).join('')}
          </div>`;
      });

      panel.querySelector('#ds-due')?.addEventListener('click', async () => {
        const res = await apiGet('ajax_dues_list');
        const out = document.getElementById('ds-tools-output'); if (!out) return;
        out.innerHTML = (res.ok && Array.isArray(res.dues) && res.dues.length)
          ? `<div class="ds-subtitle">বাকি তালিকা (${res.dues.length})</div><div class="ds-list">
              ${res.dues.map(d => `<div><b>${esc(d.name)}</b><br>
                <span class="ds-muted">${esc(d.phone || '')}</span><br>
                <b class="ds-red">বাকি: ${fmt(d.due)}</b></div>`).join('')}
            </div>`
          : '<div class="ds-muted">কোনো বাকি নেই</div>';
      });

      panel.querySelector('#ds-stat')?.addEventListener('click', async () => {
        const res = await apiGet('ajax_daily_stats');
        const out = document.getElementById('ds-tools-output'); if (!out || !res.ok || !res.stats) return;
        const s = res.stats;
        out.innerHTML = `<div class="ds-subtitle">আজকের রিপোর্ট (${esc(res.date || '')})</div>
          <div class="ds-kpis">
            <div><div class="ds-kpi-label">বিক্রয়</div><div class="ds-kpi">${fmt(s.sales)}</div></div>
            <div><div class="ds-kpi-label">কালেকশন</div><div class="ds-kpi ds-green">${fmt(s.payments)}</div></div>
            <div><div class="ds-kpi-label">খরচ</div><div class="ds-kpi ds-red">${fmt(s.expenses)}</div></div>
          </div>
          <div style="margin-top:10px;font-weight:700;text-align:center;">
            ক্যাশ: <span class="${Number(s.net) >= 0 ? 'ds-green' : 'ds-red'}">${fmt(s.net)}</span></div>`;
      });
    }
    if (panel.parentElement !== document.body) document.body.appendChild(panel);
    return panel;
  }

  // ── Chat List Badges (Dues & Pending Tasks) ────────────────────────────────
  async function refreshBadgesCache() {
    try {
      const [duesRes, tasksRes] = await Promise.all([
        apiGet('ajax_dues_list'),
        apiGet('ajax_task_list', { status: 'pending' })
      ]);

      if (duesRes && duesRes.ok && Array.isArray(duesRes.dues)) {
        _duesCache.clear();
        for (const d of duesRes.dues) {
          const dueAmt = Number(d.due || 0);
          if (dueAmt > 0.01) {
            const ph = normalizePhone(d.phone || '');
            if (ph) _duesCache.set(ph, dueAmt);
            const nm = nk(d.name || '');
            if (nm) _duesCache.set(nm, dueAmt);
          }
        }
      }

      if (tasksRes && tasksRes.ok && Array.isArray(tasksRes.tasks)) {
        _tasksCache.clear();
        for (const t of tasksRes.tasks) {
          const ph = normalizePhone(t.customer_phone || '');
          const nm = nk(t.customer_name || '');
          if (ph) _tasksCache.set(ph, (_tasksCache.get(ph) || 0) + 1);
          if (nm) _tasksCache.set(nm, (_tasksCache.get(nm) || 0) + 1);
        }
      }

      applyChatBadges();
    } catch (e) {}
  }

  function applyChatBadges() {
    const pane = document.querySelector('#pane-side');
    if (pane && (_duesCache.size > 0 || _tasksCache.size > 0)) {
      const rows = pane.querySelectorAll('div[role="row"], div[role="listitem"]');
      for (const row of rows) {
        const titleEl = row.querySelector('span[dir="auto"], span[title], div[title]');
        if (!titleEl) continue;
        const titleText = (titleEl.getAttribute('title') || titleEl.textContent || '').trim();
        const ph = looksLikePhone(titleText) ? normalizePhone(titleText) : attrPhone(row, 20);
        const nameKey = nk(titleText);

        const due = (ph && _duesCache.get(ph)) || _duesCache.get(nameKey);
        const taskCount = (ph && _tasksCache.get(ph)) || _tasksCache.get(nameKey) || 0;

        let badge = row.querySelector('.ds-chat-badge-due');
        let taskBadge = row.querySelector('.ds-chat-badge-task');

        if (due && due > 0.01) {
          if (!badge) {
            badge = document.createElement('span');
            badge.className = 'ds-chat-badge ds-chat-badge-due';
            const targetParent = titleEl.parentElement;
            if (targetParent) targetParent.appendChild(badge);
            else row.appendChild(badge);
          }
          badge.textContent = `Due: ${fmt(due)}`;
        } else if (badge) {
          badge.remove();
        }

        if (taskCount && taskCount > 0) {
          if (!taskBadge) {
            taskBadge = document.createElement('span');
            taskBadge.className = 'ds-chat-badge ds-chat-badge-task';
            const targetParent = titleEl.parentElement;
            if (targetParent) targetParent.appendChild(taskBadge);
            else row.appendChild(taskBadge);
          }
          taskBadge.textContent = `📋 ${taskCount}টি কাজ`;
        } else if (taskBadge) {
          taskBadge.remove();
        }
      }
    }

    // Active Chat Header (#main header) Due & Pending Task Badges
    try {
      const mainHeader = document.querySelector('#main header');
      if (mainHeader) {
        let hBadge = mainHeader.querySelector('.ds-header-chat-badge');
        let hTaskBadge = mainHeader.querySelector('.ds-header-chat-badge-task');
        const headerTitle = mainHeader.querySelector('span[dir="auto"][title], div[role="button"] span[title], span[dir="auto"]');
        if (headerTitle) {
          const ht = (headerTitle.getAttribute('title') || headerTitle.textContent || '').trim();
          const hph = looksLikePhone(ht) ? normalizePhone(ht) : '';
          const nameK = nk(ht);

          let hDue = 0;
          let hTask = 0;

          if (hph && _duesCache.has(hph)) {
            hDue = _duesCache.get(hph);
          } else if (nameK && _duesCache.has(nameK)) {
            hDue = _duesCache.get(nameK);
          } else if (_currentCustomer && ((_currentCustomer.phone && normalizePhone(_currentCustomer.phone) === hph) || (_currentCustomer.name && nk(_currentCustomer.name) === nameK))) {
            hDue = Number(_currentCustomer.due || 0);
          }

          if (hph && _tasksCache.has(hph)) {
            hTask = _tasksCache.get(hph);
          } else if (nameK && _tasksCache.has(nameK)) {
            hTask = _tasksCache.get(nameK);
          } else if (_currentCustomer && ((_currentCustomer.phone && normalizePhone(_currentCustomer.phone) === hph) || (_currentCustomer.name && nk(_currentCustomer.name) === nameK))) {
            hTask = Number(_currentCustomer.taskCount || 0);
          }

          if (hDue && hDue > 0.01) {
            if (!hBadge) {
              hBadge = document.createElement('span');
              hBadge.className = 'ds-header-chat-badge';
              headerTitle.parentElement?.appendChild(hBadge);
            }
            hBadge.textContent = `Due: ${fmt(hDue)}`;
          } else if (hBadge) {
            hBadge.remove();
          }

          if (hTask && hTask > 0) {
            if (!hTaskBadge) {
              hTaskBadge = document.createElement('span');
              hTaskBadge.className = 'ds-header-chat-badge-task';
              headerTitle.parentElement?.appendChild(hTaskBadge);
              hTaskBadge.addEventListener('click', () => {
                const el = document.querySelector('.ds-pending-tasks-section');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
              });
            }
            hTaskBadge.textContent = `📋 ${hTask}টি পেন্ডিং কাজ`;
          } else if (hTaskBadge) {
            hTaskBadge.remove();
          }
        } else {
          if (hBadge) hBadge.remove();
          if (hTaskBadge) hTaskBadge.remove();
        }
      }
    } catch (e) {}
  }

  // ── Application ID Enforcement & Reminder ─────────────────────────────────
  let _bypassSendAppId = null;
  let _interceptorBound = false;

  function promptMandatoryTaskAdd(parsed, onDone) {
    modal('⚠️ বাধ্যতামূলক কাজ এন্ট্রি', `
      <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.4);padding:10px 12px;border-radius:10px;margin-bottom:12px;">
        <div style="color:#ef4444;font-weight:800;font-size:12px;display:flex;align-items:center;gap:6px;">
          🚨 সতর্কতা: এই আবেদনটি সিস্টেমে যোগ করা হয়নি!
        </div>
        <div style="font-size:11.5px;color:var(--text);margin-top:4px;">
          মেসেজ পাঠানোর আগে কাজটি <b>ডিজিটাল স্টোরে</b> যোগ করে নিন, যেন হিসাব ভুলে যাওয়ার ঝুঁকি না থাকে।
        </div>
        <div style="margin-top:6px;font-size:11px;color:var(--text2);">
          <b>Application ID:</b> ${esc(parsed.appId)} • <b>নাম:</b> ${esc(parsed.name || '—')}
        </div>
      </div>
      <div class="ds-modal-actions" style="flex-direction:column;gap:8px;">
        <button class="ds-btn ds-btn-primary ds-btn-block" id="pma-add" type="button" style="font-size:12.5px;padding:9px;">
          ⚡ এখনই কাজ যোগ করুন ও মেসেজ পাঠান
        </button>
        <button class="ds-btn ds-btn-secondary ds-btn-block" id="pma-skip" type="button" style="font-size:11px;opacity:0.8;">
          এন্ট্রি ছাড়াই মেসেজ পাঠিয়ে দিন
        </button>
      </div>`,
      (ov, close) => {
        ov.querySelector('#pma-skip').onclick = () => {
          close();
          if (onDone) onDone();
        };
        ov.querySelector('#pma-add').onclick = async () => {
          close();
          const panel = ensurePanel();
          const identity = await getIdentity();
          openTaskAddModal(panel, _currentCustomer || { id: 0, name: parsed.name }, identity, '', true, parsed, () => {
            _appIdCache.set(parsed.appId, { ok: true, exists: true });
            document.getElementById('ds-app-floating-pill')?.remove();
            if (onDone) onDone();
          });
        };
      }
    );
  }

  async function updateChatFloatingReminder() {
    const footer = document.querySelector('#main footer');
    if (!footer) {
      document.getElementById('ds-app-floating-pill')?.remove();
      return;
    }

    const detected = detectChatApplication();
    let pill = document.getElementById('ds-app-floating-pill');

    if (!detected || !detected.appId) {
      if (pill) pill.remove();
      return;
    }

    const st = await checkApplicationStatus(detected.appId);
    if (st && st.exists) {
      if (pill) pill.remove();
      return;
    }

    if (!pill) {
      pill = document.createElement('div');
      pill.id = 'ds-app-floating-pill';
      pill.style.cssText = `
        position: absolute;
        top: -44px;
        left: 20px;
        right: 20px;
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: #ffffff;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 11.5px;
        font-weight: 700;
        padding: 6px 14px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        box-shadow: 0 4px 14px rgba(239, 68, 68, 0.35);
        z-index: 99;
      `;
      footer.style.position = 'relative';
      footer.appendChild(pill);
    }

    pill.innerHTML = `
      <div style="display:flex;align-items:center;gap:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
        <span>🚨 নতুন আবেদন এন্ট্রি করা হয়নি: <b>ID ${esc(detected.appId)}</b> (${esc(detected.name || 'New Birth')})</span>
      </div>
      <button type="button" id="ds-pill-add-btn" style="
        background: #ffffff;
        color: #dc2626;
        border: none;
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 800;
        font-size: 11px;
        cursor: pointer;
        white-space: nowrap;
      ">⚡ কাজ যোগ করুন</button>
    `;

    pill.querySelector('#ds-pill-add-btn')?.addEventListener('click', async () => {
      const panel = ensurePanel();
      const identity = await getIdentity();
      openTaskAddModal(panel, _currentCustomer || { id: 0, name: detected.name }, identity, '', true, detected, () => {
        _appIdCache.set(detected.appId, { ok: true, exists: true });
        pill?.remove();
      });
    });
  }

  function initComposerInterceptor() {
    if (_interceptorBound) return;
    _interceptorBound = true;

    document.addEventListener('keydown', async (e) => {
      if (e.key !== 'Enter' || e.shiftKey) return;
      const composer = e.target?.closest?.('#main footer [contenteditable="true"]');
      if (!composer) return;

      const text = (composer.innerText || composer.textContent || '').trim();
      const parsed = parseApplicationText(text);
      if (!parsed || !parsed.appId) return;

      if (_bypassSendAppId === parsed.appId) {
        _bypassSendAppId = null;
        return;
      }

      const status = await checkApplicationStatus(parsed.appId);
      if (status && status.exists) {
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();

      promptMandatoryTaskAdd(parsed, () => {
        _bypassSendAppId = parsed.appId;
        setTimeout(() => {
          const sendBtn =
            document.querySelector('button span[data-icon="send"]')?.closest('button') ||
            document.querySelector('[data-testid="compose-btn-send"]') ||
            document.querySelector('[data-testid="send"]') ||
            document.querySelector('#main footer button[aria-label*="Send"]');
          if (sendBtn) sendBtn.click();
        }, 150);
      });
    }, true);

    document.addEventListener('click', async (e) => {
      const sendBtn = e.target?.closest?.(
        'button[data-testid="compose-btn-send"], button[data-testid="send"], #main footer button[aria-label*="Send"], button:has(span[data-icon="send"])'
      );
      if (!sendBtn) return;
      const composer = document.querySelector('#main footer [contenteditable="true"]');
      if (!composer) return;

      const text = (composer.innerText || composer.textContent || '').trim();
      const parsed = parseApplicationText(text);
      if (!parsed || !parsed.appId) return;

      if (_bypassSendAppId === parsed.appId) {
        _bypassSendAppId = null;
        return;
      }

      const status = await checkApplicationStatus(parsed.appId);
      if (status && status.exists) {
        return;
      }

      e.preventDefault();
      e.stopImmediatePropagation();

      promptMandatoryTaskAdd(parsed, () => {
        _bypassSendAppId = parsed.appId;
        setTimeout(() => sendBtn.click(), 150);
      });
    }, true);
  }

  // ── Boot ───────────────────────────────────────────────────────────────────
  async function boot(forceRefresh = false) {
    const panel = ensurePanel();
    panel.classList.remove('ds-hidden');
    initComposerInterceptor();
    await refreshToolsOverview(panel, forceRefresh);
    refreshBadgesCache();
    setInterval(refreshBadgesCache, 60000);

    const id = await getIdentity();
    const key = id ? id.key : '__none__';
    const chatMeta = getSelectedChatMeta();
    const chatSignal = `${key}|${chatMeta.unreadCount}|${chatMeta.muted ? 1 : 0}`;

    if (id && (forceRefresh || _lastKey !== key || _lastChatSignal !== chatSignal)) {
      _lastKey = key;
      _lastChatSignal = chatSignal;
      panel.querySelector('#ds-customer').innerHTML = '<div class="ds-loading"><div class="ds-spinner"></div>লোড হচ্ছে...</div>';
      panel.querySelector('#ds-actions').innerHTML = '';
      panel.querySelector('#ds-recent').innerHTML = '';
      await loadSafe(panel, id);
    } else if (!id && (_lastKey !== '__none__' || _lastChatSignal !== chatSignal)) {
      _lastKey = '__none__';
      _lastChatSignal = chatSignal;
      panel.querySelector('#ds-customer').innerHTML = '<div class="ds-empty"><div class="ds-empty-icon">💬</div>একটি চ্যাট ওপেন করুন</div>';
      panel.querySelector('#ds-actions').innerHTML = '';
      panel.querySelector('#ds-recent').innerHTML = '';
    }

    if (booted) return;
    booted = true;

    const check = async () => {
      applyChatBadges();
      updateChatFloatingReminder();
      const id2 = await getIdentity();
      const k2 = id2 ? id2.key : '__none__';
      const chatMeta2 = getSelectedChatMeta();
      const chatSignal2 = `${k2}|${chatMeta2.unreadCount}|${chatMeta2.muted ? 1 : 0}`;
      if (_lastKey === k2 && _lastChatSignal === chatSignal2) return;
      _lastKey = k2;
      _lastChatSignal = chatSignal2;
      panel.querySelector('#ds-customer').innerHTML =
        id2 ? '<div class="ds-loading"><div class="ds-spinner"></div>লোড হচ্ছে...</div>'
          : '<div class="ds-empty"><div class="ds-empty-icon">💬</div>একটি চ্যাট ওপেন করুন</div>';
      panel.querySelector('#ds-actions').innerHTML = '';
      panel.querySelector('#ds-recent').innerHTML = '';
      if (id2) { await loadSafe(panel, id2); panel.classList.remove('ds-hidden'); }
    };

    const debounced = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(check, 350); };

    observer = new MutationObserver(debounced);
    const targets = [document.querySelector('#main'), document.querySelector('#pane-side')].filter(Boolean);
    if (targets.length) {
      targets.forEach(t => observer.observe(t, {
        subtree: true, childList: true, attributes: true,
        attributeFilter: ['aria-selected', 'data-id', 'data-jid', 'aria-label', 'title']
      }));
    } else {
      observer.observe(document.body, {
        subtree: true, childList: true, attributes: true,
        attributeFilter: ['aria-selected', 'data-id', 'data-jid', 'class', 'aria-label', 'title']
      });
    }
    pollTimer = setInterval(check, 1500);
  }

  // Global Keyboard Shortcut: Alt+D toggles panel
  window.addEventListener('keydown', (e) => {
    if (e.altKey && (e.key === 'd' || e.key === 'D' || e.code === 'KeyD')) {
      e.preventDefault();
      const panel = document.getElementById('ds-wa-panel');
      if (panel) {
        panel.classList.toggle('ds-hidden');
        if (!panel.classList.contains('ds-hidden')) boot(true);
      } else {
        boot(true);
      }
    }
  });

  document.addEventListener('ds-wa-boot', () => boot(true));
  if (document.readyState === 'complete' || document.readyState === 'interactive') boot(false);
  else window.addEventListener('DOMContentLoaded', () => boot(false));
})();