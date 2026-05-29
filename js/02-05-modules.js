// ============================================================
// 02-auth.js
// ============================================================
STAS.checkSession = function() {
  STAS.user = window.STAS_SESSION || {};
  const name = STAS.user.userName || '';
  const role = STAS.user.userRole || '';
  document.getElementById('sidebarName').textContent   = name;
  document.getElementById('sidebarRole').textContent   = role;
  document.getElementById('sidebarAvatar').textContent = name.charAt(0).toUpperCase();
  if (role === 'superadmin' || role === 'admin')
    document.querySelectorAll('.superadmin-only').forEach(el => el.style.display = '');
  STAS.loadDashboard();
  STAS.loadSettings();
};
STAS.logout = async function() {
  try { await STAS.api('logout',{},'POST'); } catch(e) {}
  window.location.href = 'login.php';
};

// ============================================================
// 03-nav.js
// ============================================================
STAS.currentPage = 'dashboard';
STAS.navigate = function(page) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  const pageEl = document.getElementById('page-' + page);
  if (pageEl) pageEl.classList.add('active');
  document.querySelectorAll('.nav-link').forEach(a => a.classList.toggle('active', a.dataset.page === page));
  const titles = {
    dashboard:['Dashboard','Live market · NSE'], portfolio:['Portfolio','All holdings'],
    signals:['Signals','Buy & sell alerts'], transactions:['Transactions','Buy history'],
    m1tracker:['M1 Tracker','Cycle status'], reports:['Reports','P&L Analytics'],
    settings:['Strategy Settings','Configure'], admin:['User Management','Superadmin'],
  };
  const t = titles[page] || [page,''];
  document.getElementById('pageTitle').textContent    = t[0];
  document.getElementById('pageSubtitle').textContent = t[1];
  STAS.currentPage = page;
  document.getElementById('sidebar').classList.remove('open');
  if (page==='portfolio')    STAS.loadFullPortfolio();
  if (page==='signals')      STAS.loadSignals();
  if (page==='transactions') STAS.loadTransactions();
  if (page==='m1tracker')    STAS.loadM1Tracker();
  if (page==='reports')      STAS.loadReports();
  if (page==='admin')        STAS.loadUsers();
};
document.querySelectorAll('.nav-link[data-page]').forEach(l => {
  l.addEventListener('click', e => { e.preventDefault(); STAS.navigate(l.dataset.page); });
});

// ============================================================
// PAGINATION SYSTEM
// ============================================================
STAS._pg = {
  dash: { page:1, size:10 },
  port: { page:1, size:10 },
  sigs: { page:1, size:20 },
  tx:   { page:1, size:10 },
  m1:   { page:1, size:10 },
  rep:  { page:1, size:10 },
  usr:  { page:1, size:10 },
};

// Render pagination bar into a container div
STAS.renderPagination = function(containerId, state, total, onChangeFn) {
  const el = document.getElementById(containerId);
  if (!el) return;
  const { page, size } = state;
  const totalPages = Math.ceil(total / size);
  const sizes = [5, 10, 15, 20, 25, 50, 100];
  const from = total === 0 ? 0 : (page - 1) * size + 1;
  const to   = Math.min(page * size, total);

  if (total === 0) { el.innerHTML = ''; return; }

  let pageNums = '';
  for (let i = 1; i <= totalPages; i++) {
    if (i === 1 || i === totalPages || (i >= page - 1 && i <= page + 1)) {
      pageNums += `<button class="pg-num ${i===page?'active':''}" onclick="STAS._pgGo('${containerId}',${i})">${i}</button>`;
    } else if (i === page - 2 || i === page + 2) {
      pageNums += `<span class="pg-ellipsis">…</span>`;
    }
  }

  el.innerHTML = `<div class="pg-bar">
    <div class="pg-left">
      <select class="pg-size-sel" onchange="STAS._pgSize('${containerId}',this.value)">
        ${sizes.map(s=>`<option value="${s}" ${s==size?'selected':''}>${s} / page</option>`).join('')}
      </select>
      <span class="pg-info">${from}–${to} of ${total}</span>
    </div>
    <div class="pg-right">
      <button class="pg-btn" ${page<=1?'disabled':''} onclick="STAS._pgGo('${containerId}',${page-1})">‹ Prev</button>
      ${pageNums}
      <button class="pg-btn" ${page>=totalPages?'disabled':''} onclick="STAS._pgGo('${containerId}',${page+1})">Next ›</button>
    </div>
  </div>`;

  // Store callback reference
  el._pgFn = onChangeFn;
};

