async function withActiveTab(fn) {
  const tabs = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tabs.length) return null;
  const tab = tabs[0];
  return fn(tab);
}

const DEFAULTS = {
  baseUrl: 'https://digital-store.top',
  token: '',
  scopeAdminId: ''
};

function setStatus(msg, type = 'neutral') {
  const el = document.getElementById('status');
  if (!el) return;
  el.textContent = msg;
  el.dataset.state = type;
}

function setConnectionState(title, meta, type = 'neutral') {
  const titleEl = document.getElementById('connectionState');
  const metaEl = document.getElementById('connectionMeta');
  const dotEl = document.getElementById('statusDot');
  if (!titleEl || !metaEl) return;
  titleEl.textContent = title;
  metaEl.textContent = meta;
  titleEl.dataset.state = type;
  if (dotEl) {
    dotEl.className = 'status-indicator' + (type === 'success' ? ' success' : type === 'error' ? ' error' : '');
  }
}

function renderProfile(profile) {
  const card = document.getElementById('profileCard');
  if (!card) return;
  if (!profile || !profile.user) {
    card.classList.add('ds-hidden');
    return;
  }
  const name = profile.user.name || profile.user.email || 'Connected User';
  const initials = name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() || 'DS';
  const avatarEl = document.getElementById('profileAvatar');
  if (avatarEl) avatarEl.textContent = initials;

  document.getElementById('profileName').textContent = name;
  document.getElementById('profileScope').textContent = `Scope: ${profile.scope?.name || 'Full Scope'}${profile.user.email ? ` • ${profile.user.email}` : ''}`;
  card.classList.remove('ds-hidden');
}

function normalizeBaseUrl(url) {
  const u = (url || '').trim();
  return u.replace(/\/+$/, '');
}

async function fetchJson(url, token) {
  const res = await fetch(url, {
    headers: { Authorization: `Bearer ${token}` }
  });
  const text = await res.text();
  try {
    return { status: res.status, json: JSON.parse(text) };
  } catch (e) {
    return { status: res.status, json: { ok: false, error: 'Invalid server response', raw: text.slice(0, 300) } };
  }
}

async function testConnection(showSuccessToast = false) {
  const cfg = await chrome.storage.sync.get(DEFAULTS);
  const baseUrl = normalizeBaseUrl(cfg.baseUrl || DEFAULTS.baseUrl);
  const token = (cfg.token || '').trim();
  if (!token) {
    setConnectionState('Missing Token', 'Enter and save API token from Settings', 'error');
    return false;
  }
  try {
    const profileUrl = new URL(baseUrl + '/index.php');
    profileUrl.searchParams.set('page', 'ajax_profile');
    if (cfg.scopeAdminId) profileUrl.searchParams.set('scope_admin_id', cfg.scopeAdminId);

    const result = await fetchJson(profileUrl.toString(), token);
    const json = result.json || {};
    if (json.ok) {
      setConnectionState('Connected & Active', `${json.user?.name || 'Online'} • ${baseUrl.replace(/^https?:\/\//, '')}`, 'success');
      renderProfile(json);
      if (showSuccessToast) setStatus('Connection verified successfully!', 'success');
      return true;
    }

    renderProfile(null);
    setConnectionState('Auth Error', json.error || 'Invalid API Token', 'error');
    if (showSuccessToast) setStatus(json.error || 'Connection failed', 'error');
    return false;
  } catch (e) {
    renderProfile(null);
    setConnectionState('Connection Offline', 'Could not reach server. Check URL & network.', 'error');
    if (showSuccessToast) setStatus('Network unreachable', 'error');
    return false;
  }
}

async function loadSettings() {
  const cfg = await chrome.storage.sync.get(DEFAULTS);
  document.getElementById('baseUrl').value = cfg.baseUrl || DEFAULTS.baseUrl;
  document.getElementById('token').value = cfg.token || '';
  document.getElementById('scopeAdminId').value = cfg.scopeAdminId || '';
  
  // Auto test connection on popup open
  await testConnection(false);
}

async function togglePanel(tab, refresh) {
  await chrome.scripting.executeScript({
    target: { tabId: tab.id },
    func: (doRefresh) => {
      const el = document.getElementById('ds-wa-panel');
      if (el) {
        if (doRefresh) {
          document.dispatchEvent(new CustomEvent('ds-wa-boot'));
        }
        el.classList.remove('ds-hidden');
      } else {
        document.dispatchEvent(new CustomEvent('ds-wa-boot'));
      }
    },
    args: [refresh]
  });
}

document.getElementById('save').addEventListener('click', async () => {
  const baseUrl = normalizeBaseUrl(document.getElementById('baseUrl').value) || DEFAULTS.baseUrl;
  const token = (document.getElementById('token').value || '').trim();
  const scopeAdminId = (document.getElementById('scopeAdminId').value || '').trim();
  await chrome.storage.sync.set({ baseUrl, token, scopeAdminId });
  setStatus('Settings saved!', 'success');
  await testConnection(false);
});

document.getElementById('test').addEventListener('click', async () => {
  setStatus('Testing connection...', 'neutral');
  await testConnection(true);
});

document.getElementById('openPanel').addEventListener('click', async () => {
  setStatus('Opening panel...', 'neutral');
  try {
    const tabRes = await withActiveTab(async (tab) => {
      if (tab.url && tab.url.includes('web.whatsapp.com')) {
        await togglePanel(tab, true);
        return true;
      }
      return false;
    });
    if (tabRes) {
      setStatus('Panel opened in WhatsApp!', 'success');
    } else {
      await chrome.tabs.create({ url: 'https://web.whatsapp.com' });
      setStatus('Opening WhatsApp Web...', 'neutral');
    }
  } catch (e) {
    setStatus('Open WhatsApp Web tab', 'error');
  }
});

document.getElementById('refreshPanel').addEventListener('click', async () => {
  setStatus('Syncing panel...', 'neutral');
  try {
    await withActiveTab(async (tab) => togglePanel(tab, true));
    setStatus('Data synced with WhatsApp tab!', 'success');
  } catch (e) {
    setStatus('Open WhatsApp Web tab', 'error');
  }
});

async function openPage(pageName) {
  const cfg = await chrome.storage.sync.get(DEFAULTS);
  const baseUrl = normalizeBaseUrl(cfg.baseUrl || DEFAULTS.baseUrl) || DEFAULTS.baseUrl;
  await chrome.tabs.create({ url: `${baseUrl}/index.php?page=${pageName}` });
}

document.getElementById('openAdminPanel')?.addEventListener('click', () => openPage('admin_panel'));
document.getElementById('openSales')?.addEventListener('click', () => openPage('sales'));
document.getElementById('openDues')?.addEventListener('click', () => openPage('dues'));
document.getElementById('openSite')?.addEventListener('click', () => openPage('settings'));

loadSettings();
