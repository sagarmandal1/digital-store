(() => {
  'use strict';

  const DEFAULTS = { baseUrl: 'https://digital-store.top', token: '', scopeAdminId: '' };

  let booted = false, observer = null, pollTimer = null;
  let debounceTimer = null, loadRunning = false, loadQueued = null;
  let _lastKey = '';

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

  const fmt = n => Number(n || 0).toFixed(2);

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
    cEl.innerHTML = `
      <div class="ds-empty">
        <div class="ds-empty-icon">👤</div>
        <b style="color:var(--ds-text2);display:block;margin-bottom:4px;">${esc(name || ph || 'Unknown')}</b>
        এই কাস্টমারটি ডাটাবেসে নেই
      </div>`;
    aEl.innerHTML = `<button class="ds-btn ds-btn-primary ds-btn-block" id="ds-add-manual">+ কাস্টমার যোগ করুন</button>`;
    aEl.querySelector('#ds-add-manual').onclick = () =>
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
            <button class="ds-btn ds-btn-block" type="button" id="nc2">সেভ</button>
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
    const sales = Array.isArray(lookup.recent_sales) ? lookup.recent_sales : [];
    const evts = (ledger?.ok && Array.isArray(ledger.events)) ? ledger.events : [];

    cEl.innerHTML = `
      ${id?.nameMatch ? '<div class="ds-warn-banner">⚠️ ফোন ডিটেক্ট হয়নি — নাম দিয়ে মিলেছে</div>' : ''}
      <div class="ds-customer-name">
        ${esc(c.name)}
        <span class="ds-badge ${c.category === 'VIP' ? 'ds-badge-vip' : ''}">${esc(c.category || 'Regular')}</span>
      </div>
      <div class="ds-info-row">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;min-width:13px;flex-shrink:0;opacity:.5"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.1 1.18 2 2 0 012.11 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 7.09a16 16 0 006 6l.46-.46a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
        ${esc(c.phone || '—')}
      </div>
      ${c.email ? `<div class="ds-info-row">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;min-width:13px;flex-shrink:0;opacity:.5"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 7l-10 7L2 7"/></svg>
        ${esc(c.email)}
      </div>`: ''}
      ${c.address ? `<div class="ds-info-row">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;min-width:13px;flex-shrink:0;opacity:.5"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
        ${esc(c.address)}
      </div>`: ''}
      <div class="ds-kpis">
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">বিক্রয়</div>
          <div class="ds-kpi-value">${fmt(s.total_sell)}</div>
        </div>
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">পরিশোধ</div>
          <div class="ds-kpi-value green">${fmt(s.total_paid)}</div>
        </div>
        <div class="ds-kpi-box">
          <div class="ds-kpi-label">বাকি</div>
          <div class="ds-kpi-value ${Number(s.total_due) > 0.01 ? 'red' : 'green'}">${fmt(s.total_due)}</div>
        </div>
      </div>`;

    const cfg = await getConfig();
    aEl.innerHTML = `
      <div class="ds-actionbar">
        <div class="ds-menu-wrap">
          <button class="ds-btn ds-btn-secondary ds-menu-btn" id="ds-ambt" type="button">অ্যাকশন <span>▾</span></button>
          <div class="ds-menu ds-hidden" id="ds-amenu">
            <button class="ds-menu-item" id="ds-sale"  type="button">নতুন সেল</button>
            <button class="ds-menu-item" id="ds-pay"   type="button">পেমেন্ট</button>
            <button class="ds-menu-item" id="ds-note"  type="button">নোট যোগ</button>
            <button class="ds-menu-item" id="ds-upd"   type="button">কাস্টমার আপডেট</button>
            <button class="ds-menu-item" id="ds-ssum"  type="button">সামারি পাঠান</button>
            <button class="ds-menu-item" id="ds-sdue"  type="button">বকেয়া রিমাইন্ডার</button>
            <button class="ds-menu-item" id="ds-sinv"  type="button">ইনভয়েস পাঠান</button>
          </div>
        </div>
        <a class="ds-link" target="_blank"
           href="${esc(cfg.baseUrl)}/index.php?page=customer_ledger&id=${encodeURIComponent(c.id)}">লেজার</a>
      </div>
      ${id?.nameMatch ? '<button class="ds-btn ds-btn-secondary ds-btn-block" id="ds-bindph" type="button">এই চ্যাটে ফোন লিংক করুন</button>' : ''}`;

    const menu = aEl.querySelector('#ds-amenu');
    const closeMenu = () => menu.classList.add('ds-hidden');
    aEl.querySelector('#ds-ambt').onclick = e => { e.stopPropagation(); menu.classList.toggle('ds-hidden'); };
    document.addEventListener('click', closeMenu, { once: true });
    menu.addEventListener('click', e => e.stopPropagation());

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

    // ── Sale ─────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-sale').onclick = () => {
      closeMenu();
      let sel = { id: null, name: 'WhatsApp Quick Sale', price: 0 };
      modal('নতুন সেল', `
        <div class="ds-field"><label class="ds-label">প্রোডাক্ট (ঐচ্ছিক)</label>
          <input class="ds-input" id="sq" placeholder="নাম বা SKU">
          <div class="ds-suggest" id="ss"></div></div>
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">মোট পরিমাণ</label>
            <input class="ds-input" id="sa" inputmode="decimal" placeholder="1000"></div>
          <div class="ds-field"><label class="ds-label">Qty</label>
            <input class="ds-input" id="sqt" inputmode="numeric" value="1"></div>
          <div class="ds-field"><label class="ds-label">পেমেন্ট</label>
            <input class="ds-input" id="sp" inputmode="decimal" value="0"></div>
        </div>
        <div class="ds-field"><label class="ds-label">নোট</label>
          <textarea class="ds-textarea" id="sn" placeholder="ঐচ্ছিক"></textarea></div>
        <div class="ds-field">
          <label class="ds-checkbox-label" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="si" style="width:16px;height:16px;margin:0;" checked>
            <span>ইনভয়েস পাঠান</span></label></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="sc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="sv">সেভ</button>
        </div>`,
        (ov, close) => {
          const qEl = ov.querySelector('#sq'), sgEl = ov.querySelector('#ss');
          const aEl2 = ov.querySelector('#sa'), qEl2 = ov.querySelector('#sqt');
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
                sel = {
                  id: Number(decodeURIComponent(it.dataset.id || '')) || null,
                  name: it.dataset.name || 'WhatsApp Quick Sale', price: Number(it.dataset.price || 0)
                };
                const q2 = Math.max(1, Math.min(999, Number(qEl2.value) || 1));
                qEl2.value = String(q2); aEl2.value = String(sel.price * q2);
                sgEl.innerHTML = ''; qEl.value = sel.name;
              });
            });
          };
          qEl.addEventListener('input', () => { clearTimeout(t); t = setTimeout(search, 250); });
          qEl2.addEventListener('input', () => {
            const q2 = Math.max(1, Math.min(999, Number(qEl2.value) || 1));
            qEl2.value = String(q2);
            if (sel.id) aEl2.value = String(sel.price * q2);
          });
          ov.querySelector('#sc').onclick = close;
          ov.querySelector('#sv').onclick = async () => {
            const amt = Number(aEl2.value || 0);
            if (!(amt > 0)) { toast('পরিমাণ দিন'); return; }
            const qty = Math.max(1, Math.min(999, Number(qEl2.value) || 1));
            const res = await apiPost('ajax_quick_sale', {
              customer_id: c.id, amount: amt, qty,
              payment_amount: Number(ov.querySelector('#sp').value || 0),
              notes: (ov.querySelector('#sn').value || '').trim(),
              product_id: sel.id, product_name: sel.name || 'WhatsApp Quick Sale'
            });
            if (res.ok) {
              close();
              if (ov.querySelector('#si').checked && res.sale_id) {
                await apiPost('ajax_send_invoice', { sale_id: res.sale_id });
                toast('সেল ও ইনভয়েস সম্পন্ন');
              } else toast('সেল যোগ হয়েছে');
              await loadSafe(panel, id);
            } else toast(esc(res.error || 'ব্যর্থ'));
          };
        });
    };

    // ── Payment ───────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-pay').onclick = () => {
      closeMenu();
      modal('পেমেন্ট', `
        <div class="ds-row">
          <div class="ds-field"><label class="ds-label">পরিমাণ</label>
            <input class="ds-input" id="pa" inputmode="decimal" placeholder="500"></div>
          <div class="ds-field"><label class="ds-label">পদ্ধতি</label>
            <select class="ds-select" id="pm"><option>Cash</option><option>bKash</option>
              <option>Nagad</option><option>Bank</option><option>Other</option></select></div>
        </div>
        <div class="ds-field"><label class="ds-label">নোট</label>
          <textarea class="ds-textarea" id="pn" placeholder="ঐচ্ছিক"></textarea></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="pc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="ps">সেভ</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#pc').onclick = close;
          ov.querySelector('#ps').onclick = async () => {
            const amt = Number(ov.querySelector('#pa').value || 0);
            if (!(amt > 0)) { toast('পরিমাণ দিন'); return; }
            const res = await apiPost('ajax_quick_payment', {
              customer_id: c.id, amount: amt,
              method: ov.querySelector('#pm').value || 'Cash',
              note: (ov.querySelector('#pn').value || '').trim()
            });
            if (res.ok) { close(); await loadSafe(panel, id); toast('পেমেন্ট যোগ হয়েছে'); }
            else toast(esc(res.error || 'ব্যর্থ'));
          };
        });
    };

    // ── Note ──────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-note').onclick = () => {
      closeMenu();
      modal('নোট যোগ', `
        <div class="ds-field"><label class="ds-label">নোট</label>
          <textarea class="ds-textarea" id="nt" placeholder="নোট লিখুন"></textarea></div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="nc">বাতিল</button>
          <button class="ds-btn ds-btn-block" type="button" id="ns">সেভ</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#nc').onclick = close;
          ov.querySelector('#ns').onclick = async () => {
            const note = (ov.querySelector('#nt').value || '').trim();
            if (!note) { toast('নোট লিখুন'); return; }
            const res = await apiPost('ajax_customer_note_add', { customer_id: c.id, note });
            if (res.ok) { close(); await loadSafe(panel, id); toast('নোট যোগ হয়েছে'); }
            else toast(esc(res.error || 'ব্যর্থ'));
          };
        });
    };

    // ── Update ────────────────────────────────────────────────────────────────
    aEl.querySelector('#ds-upd').onclick = () => {
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
          ov.querySelector('#ux').onclick = close;
          ov.querySelector('#us').onclick = async () => {
            const res = await apiPost('ajax_customer_update', {
              id: c.id,
              name: (ov.querySelector('#un').value || '').trim(),
              phone: (ov.querySelector('#up').value || '').trim(),
              email: (ov.querySelector('#ue').value || '').trim(),
              address: (ov.querySelector('#ua').value || '').trim(),
              category: ov.querySelector('#uc').value || 'Regular'
            });
            if (res.ok) { close(); await loadSafe(panel, id); toast('আপডেট হয়েছে'); }
            else toast(esc(res.error || 'ব্যর্থ'));
          };
        });
    };

    aEl.querySelector('#ds-ssum').onclick = async () => {
      closeMenu();
      const r = await apiPost('ajax_send_notification', { customer_id: c.id, type: 'summary' });
      r.ok ? toast('সামারি পাঠানো হয়েছে') : apiError('সামারি পাঠাতে ব্যর্থ', r);
    };
    aEl.querySelector('#ds-sdue').onclick = async () => {
      closeMenu();
      const r = await apiPost('ajax_send_notification', { customer_id: c.id, type: 'due_reminder' });
      r.ok ? toast('রিমাইন্ডার পাঠানো হয়েছে') : apiError('রিমাইন্ডার পাঠাতে ব্যর্থ', r);
    };
    aEl.querySelector('#ds-sinv').onclick = () => {
      closeMenu();
      if (!sales.length) { toast('কোনো ইনভয়েস নেই'); return; }
      modal('ইনভয়েস পাঠান', `
        <div class="ds-suggest">
          ${sales.slice(0, 6).map(r => {
        const due = Math.max(0, Number(r.total_sell || 0) - Number(r.paid_amount || 0));
        return `<button type="button" class="ds-suggest-item" data-sid="${encodeURIComponent(r.id)}">
              <div style="font-weight:900;">${esc(r.invoice_no || '')}</div>
              <div class="ds-muted" style="text-align:left;padding:0;">তারিখ: ${esc(r.sale_date || '')}</div>
              <div class="ds-muted" style="text-align:left;padding:0;">
                মোট:${fmt(r.total_sell)} • পরিশোধ:${fmt(r.paid_amount)} • বাকি:${fmt(due)}</div>
            </button>`;
      }).join('')}
        </div>
        <div class="ds-modal-actions">
          <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="ic">বাতিল</button>
        </div>`,
        (ov, close) => {
          ov.querySelector('#ic').onclick = close;
          ov.querySelectorAll('[data-sid]').forEach(btn => {
            btn.addEventListener('click', async () => {
              const sid = Number(decodeURIComponent(btn.dataset.sid || ''));
              if (!sid) return;
              const res = await apiPost('ajax_send_invoice', { sale_id: sid });
              res.ok ? (close(), toast('ইনভয়েস পাঠানো হয়েছে')) : apiError('ইনভয়েস পাঠাতে ব্যর্থ', res);
            });
          });
        });
    };
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
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#071a10" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;display:block;flex-shrink:0;">
                <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>
              </svg>
            </div>
            <div>
              <div class="ds-title">Digital Store</div>
              <div class="ds-title-sub">CRM Panel</div>
            </div>
          </div>
          <div class="ds-header-actions">
            <button class="ds-refresh-btn" id="ds-rfr" title="রিফ্রেশ">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;display:block;"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
            </button>
            <button class="ds-close" title="হাইড">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;display:block;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>
        <div class="ds-tabs">
          <button class="ds-tab-btn active" data-tab="customer">কাস্টমার</button>
          <button class="ds-tab-btn" data-tab="tools">টুলস</button>
        </div>
        <div class="ds-body">
          <div class="ds-tab-content" id="ds-tab-customer">
            <div id="ds-customer">
              <div class="ds-empty"><span class="ds-empty-icon">💬</span>একটি চ্যাট সিলেক্ট করুন</div>
            </div>
            <div id="ds-actions"></div>
            <div id="ds-recent"></div>
          </div>
          <div class="ds-tab-content ds-hidden" id="ds-tab-tools">
            <div class="ds-card">
              <div class="ds-subtitle" style="margin-top:0;">কুইক অ্যাকশন</div>
              <div class="ds-tools-grid">
                <button class="ds-btn ds-btn-primary" id="ds-exp">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:11px;height:11px;display:block;flex-shrink:0;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>খরচ যোগ
                </button>
                <button class="ds-btn ds-btn-outline" id="ds-prc">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="width:11px;height:11px;display:block;flex-shrink:0;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>প্রাইস চেক
                </button>
                <button class="ds-btn ds-btn-outline" id="ds-due">বাকি তালিকা</button>
                <button class="ds-btn ds-btn-outline" id="ds-stat">আজকের রিপোর্ট</button>
              </div>
            </div>
            <div class="ds-card">
              <div class="ds-subtitle" style="margin-top:0;">শর্টকাট</div>
              <div style="display:flex;flex-wrap:wrap;gap:5px;">
                <a class="ds-btn ds-btn-outline" style="width:auto;padding:5px 9px;text-decoration:none;" id="ds-lc" target="_blank" href="#">Customers</a>
                <a class="ds-btn ds-btn-outline" style="width:auto;padding:5px 9px;text-decoration:none;" id="ds-lp" target="_blank" href="#">Products</a>
                <a class="ds-btn ds-btn-outline" style="width:auto;padding:5px 9px;text-decoration:none;" id="ds-ls" target="_blank" href="#">Settings</a>
              </div>
            </div>
            <div id="ds-tools-output"></div>
          </div>
        </div>`;
      document.body.appendChild(panel);

      // ── Drag to move ──────────────────────────────────────────────────────
      (function initDrag() {
        const handle = panel.querySelector('#ds-drag-handle');
        let dragging = false, startX = 0, startY = 0, startRight = 0, startTop = 0;

        handle.addEventListener('mousedown', function (e) {
          // Don't drag when clicking buttons
          if (e.target.closest('button')) return;
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
          const newW = Math.max(240, Math.min(480, startW + dx));
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
          panel.querySelectorAll('.ds-tab-btn').forEach(b => b.classList.remove('active'));
          panel.querySelectorAll('.ds-tab-content').forEach(c => c.classList.add('ds-hidden'));
          btn.classList.add('active');
          document.getElementById('ds-tab-' + btn.dataset.tab)?.classList.remove('ds-hidden');
        };
      });
      panel.querySelector('.ds-close').addEventListener('click', () => panel.classList.toggle('ds-hidden'));
      panel.querySelector('#ds-rfr').addEventListener('click', () => boot(true));

      getConfig().then(cfg => {
        const b = cfg.baseUrl || DEFAULTS.baseUrl;
        const lk = (id, pg) => { const a = panel.querySelector(id); if (a) a.href = `${b}/index.php?page=${pg}`; };
        lk('#ds-lc', 'customers'); lk('#ds-lp', 'products'); lk('#ds-ls', 'settings');
      }).catch(() => { });

      panel.querySelector('#ds-exp').onclick = () => {
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
            ov.querySelector('#ex').onclick = close;
            ov.querySelector('#es').onclick = async () => {
              const amt = Number(ov.querySelector('#ea').value || 0);
              if (!(amt > 0)) { toast('পরিমাণ দিন'); return; }
              const res = await apiPost('ajax_expense_add', {
                amount: amt,
                category: (ov.querySelector('#ec').value || 'General').trim() || 'General',
                note: (ov.querySelector('#en').value || '').trim()
              });
              if (res.ok) { close(); toast('খরচ যোগ হয়েছে'); } else toast(esc(res.error || 'ব্যর্থ'));
            };
          });
      };

      panel.querySelector('#ds-prc').onclick = () => {
        modal('প্রাইস চেক', `
          <div class="ds-field"><label class="ds-label">নাম বা SKU</label>
            <input class="ds-input" id="pq" placeholder="নাম লিখুন"></div>
          <div class="ds-modal-actions">
            <button class="ds-btn ds-btn-secondary ds-btn-block" type="button" id="px">বাতিল</button>
            <button class="ds-btn ds-btn-block" type="button" id="ps2">খুঁজুন</button>
          </div>
          <div id="po" style="margin-top:10px;"></div>`,
          (ov, close) => {
            ov.querySelector('#px').onclick = close;
            ov.querySelector('#ps2').onclick = async () => {
              const q = (ov.querySelector('#pq').value || '').trim();
              const out = ov.querySelector('#po');
              if (!q) { toast('নাম লিখুন'); return; }
              out.innerHTML = '<div class="ds-muted">খুঁজছি...</div>';
              const res = await apiGet('ajax_products_lookup', { q });
              out.innerHTML = (res.ok && Array.isArray(res.products) && res.products.length)
                ? `<div class="ds-list">${res.products.map(p =>
                  `<div><b>${esc(p.name)}</b><br>
                    <span class="ds-muted">SKU:${esc(p.sku || '')} • ${fmt(p.price)}</span></div>`
                ).join('')}</div>`
                : '<div class="ds-muted">কোনো প্রোডাক্ট পাওয়া যায়নি</div>';
            };
          });
      };

      panel.querySelector('#ds-due').onclick = async () => {
        const res = await apiGet('ajax_dues_list');
        const out = document.getElementById('ds-tools-output'); if (!out) return;
        out.innerHTML = (res.ok && Array.isArray(res.dues) && res.dues.length)
          ? `<div class="ds-subtitle">বাকি তালিকা (Top 20)</div><div class="ds-list">
              ${res.dues.map(d => `<div><b>${esc(d.name)}</b><br>
                <span class="ds-muted">${esc(d.phone || '')}</span><br>
                <b class="ds-red">বাকি: ${fmt(d.due)}</b></div>`).join('')}
            </div>`
          : '<div class="ds-muted">কোনো বাকি নেই</div>';
      };

      panel.querySelector('#ds-stat').onclick = async () => {
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
      };
    }
    if (panel.parentElement !== document.body) document.body.appendChild(panel);
    return panel;
  }

  // ── Boot ───────────────────────────────────────────────────────────────────
  async function boot(forceRefresh = false) {
    const panel = ensurePanel();
    panel.classList.remove('ds-hidden');

    const id = await getIdentity();
    const key = id ? id.key : '__none__';

    if (id && (forceRefresh || _lastKey !== key)) {
      _lastKey = key;
      panel.querySelector('#ds-customer').innerHTML = '<div class="ds-loading"><div class="ds-spinner"></div>লোড হচ্ছে...</div>';
      panel.querySelector('#ds-actions').innerHTML = '';
      panel.querySelector('#ds-recent').innerHTML = '';
      await loadSafe(panel, id);
    } else if (!id && _lastKey !== '__none__') {
      _lastKey = '__none__';
      panel.querySelector('#ds-customer').innerHTML = '<div class="ds-empty"><div class="ds-empty-icon">💬</div>একটি চ্যাট ওপেন করুন</div>';
      panel.querySelector('#ds-actions').innerHTML = '';
      panel.querySelector('#ds-recent').innerHTML = '';
    }

    if (booted) return;
    booted = true;

    const check = async () => {
      const id2 = await getIdentity();
      const k2 = id2 ? id2.key : '__none__';
      if (_lastKey === k2) return;
      _lastKey = k2;
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
        attributeFilter: ['aria-selected', 'data-id', 'data-jid']
      }));
    } else {
      observer.observe(document.body, {
        subtree: true, childList: true, attributes: true,
        attributeFilter: ['aria-selected', 'data-id', 'data-jid', 'class']
      });
    }
    pollTimer = setInterval(check, 1500);
  }

  document.addEventListener('ds-wa-boot', () => boot(true));
  if (document.readyState === 'complete' || document.readyState === 'interactive') boot(false);
  else window.addEventListener('DOMContentLoaded', () => boot(false));
})();