STAS._pgGo = function(containerId, newPage) {
  const el = document.getElementById(containerId);
  if (el && el._pgFn) el._pgFn('page', newPage);
};
STAS._pgSize = function(containerId, newSize) {
  const el = document.getElementById(containerId);
  if (el && el._pgFn) el._pgFn('size', parseInt(newSize));
};

// Slice data for current page
STAS.pageSlice = function(data, state) {
  const start = (state.page - 1) * state.size;
  return data.slice(start, start + state.size);
};

// ============================================================
// 04-dashboard.js
// ============================================================
STAS.loadDashboard = async function() {
  const [dashRes, portRes, sigRes] = await Promise.all([
    STAS.api('getDashboard'), STAS.api('getPortfolio'), STAS.api('getSignals'),
  ]);
  if (dashRes.status === 'ok') {
    const d = dashRes.data;
    document.getElementById('mTotalInvested').textContent   = STAS.rupee(d.totalInvested);
    document.getElementById('mStockCount').textContent      = d.stockCount + ' stocks · ' + d.totalQty + ' qty';
    document.getElementById('mCurrentValue').textContent    = STAS.rupee(d.currentValue);
    document.getElementById('mSignals').textContent         = (d.buySignals+d.sellSignals) || '0';
    document.getElementById('mSignalBreakdown').textContent = d.buySignals+' Buy · '+d.sellSignals+' Sell';
    document.getElementById('mPL').textContent    = STAS.pct(d.totalPLPct);
    document.getElementById('mPL').className      = 'metric-sub '+STAS.plClass(d.totalPLPct);
    document.getElementById('mPLAmt').textContent = STAS.rupee(d.totalPL);
    document.getElementById('mPLAmt').className   = 'metric-sub '+STAS.plClass(d.totalPL);
    document.getElementById('mPLPct').textContent = STAS.pct(d.totalPLPct);
    document.getElementById('mPLPct').className   = 'metric-value '+STAS.plClass(d.totalPLPct);
    const total = d.buySignals+d.sellSignals;
    const badge = document.getElementById('navSignalBadge');
    if (total>0) { badge.textContent=total; badge.style.display=''; }
    else badge.style.display='none';
  }
  if (portRes.status === 'ok') {
    STAS._portfolioData = portRes.data;
    STAS._pg.dash.page = 1; // reset to page 1 on fresh load
    STAS._renderDashPortfolio();
  }
  if (sigRes.status === 'ok') {
    STAS._signalsData = sigRes.data.signals;
    STAS.renderSignalsPanel(sigRes.data.signals.slice(0,6), 'signalsList');
    const uc = sigRes.data.unreadCount;
    const sp = document.getElementById('signalPanelCount');
    if (uc>0) { sp.textContent=uc+' new'; sp.style.display=''; }
    else sp.style.display='none';
  }
  document.getElementById('lastRefreshTime').textContent = 'Updated '+new Date().toLocaleTimeString('en-IN');
};

// ── Dashboard portfolio filter ────────────────────────────────
STAS._dashFilter = '';
STAS._applyDashFilter = function() {
  STAS._dashFilter = (document.getElementById('dashSearch')?.value || '').trim().toUpperCase();
  STAS._pg.dash.page = 1;
  STAS._renderDashPortfolio();
};
STAS._clearDashFilter = function() {
  const el = document.getElementById('dashSearch'); if (el) el.value = '';
  STAS._dashFilter = '';
  STAS._pg.dash.page = 1;
  STAS._renderDashPortfolio();
};

// Re-render dashboard portfolio with current pagination state
STAS._renderDashPortfolio = function() {
  let data = STAS._portfolioData || [];
  if (STAS._dashFilter) data = data.filter(s => s.symbol.toUpperCase().includes(STAS._dashFilter));
  const state = STAS._pg.dash;
  const slice = STAS.pageSlice(data, state);
  STAS.renderPortfolioTable(slice, 'portfolioTableBody');
  STAS.renderMobileCards(slice, 'portfolioMobileCards');
  const badge = document.getElementById('dashFilterBadge');
  if (badge) {
    if (STAS._dashFilter) {
      const inv = data.reduce((a,s)=>a+parseFloat(s.totalInvestment||0),0);
      badge.textContent = data.length + ' stocks · ' + STAS.rupee(inv);
      badge.style.display = '';
    } else { badge.style.display = 'none'; }
  }
  STAS.renderPagination('dashPagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.dash.page = val;
    if (type === 'size') { STAS._pg.dash.size = val; STAS._pg.dash.page = 1; }
    STAS._renderDashPortfolio();
  });
};

