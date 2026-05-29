// ============================================================
// 01-config.js — Global config, API helper, utilities
// ============================================================

const STAS = window.STAS || {};

STAS.API  = 'api/index.php';
STAS.user = null;

// ── API fetch with error display ─────────────────────────────
STAS.api = async function(action, payload = null, method = 'GET') {
  // Handle GET params appended to action (e.g. 'getSignals&type=BUY')
  const [baseAction, ...extras] = action.split('&');
  const extraStr = extras.length ? '&' + extras.join('&') : '';
  const url  = `${STAS.API}?action=${baseAction}${extraStr}`;
  const opts = { method: payload ? 'POST' : method, headers: {'Content-Type':'application/json'} };
  if (payload) opts.body = JSON.stringify(payload);

  try {
    const res  = await fetch(url, opts);
    const text = await res.text();

    // If not JSON, show the raw error (PHP error/warning)
    try {
      return JSON.parse(text);
    } catch(e) {
      console.error(`API ${action} returned non-JSON:`, text.substring(0, 500));
      STAS.showPageError(`Server error on [${action}]: ${text.substring(0, 200)}`);
      return { status: 'error', message: 'Server error', data: null };
    }
  } catch(e) {
    console.error(`API ${action} network error:`, e.message);
    return { status: 'error', message: 'Network error: ' + e.message, data: null };
  }
};

// Show a visible error banner at top of page
STAS.showPageError = function(msg) {
  let banner = document.getElementById('stas-error-banner');
  if (!banner) {
    banner = document.createElement('div');
    banner.id = 'stas-error-banner';
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;background:#A32D2D;color:#fff;padding:10px 18px;font-size:13px;z-index:9999;display:flex;justify-content:space-between;align-items:center;';
    banner.innerHTML = `<span id="stas-error-text"></span><button onclick="document.getElementById('stas-error-banner').remove()" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer;">✕</button>`;
    document.body.prepend(banner);
  }
  document.getElementById('stas-error-text').textContent = msg;
};

// Show loading state in a tbody
STAS.setLoading = function(tbodyId, cols = 7) {
  const el = document.getElementById(tbodyId);
  if (el) el.innerHTML = `<tr><td colspan="${cols}" class="text-center py-4" style="color:var(--gray);font-size:13px;">⏳ Loading...</td></tr>`;
};

// Show empty state
STAS.setEmpty = function(tbodyId, msg, cols = 7) {
  const el = document.getElementById(tbodyId);
  if (el) el.innerHTML = `<tr><td colspan="${cols}" class="text-center py-4" style="color:var(--gray);font-size:13px;">${msg}</td></tr>`;
};

// ── Format helpers ────────────────────────────────────────────
STAS.rupee = (n) => {
  if (n === null || n === undefined || isNaN(n) || n === '' ) return '—';
  return '₹' + parseFloat(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

STAS.pct = (n) => {
  if (n === null || n === undefined || isNaN(n)) return '—';
  const v = parseFloat(n);
  return (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
};

STAS.plClass = (n) => {
  const v = parseFloat(n);
  return isNaN(v) ? '' : (v >= 0 ? 'green' : 'red');
};

STAS.dateStr = (d) => {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
};

STAS.timeAgo = (d) => {
  if (!d) return '';
  const diff = Math.floor((Date.now() - new Date(d)) / 1000);
  if (diff < 60)    return 'Just now';
  if (diff < 3600)  return Math.floor(diff/60)   + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600)  + 'h ago';
  return STAS.dateStr(d);
};

STAS.toast = function(msg, type = 'success') {
  const el = document.createElement('div');
  el.style.cssText = `
    position:fixed; top:18px; right:18px; z-index:9999;
    background:${type === 'success' ? '#27862A' : '#A32D2D'};
    color:#fff; padding:10px 18px; border-radius:8px; max-width:320px;
    font-size:13px; box-shadow:0 3px 12px rgba(0,0,0,0.2); transition:opacity .4s;
  `;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3500);
};

STAS.toggleSidebar = function() {
  document.getElementById('sidebar').classList.toggle('open');
};