// ============================================================
// 05-portfolio.js
// ============================================================

function stackCell(rate, pct) {
  if (!rate && pct === null) return '<span class="gray" style="font-size:11px;">—</span>';
  const v    = pct !== null ? parseFloat(pct) : null;
  const cls  = v === null ? 'nt' : (v > 0 ? 'up' : (v < 0 ? 'dn' : 'nt'));
  const arr  = cls==='up' ? '▲' : (cls==='dn' ? '▼' : '');
  const ptxt = v !== null ? (v>0?'+':'') + v.toFixed(2)+'%' : '';
  const barW = v !== null ? Math.min(100, Math.abs(v)*3)+'%' : '0%';
  return `<div class="val-stack">
    ${rate !== null ? `<span class="val-rate">${STAS.rupee(rate)}</span>` : ''}
    <span class="val-pct ${cls}">${arr} ${ptxt}</span>
    <div class="sig-line ${cls}" style="width:${barW};"></div>
  </div>`;
}

function max1Cell(s) {
  const trailHigh = s.m1TrailingHigh ? parseFloat(s.m1TrailingHigh) : 0;
  const actThresh = s.activationThreshold ? parseFloat(s.activationThreshold)
                  : (s.activationPrice ? parseFloat(s.activationPrice) : 0);
  const trigger   = s.triggerPrice ? parseFloat(s.triggerPrice) : 0;
  const m1p       = (s.m1Pct !== null && s.m1Pct !== undefined) ? parseFloat(s.m1Pct) : null;
  const editVal   = trailHigh > 0 ? trailHigh : actThresh;

  let priceHtml = '';
  if (!s.m1Activated) {
    if (s.needPctForM1 !== null) {
      priceHtml = `<div style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
        <span class="m-need">need +${parseFloat(s.needPctForM1).toFixed(2)}%</span>
        <span style="font-size:10px;color:var(--gray);">${STAS.rupee(actThresh)}</span>
        <button class="m-edit" onclick="STAS.editM1('${s.id}','${s.m1Id||''}',${editVal})">✏</button>
      </div>`;
    } else {
      priceHtml = `<span class="gray" style="font-size:11px;">Waiting ${STAS.rupee(actThresh)}</span>`;
    }
  } else {
    const inBuyZone = m1p !== null && m1p <= -4.5;
    priceHtml = `<div style="display:flex;align-items:center;gap:4px;">
      <span class="m-g" style="color:var(--green);">G</span>
      <span class="m-price" style="${inBuyZone?'color:var(--red);font-weight:700;':''}">${STAS.rupee(trailHigh||actThresh)}</span>
      <button class="m-edit" onclick="STAS.editM1('${s.id}','${s.m1Id||''}',${editVal})">✏</button>
    </div>`;
  }

  const cls  = m1p===null?'nt':(m1p>=0?'up':'dn');
  const arr  = m1p===null?'':(m1p>=0?'▲':'▼');
  const ptxt = m1p!==null?((m1p>=0?'+':'')+m1p.toFixed(2)+'%'):'—';
  const inZone = s.m1Activated && m1p!==null && m1p<=0;
  const pctStyle = inZone?'color:var(--red);font-weight:700;':'';
  const barW = m1p!==null?Math.min(100,Math.abs(m1p)*3)+'%':'0%';
  const trigHtml = (s.m1Activated && trigger>0)
    ? `<span style="font-size:10px;color:${inZone?'var(--red)':'var(--green)'};">buy@${STAS.rupee(trigger)}</span>` : '';

  return `<div class="val-stack">
    ${priceHtml}
    ${trigHtml}
    <span class="val-pct ${cls}" style="${pctStyle}">${arr} ${ptxt}</span>
    <div class="sig-line ${cls}" style="width:${barW};"></div>
  </div>`;
}

function max2Cell(s) {
  const m2h = s.m2HighestPrice ? parseFloat(s.m2HighestPrice) : 0;
  const m2p = s.m2Pct !== null && s.m2Pct !== undefined ? parseFloat(s.m2Pct) : null;
  let priceHtml = '';
  if (!m2h) {
    priceHtml = '<span class="gray" style="font-size:11px;">—</span>';
  } else {
    priceHtml = `<div style="display:flex;align-items:center;gap:4px;">
      <span class="m-g" style="color:var(--amber);">G</span>
      <span class="m-price">${STAS.rupee(m2h)}</span>
      <button class="m-edit" onclick="STAS.startEditM2('${s.id}',${m2h})">✏</button>
    </div>`;
  }
  const cls  = m2p===null ? 'nt' : (m2p>0 ? 'up' : 'dn');
  const arr  = cls==='up' ? '▲' : (cls==='dn' ? '▼' : '');
  const ptxt = m2p !== null ? (m2p>0?'+':'') + m2p.toFixed(2)+'%' : '—';
  const barW = m2p !== null ? Math.min(100, Math.abs(m2p)*3)+'%' : '0%';
  return `<div class="val-stack">
    ${priceHtml}
    <span class="val-pct ${cls}">${arr} ${ptxt}</span>
    <div class="sig-line ${cls}" style="width:${barW};"></div>
  </div>`;
}

// ── Desktop table renderer ────────────────────────────────────
STAS.renderPortfolioTable = function(data, tbodyId) {
  const tbody = document.getElementById(tbodyId);
  if (!tbody) return;
  if (!data || !data.length) {
    tbody.innerHTML = `<tr><td colspan="13" class="text-center py-4" style="color:var(--gray);font-size:13px;">No stocks yet. Click <strong>+ Add Stock</strong>.</td></tr>`;
    return;
  }
  const settings = STAS._settings || { sellTargetPct:30, maxBuyCount:5 };
  tbody.innerHTML = data.map((s, idx) => {
    const cmp    = s.cmp     ? parseFloat(s.cmp)    : null;
    const avg    = parseFloat(s.avgPrice     || 0);
    const lastBuy= parseFloat(s.lastBuyPrice || 0);
    const avPct  = s.avPct  !== undefined ? s.avPct  : null;
    const brPct  = s.brPct  !== undefined ? s.brPct  : null;
    const chgPct = s.changePercent !== undefined && s.changePercent !== null ? parseFloat(s.changePercent) : null;
    const plAmt  = s.plAmt  !== undefined ? s.plAmt  : null;
    const plPct  = s.plPct  !== undefined ? s.plPct  : null;
    const plNum  = plPct !== null ? parseFloat(plPct) : null;
    const pullPctSig = parseFloat((STAS._settings||{}).pullbackPct||5);
    const m1BuyNow = s.m1Activated && s.m1Pct !== null && parseFloat(s.m1Pct) <= -pullPctSig;
    const sellZone = plNum !== null && plNum >= parseFloat(settings.sellTargetPct);
    let sigBadge = '<span class="sig-none">Watch</span>';
    if (m1BuyNow)                              sigBadge = '<span class="sig-buy">▲ BUY</span>';
    else if (sellZone)                          sigBadge = '<span class="sig-sell">▼ SELL</span>';
    else if (plNum !== null && plNum >= 15)     sigBadge = '<span class="sig-wait">⏳ WAIT</span>';
    return `<tr>
      <td style="color:var(--gray);font-size:11px;">${idx+1}</td>
      <td class="stock-cell" onclick="STAS.openBuyHistory('${s.id}')">
        <div class="stock-name">${s.symbol}</div>
        <div class="stock-sub">${s.exchange} · QTY:${s.totalQty}</div>
      </td>
      <td><strong>${s.totalQty}</strong></td>
      <td>${stackCell(avg>0?avg:null, avPct)}</td>
      <td>${stackCell(lastBuy>0?lastBuy:null, brPct)}</td>
      <td>${max1Cell(s)}</td>
      <td>${max2Cell(s)}</td>
      <td>${stackCell(cmp, chgPct)}</td>
      <td>${sigBadge}</td>
      <td>${stackCell(plAmt, plPct)}</td>
      <td style="font-size:11px;color:var(--gray);">${STAS.rupee(s.totalInvestment)}</td>
      <td style="font-size:11px;">${cmp ? STAS.rupee(cmp * parseInt(s.totalQty)) : '—'}</td>
      <td>
        <button class="btn-link" onclick="STAS.openAddStockModal('${s.symbol}','${s.exchange}')">+Buy</button>
      </td>
    </tr>`;
  }).join('');
};

// ── Mobile Cards — NEW 4-ROW LAYOUT ──────────────────────────
STAS.renderMobileCards = function(data, containerId) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (!data || !data.length) {
    el.innerHTML = '<div class="text-center py-4" style="color:var(--gray);font-size:13px;">No stocks yet.</div>';
    return;
  }
  const settings = STAS._settings || { sellTargetPct:30, pullbackPct:5 };

  el.innerHTML = data.map(s => {
    const cmp    = s.cmp ? parseFloat(s.cmp) : null;
    const avg    = parseFloat(s.avgPrice || 0);
    const lastBuy= parseFloat(s.lastBuyPrice || 0);
    const plPct  = s.plPct !== undefined ? parseFloat(s.plPct) : null;
    const plAmt  = s.plAmt !== undefined ? parseFloat(s.plAmt) : null;
    const avPct  = s.avPct !== undefined ? parseFloat(s.avPct) : null;
    const brPct  = s.brPct !== undefined ? parseFloat(s.brPct) : null;
    const chg    = s.changePercent ? parseFloat(s.changePercent) : null;
    const m2p    = s.m2Pct !== null && s.m2Pct !== undefined ? parseFloat(s.m2Pct) : null;
    const m1p    = s.m1Pct !== null && s.m1Pct !== undefined ? parseFloat(s.m1Pct) : null;
    const mktVal = cmp ? cmp * parseInt(s.totalQty) : null;

    // Signal
    const pullPctSig = parseFloat(settings.pullbackPct || 5);
    const m1Buy  = s.m1Activated && m1p !== null && m1p <= -pullPctSig;
    const sellZ  = plPct !== null && plPct >= parseFloat(settings.sellTargetPct);
    let sigBadge = '<span class="sig-none">Watch</span>';
    if (m1Buy)               sigBadge = '<span class="sig-buy">▲ BUY</span>';
    else if (sellZ)          sigBadge = '<span class="sig-sell">▼ SELL</span>';
    else if (plPct!==null && plPct>=15) sigBadge = '<span class="sig-wait">⏳ WAIT</span>';

    // Helper: pct badge
    const pctBadge = (v, cls) => v !== null ? `<span class="sc2-pct ${cls}">${v>0?'▲':'▼'} ${Math.abs(v).toFixed(2)}%</span>` : '';

    // M1 display
    const actThresh = s.activationPrice ? parseFloat(s.activationPrice) : 0;
    const trailHigh = s.m1TrailingHigh  ? parseFloat(s.m1TrailingHigh) : 0;
    let m1TopVal  = s.m1Activated ? (trailHigh || actThresh) : actThresh;
    let m1TopCls  = s.m1Activated ? 'green' : 'amber';
    let m1Sub     = s.m1Activated
      ? (m1p !== null ? pctBadge(m1p, m1p >= 0 ? 'up' : 'dn') : '')
      : (s.needPctForM1 !== null ? `<span class="sc2-need">need +${parseFloat(s.needPctForM1).toFixed(1)}%</span>` : '');

    // M2 display
    const m2h = s.m2HighestPrice ? parseFloat(s.m2HighestPrice) : 0;

    return `<div class="sc2-card">

      <!-- ROW 1: Name | Qty | Avg Rate -->
      <div class="sc2-row sc2-row1">
        <div class="sc2-name-cell">
          <span class="sc2-sym" onclick="STAS.openBuyHistory('${s.id}')">${s.symbol}</span>
          <span class="sc2-exch">${s.exchange} · Buy #${s.buyCount}</span>
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">QTY</span>
          <span class="sc2-val">${s.totalQty}</span>
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">AVG RATE</span>
          <span class="sc2-val">${STAS.rupee(avg)}</span>
          ${pctBadge(avPct, avPct !== null ? (avPct >= 0 ? 'up' : 'dn') : '')}
        </div>
      </div>

      <!-- ROW 2: Buy Rate | MAX1 | MAX2 -->
      <div class="sc2-row sc2-row2">
        <div class="sc2-cell">
          <span class="sc2-lbl">BUY RATE</span>
          <span class="sc2-val">${STAS.rupee(lastBuy || avg)}</span>
          ${pctBadge(brPct, brPct !== null ? (brPct >= 0 ? 'up' : 'dn') : '')}
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">MAX1 / M1%</span>
          <span class="sc2-val ${m1TopCls}">${STAS.rupee(m1TopVal)}</span>
          ${m1Sub}
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">MAX2 / M2%</span>
          <span class="sc2-val amber">${m2h ? STAS.rupee(m2h) : '—'}</span>
          ${pctBadge(m2p, m2p !== null ? (m2p >= 0 ? 'up' : 'dn') : '')}
        </div>
      </div>

      <!-- ROW 3: CMP/CHG% | Signal -->
      <div class="sc2-row sc2-row3">
        <div class="sc2-cell">
          <span class="sc2-lbl">RATE / CHG%</span>
          <span class="sc2-val">${cmp ? STAS.rupee(cmp) : '—'}</span>
          ${pctBadge(chg, chg !== null ? (chg >= 0 ? 'up' : 'dn') : '')}
        </div>
        <div class="sc2-cell sc2-sig-cell">
          <span class="sc2-lbl">SIGNAL</span>
          <div>${sigBadge}</div>
        </div>
      </div>

      <!-- ROW 4: P&L | Invested | Mkt Value | Actions -->
      <div class="sc2-row sc2-row4">
        <div class="sc2-cell">
          <span class="sc2-lbl">P&amp;L</span>
          <span class="sc2-val ${plAmt!==null?(plAmt>=0?'green':'red'):''}">${plAmt!==null?STAS.rupee(plAmt):'—'}</span>
          ${pctBadge(plPct, plPct !== null ? (plPct >= 0 ? 'up' : 'dn') : '')}
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">INVESTED</span>
          <span class="sc2-val">${STAS.rupee(s.totalInvestment)}</span>
        </div>
        <div class="sc2-cell">
          <span class="sc2-lbl">MKT VALUE</span>
          <span class="sc2-val">${mktVal ? STAS.rupee(mktVal) : '—'}</span>
        </div>
        <div class="sc2-actions">
          <button class="sc2-btn-buy" onclick="STAS.openAddStockModal('${s.symbol}','${s.exchange}')">+Buy</button>
          <button class="sc2-btn-del" onclick="STAS.deleteStock('${s.id}','${s.symbol}')">✕</button>
        </div>
      </div>

    </div>`;
  }).join('');
};

// ── Full Portfolio Page ───────────────────────────────────────
STAS._portFilter = '';
STAS._applyPortFilter = function() {
  STAS._portFilter = (document.getElementById('portSearch')?.value || '').trim().toUpperCase();
  STAS._pg.port.page = 1;
  STAS._renderPortPage();
};
STAS._clearPortFilter = function() {
  const el = document.getElementById('portSearch'); if (el) el.value = '';
  STAS._portFilter = '';
  STAS._pg.port.page = 1;
  STAS._renderPortPage();
};

STAS.loadFullPortfolio = async function() {
  const res = await STAS.api('getPortfolio');
  if (res.status === 'ok') {
    STAS._portfolioData = res.data;
    STAS._portFilter = '';
    const el = document.getElementById('portSearch'); if (el) el.value = '';
    STAS._pg.port.page = 1;
    STAS._renderPortPage();
  }
};

STAS._renderPortPage = function() {
  let data = STAS._portfolioData || [];
  if (STAS._portFilter) data = data.filter(s => s.symbol.toUpperCase().includes(STAS._portFilter));
  const state = STAS._pg.port;
  const slice = STAS.pageSlice(data, state);
  STAS.renderPortfolioTable(slice, 'fullPortfolioBody');
  STAS.renderMobileCards(slice, 'fullMobileCards');
  const badge = document.getElementById('portFilterBadge');
  if (badge) {
    if (STAS._portFilter) {
      const inv = data.reduce((a,s)=>a+parseFloat(s.totalInvestment||0),0);
      badge.textContent = data.length + ' stocks · ' + STAS.rupee(inv);
      badge.style.display = '';
    } else { badge.style.display = 'none'; }
  }
  STAS.renderPagination('portPagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.port.page = val;
    if (type === 'size') { STAS._pg.port.size = val; STAS._pg.port.page = 1; }
    STAS._renderPortPage();
  });
};

// ── Edit M1 ───────────────────────────────────────────────────
STAS.editM1 = async function(portfolioId, m1Id, currentAct) {
  const cur = parseFloat(currentAct || 0);
  const newVal = prompt(
    `Edit M1 Trailing High\nCurrent: ₹${cur.toFixed(2)}\n\nBuy signal fires at: this value × (1 - pullback%)\nEnter new M1 trailing high:`,
    cur > 0 ? cur.toFixed(2) : ''
  );
  if (!newVal || isNaN(parseFloat(newVal))) return;
  const value = parseFloat(newVal);
  const res = await STAS.api('updateM1M2', { portfolioId, m1Id, field:'activation', value }, 'POST');
  if (res.status === 'ok') {
    const newTrig = res.data?.newTrigger || 0;
    STAS.toast(`M1 updated. Activation: ₹${value.toFixed(2)} | Buy signal at: ₹${parseFloat(newTrig).toFixed(2)}`);
    await STAS.api('runStrategyCheck', {}, 'POST');
    await STAS.loadDashboard();
    if (STAS.currentPage === 'm1tracker') STAS.loadM1Tracker();
  } else {
    STAS.toast(res.message, 'error');
  }
};

STAS.startEditM2 = async function(portfolioId, currentVal) {
  const cur = parseFloat(currentVal || 0);
  const newVal = prompt(`Edit M2 Trailing High\nCurrent: ₹${cur.toFixed(2)}\n\nEnter new M2 high watermark:`, cur > 0 ? cur.toFixed(2) : '');
  if (!newVal || isNaN(parseFloat(newVal))) return;
  const res = await STAS.api('updateM1M2', { portfolioId, field:'m2', value:parseFloat(newVal) }, 'POST');
  if (res.status==='ok') { STAS.toast('M2 updated'); STAS.loadDashboard(); }
  else STAS.toast(res.message,'error');
};

STAS.startEditM1 = STAS.editM1;

// ── Add Stock Modal ───────────────────────────────────────────
STAS._addModal=null; STAS._searchTimer=null;
STAS.openAddStockModal = function(symbol, exchange) {
  document.getElementById('addSymbol').value   = symbol   || '';
  document.getElementById('addExchange').value = exchange || 'NSE';
  document.getElementById('addPrice').value    = '';
  document.getElementById('addQty').value      = '';
  document.getElementById('addDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('addNotes').value    = '';
  document.getElementById('addStockError').style.display = 'none';
  document.getElementById('avgPreview').style.display    = 'none';
  document.getElementById('stockDropdown').style.display = 'none';
  if (!STAS._addModal) STAS._addModal = new bootstrap.Modal(document.getElementById('addStockModal'));
  STAS._addModal.show();
};

STAS.onSymbolInput = function() {
  const q = document.getElementById('addSymbol').value.trim();
  clearTimeout(STAS._searchTimer);
  if (q.length<2) { document.getElementById('stockDropdown').style.display='none'; return; }
  STAS._searchTimer = setTimeout(()=>STAS.fetchStockSearch(q), 400);
  STAS.updateAddPreview();
};

STAS.fetchStockSearch = async function(q) {
  const drop = document.getElementById('stockDropdown');
  drop.innerHTML='<div class="search-loading">🔍 Searching...</div>';
  drop.style.display='';
  try {
    const res  = await fetch(`api/index.php?action=searchStock&q=${encodeURIComponent(q.toUpperCase())}`);
    const data = await res.json();
    const results = data.data?.results||[];
    const source  = data.data?.source ||'yahoo';
    const total   = data.data?.total  ||0;
    if (!results.length) {
      const sym=q.toUpperCase();
      drop.innerHTML=`<div class="stock-option" onclick="STAS.selectStock('${sym}','NSE')">
        <div><div class="stock-option-sym">${sym}</div><div class="stock-option-name">Use "${sym}" as typed</div></div>
        <span class="stock-option-exch">NSE</span></div>
        <div style="padding:6px 12px;font-size:10px;color:var(--gray);">No match. <a href="import_stocks.php" target="_blank">Import NSE stocks</a></div>`;
      return;
    }
    const srcNote = source==='local'
      ? `<span style="font-size:10px;color:var(--green);">📦 ${Number(total).toLocaleString()} stocks</span>`
      : `<span style="font-size:10px;color:var(--amber);">⚠️ Live search</span>`;
    drop.innerHTML = results.map(r=>`
      <div class="stock-option" onclick="STAS.selectStock('${r.symbol}','${r.exchange}')">
        <div><div class="stock-option-sym">${r.symbol}</div><div class="stock-option-name">${r.name||r.symbol}</div></div>
        <span class="stock-option-exch">${r.exchange}</span>
      </div>`).join('')+`<div style="padding:5px 12px;border-top:1px solid var(--border);">${srcNote}</div>`;
  } catch(e) {
    const sym=q.toUpperCase();
    drop.innerHTML=`<div class="stock-option" onclick="STAS.selectStock('${sym}','NSE')">
      <div class="stock-option-sym">${sym}</div><div class="stock-option-name">Use "${sym}"</div></div>`;
  }
};

STAS.selectStock = function(symbol, exchange) {
  document.getElementById('addSymbol').value   = symbol;
  document.getElementById('addExchange').value = exchange||'NSE';
  document.getElementById('stockDropdown').style.display='none';
  document.getElementById('addPrice').focus();
  STAS.updateAddPreview();
};

STAS.updateAddPreview = function() {
  const price=parseFloat(document.getElementById('addPrice').value);
  const qty  =parseInt(document.getElementById('addQty').value);
  const sym  =document.getElementById('addSymbol').value.trim().toUpperCase();
  if (!price||!qty||price<=0||qty<=0) { document.getElementById('avgPreview').style.display='none'; return; }
  const s=STAS._settings||{activationPct:10,pullbackPct:5};
  const existing=(STAS._portfolioData||[]).find(x=>x.symbol===sym);
  let newAvg=price,newQty=qty;
  if (existing) { newQty=existing.totalQty+qty; newAvg=((existing.avgPrice*existing.totalQty)+(price*qty))/newQty; }
  const m1=price*(1+s.activationPct/100),trigger=m1*(1-s.pullbackPct/100),drop20=price*0.80;
  document.getElementById('previewAvg').textContent     = STAS.rupee(newAvg);
  document.getElementById('previewQty').textContent     = newQty;
  document.getElementById('previewM1').textContent      = STAS.rupee(m1);
  document.getElementById('previewTrigger').textContent = STAS.rupee(trigger);
  document.getElementById('previewDrop20').textContent  = STAS.rupee(drop20);
  document.getElementById('avgPreview').style.display   = '';
};

STAS.submitAddStock = async function() {
  const symbol  =document.getElementById('addSymbol').value.trim().toUpperCase();
  const exchange=document.getElementById('addExchange').value;
  const buyPrice=parseFloat(document.getElementById('addPrice').value);
  const qty     =parseInt(document.getElementById('addQty').value);
  const txDate  =document.getElementById('addDate').value;
  const notes   =document.getElementById('addNotes').value.trim();
  const errEl   =document.getElementById('addStockError');
  errEl.style.display='none';
  if (!symbol)           {errEl.textContent='Symbol required';errEl.style.display='';return;}
  if (!buyPrice||buyPrice<=0){errEl.textContent='Enter valid price';errEl.style.display='';return;}
  if (!qty||qty<1)       {errEl.textContent='Enter valid quantity';errEl.style.display='';return;}
  const btn=document.getElementById('addStockSubmitBtn');
  btn.disabled=true; btn.textContent='Saving...';
  const res=await STAS.api('addStock',{symbol,exchange,buyPrice,qty,txDate,notes},'POST');
  btn.disabled=false; btn.textContent='Record Buy & Start M1 Cycle';
  if (res.status==='ok') { STAS._addModal.hide(); STAS.toast(res.message||'Buy recorded!'); STAS.loadDashboard(); }
  else { errEl.textContent=res.message||'Error'; errEl.style.display=''; }
};

STAS.deleteStock = async function(portfolioId, symbol) {
  if (!confirm(`Remove ${symbol} from portfolio?`)) return;
  const res=await STAS.api('deleteStock',{portfolioId},'POST');
  if (res.status==='ok') { STAS.toast(symbol+' removed'); STAS.loadDashboard(); }
  else STAS.toast(res.message,'error');
};

STAS.refreshPrices = async function() {
  const btn=document.getElementById('btnRefresh');
  btn.textContent='🔄 Refreshing...'; btn.disabled=true;
  await STAS.api('refreshPrices',{},'POST');
  await STAS.api('runStrategyCheck',{},'POST');
  btn.textContent='🔄 Refresh'; btn.disabled=false;
  STAS.loadDashboard();
  STAS.toast('Prices refreshed & strategy checked');
};

document.addEventListener('DOMContentLoaded',()=>{ STAS.checkSession(); });
