// ============================================================
// 06-signals.js  (with Delete button + pagination)
// ============================================================
STAS.renderSignalsPanel = function(signals, containerId) {
  const el = document.getElementById(containerId);
  if (!el) return;
  if (!signals || !signals.length) {
    el.innerHTML = '<div class="text-center text-muted py-4" style="font-size:13px;">No signals. Click Refresh to check.</div>';
    return;
  }
  el.innerHTML = signals.map(s => `
    <div class="signal-item ${s.signalType==='SELL'?'sell':'buy'} ${!s.isRead?'signal-unread':''}">
      <div class="signal-top">
        <span class="signal-sym">${s.symbol||''}</span>
        <span class="${s.signalType==='SELL'?'sig-sell':'sig-buy'}">${s.signalType}</span>
      </div>
      <div class="signal-msg">${s.message||''}</div>
      <div class="signal-foot">
        <span class="signal-time">${STAS.timeAgo(s.signalDate)}</span>
        <div style="display:flex;gap:8px;align-items:center;">
          <span class="signal-action" onclick="STAS.actionSignal('${s.id}','${s.signalType}','${s.symbol||''}')">
            ${s.signalType==='SELL'?'Mark Actioned ↗':'Record Buy ↗'}
          </span>
          <span class="signal-del" onclick="STAS.deleteSignal('${s.id}',this)" title="Delete signal">🗑</span>
        </div>
      </div>
    </div>
  `).join('');
};

STAS._signalsAll = [];

STAS.loadSignals = async function() {
  const res = await STAS.api('getSignals');
  if (res.status === 'ok') {
    STAS._signalsAll = res.data.signals;
    STAS._signalsData = res.data.signals;
    STAS._pg.sigs.page = 1;
    STAS._applySigFilter();
  }
};

STAS._applySigFilter = function() {
  const text = (document.getElementById('sigSearch')?.value || '').trim().toUpperCase();
  const from = document.getElementById('sigFrom')?.value || '';
  const to   = document.getElementById('sigTo')?.value || '';
  const type = document.getElementById('signalFilter')?.value || '';
  let data = STAS._signalsAll || [];
  if (text) data = data.filter(s => (s.symbol||'').toUpperCase().includes(text));
  if (from) data = data.filter(s => (s.signalDate||'').substring(0,10) >= from);
  if (to)   data = data.filter(s => (s.signalDate||'').substring(0,10) <= to);
  if (type) data = data.filter(s => s.signalType === type);
  const badge = document.getElementById('sigFilterBadge');
  if (badge) {
    if (text || from || to || type) {
      const buy = data.filter(s=>s.signalType==='BUY').length;
      const sell = data.filter(s=>s.signalType==='SELL').length;
      badge.textContent = data.length + ' signals · ' + buy + ' Buy · ' + sell + ' Sell';
      badge.style.display = '';
    } else { badge.style.display = 'none'; }
  }
  STAS._sigsFiltered = data;
  STAS._pg.sigs.page = 1;
  STAS._renderSignalsPage();
};
STAS._clearSigFilter = function() {
  ['sigSearch','sigFrom','sigTo'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  document.getElementById('signalFilter').value = '';
  STAS._applySigFilter();
};

STAS._renderSignalsPage = function() {
  const data  = STAS._sigsFiltered !== undefined ? STAS._sigsFiltered : (STAS._signalsAll || []);
  const state = STAS._pg.sigs;
  const slice = STAS.pageSlice(data, state);
  STAS.renderSignalsPanel(slice, 'allSignalsList');
  STAS.renderPagination('signalsPagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.sigs.page = val;
    if (type === 'size') { STAS._pg.sigs.size = val; STAS._pg.sigs.page = 1; }
    STAS._renderSignalsPage();
  });
};

STAS.deleteSignal = async function(id, iconEl) {
  if (!confirm('Delete this signal?')) return;
  iconEl.textContent = '⏳';
  const res = await STAS.api('deleteSignal', { id }, 'POST');
  if (res.status === 'ok') {
    STAS.toast('Signal deleted');
    // Remove from local array and re-render
    STAS._signalsAll = (STAS._signalsAll || []).filter(s => s.id !== id);
    STAS._renderSignalsPage();
    // Update unread badge
    STAS.loadDashboard();
  } else {
    iconEl.textContent = '🗑';
    STAS.toast(res.message, 'error');
  }
};

STAS.markAllRead = async function() {
  await STAS.api('markSignalRead', { id:'all' }, 'POST');
  STAS.loadDashboard();
  STAS.toast('All signals marked as read');
};

STAS.actionSignal = async function(id, type, symbol) {
  await STAS.api('markSignalActioned', { id }, 'POST');
  if (type !== 'SELL' && symbol) STAS.openAddStockModal(symbol);
  else { STAS.toast('Signal actioned'); STAS.loadDashboard(); }
};

// ============================================================
// 07-transactions.js
// ============================================================
STAS._txData = [];
STAS._txFiltered = null;

STAS.loadTransactions = async function() {
  const res   = await STAS.api('getTransactions');
  const tbody = document.getElementById('txTableBody');
  if (res.status!=='ok') {
    tbody.innerHTML='<tr><td colspan="8" class="text-center text-muted py-4">No transactions yet.</td></tr>'; return;
  }
  STAS._txData = res.data || [];
  STAS._txFiltered = null;
  STAS._pg.tx.page = 1;
  STAS._renderTxPage();
};

STAS._applyTxFilter = function() {
  const text = (document.getElementById('txSearch')?.value || '').trim().toUpperCase();
  const from = document.getElementById('txFrom')?.value || '';
  const to   = document.getElementById('txTo')?.value || '';
  let data = STAS._txData || [];
  if (text) data = data.filter(t => t.symbol.toUpperCase().includes(text));
  if (from) data = data.filter(t => (t.txDate||'') >= from);
  if (to)   data = data.filter(t => (t.txDate||'') <= to);
  STAS._txFiltered = data;
  const badge = document.getElementById('txFilterBadge');
  if (badge) {
    if (text || from || to) {
      const total = data.reduce((a,t)=>a+parseFloat(t.buyPrice||0)*parseInt(t.qty||0),0);
      badge.textContent = data.length + ' txns · ' + STAS.rupee(total);
      badge.style.display = '';
    } else { badge.style.display = 'none'; }
  }
  STAS._pg.tx.page = 1;
  STAS._renderTxPage();
};
STAS._clearTxFilter = function() {
  ['txSearch','txFrom','txTo'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
  STAS._txFiltered = null;
  const badge = document.getElementById('txFilterBadge'); if (badge) badge.style.display='none';
  STAS._pg.tx.page = 1;
  STAS._renderTxPage();
};

STAS._renderTxPage = function() {
  const data  = STAS._txFiltered !== null ? STAS._txFiltered : (STAS._txData || []);
  const state = STAS._pg.tx;
  const tbody = document.getElementById('txTableBody');
  if (!data.length) {
    tbody.innerHTML='<tr><td colspan="8" class="text-center text-muted py-4">No transactions yet.</td></tr>';
    const pg = document.getElementById('txPagination'); if (pg) pg.innerHTML='';
    return;
  }
  const slice = STAS.pageSlice(data, state);
  tbody.innerHTML = slice.map(t => `
    <tr>
      <td>${STAS.dateStr(t.txDate)}</td>
      <td><strong>${t.symbol}</strong></td>
      <td>Buy #${t.buyNumber}</td>
      <td>${STAS.rupee(t.buyPrice)}</td>
      <td>${t.qty}</td>
      <td>${STAS.rupee(t.buyPrice*t.qty)}</td>
      <td>${t.notes||'—'}</td>
      <td>
        <button class="btn-link red" onclick="STAS.deleteTransaction('${t.id}','${t.symbol}')">🗑 Delete</button>
      </td>
    </tr>
  `).join('');
  STAS.renderPagination('txPagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.tx.page = val;
    if (type === 'size') { STAS._pg.tx.size = val; STAS._pg.tx.page = 1; }
    STAS._renderTxPage();
  });
};

STAS.deleteTransaction = async function(txId, symbol) {
  if (!confirm(`Delete this ${symbol} transaction?\n\nThis will:\n• Recalculate average price\n• Reset M1 cycle from new last buy\n• Update all portfolio figures`)) return;
  const btn = event.target;
  btn.textContent = '⏳'; btn.disabled = true;
  const res = await STAS.api('deleteTransaction', { id:txId }, 'POST');
  if (res.status==='ok') {
    STAS.toast(res.message || 'Transaction deleted.');
    await STAS.loadDashboard();
    STAS.loadTransactions();
    if (STAS._historyPortfolioId) STAS.openBuyHistory(STAS._historyPortfolioId);
  } else {
    STAS.toast(res.message,'error');
    btn.textContent='🗑 Delete'; btn.disabled=false;
  }
};

// ── Buy History Modal ────────────────────────────────────────
STAS._historyModal       = null;
STAS._historyPortfolioId = null;

STAS.openBuyHistory = async function(portfolioId) {
  STAS._historyPortfolioId = portfolioId;
  const res  = await fetch(`api/index.php?action=getBuyHistory&portfolioId=${portfolioId}`);
  const data = await res.json();
  if (data.status!=='ok') { STAS.toast('Could not load history','error'); return; }
  const d = data.data;
  document.getElementById('historyTitle').textContent   = 'BUY HISTORY • ' + d.symbol;
  document.getElementById('historySummary').textContent =
    `TOTAL QTY ${d.totalQty}  •  AVG ₹${parseFloat(d.avgPrice).toFixed(2)}  •  COST ${STAS.rupee(d.totalInvestment)}`;
  const tbody = document.getElementById('historyTableBody');
  if (!d.transactions.length) {
    tbody.innerHTML='<tr><td colspan="5" class="text-center text-muted py-3">No transactions</td></tr>';
  } else {
    tbody.innerHTML = d.transactions.map(t => `
      <tr>
        <td>${t.txDate}</td>
        <td><strong>${t.qty}</strong></td>
        <td><strong>₹${parseFloat(t.buyPrice).toFixed(2)}</strong></td>
        <td>${STAS.rupee(t.total)}</td>
        <td><span class="del-icon" title="Delete" onclick="STAS.deleteFromHistory('${t.id}','${d.symbol}','${portfolioId}')">🗑</span></td>
      </tr>
    `).join('');
  }
  if (!STAS._historyModal) STAS._historyModal = new bootstrap.Modal(document.getElementById('buyHistoryModal'));
  STAS._historyModal.show();
};

STAS.deleteFromHistory = async function(txId, symbol, portfolioId) {
  if (!confirm(`Delete this ${symbol} buy transaction?\n\nThis will recalculate:\n• Average price\n• M1 cycle (reset from new last buy)\n• All portfolio figures`)) return;
  const iconEl = event.target;
  iconEl.textContent = '⏳';
  const res = await STAS.api('deleteTransaction',{id:txId},'POST');
  if (res.status==='ok') {
    STAS.toast(res.message || 'Deleted & recalculated');
    const reloadRes  = await fetch(`api/index.php?action=getBuyHistory&portfolioId=${portfolioId}`);
    const reloadData = await reloadRes.json();
    if (reloadData.status==='ok' && reloadData.data.transactions.length>0) {
      const d = reloadData.data;
      document.getElementById('historySummary').textContent =
        `TOTAL QTY ${d.totalQty}  •  AVG ₹${parseFloat(d.avgPrice).toFixed(2)}  •  COST ${STAS.rupee(d.totalInvestment)}`;
      document.getElementById('historyTableBody').innerHTML = d.transactions.map(t => `
        <tr>
          <td>${t.txDate}</td>
          <td><strong>${t.qty}</strong></td>
          <td><strong>₹${parseFloat(t.buyPrice).toFixed(2)}</strong></td>
          <td>${STAS.rupee(t.total)}</td>
          <td><span class="del-icon" onclick="STAS.deleteFromHistory('${t.id}','${d.symbol}','${portfolioId}')">🗑</span></td>
        </tr>
      `).join('');
    } else {
      STAS._historyModal.hide();
      STAS._historyPortfolioId = null;
    }
    STAS.loadDashboard();
  } else {
    iconEl.textContent = '🗑';
    STAS.toast(res.message,'error');
  }
};

// ============================================================
// 08-m1tracker.js
// ============================================================
STAS._m1Data = [];
STAS._m1Filtered = null;

STAS.loadM1Tracker = async function() {
  const res       = await STAS.api('getM1Tracker');
  const container = document.getElementById('m1Cards');
  if (res.status!=='ok') { container.innerHTML='<div class="text-center text-muted py-4">Error loading M1 cycles.</div>'; return; }
  if (!res.data.length)  { container.innerHTML='<div class="text-center text-muted py-4">No active M1 cycles. Add stocks to begin.</div>'; return; }
  STAS._m1Data = res.data;
  STAS._m1Filtered = null;
  STAS._pg.m1.page = 1;
  STAS._renderM1Page();
};

STAS._applyM1Filter = function() {
  const text = (document.getElementById('m1Search')?.value || '').trim().toUpperCase();
  let data = STAS._m1Data || [];
  if (text) data = data.filter(m => (m.symbol||'').toUpperCase().includes(text));
  STAS._m1Filtered = text ? data : null;
  const badge = document.getElementById('m1FilterBadge');
  if (badge) {
    if (text) { badge.textContent = data.length + ' cycles'; badge.style.display = ''; }
    else badge.style.display = 'none';
  }
  STAS._pg.m1.page = 1;
  STAS._renderM1Page();
};
STAS._clearM1Filter = function() {
  const el = document.getElementById('m1Search'); if (el) el.value = '';
  STAS._m1Filtered = null;
  const badge = document.getElementById('m1FilterBadge'); if (badge) badge.style.display='none';
  STAS._pg.m1.page = 1;
  STAS._renderM1Page();
};

STAS._m1CardHtml = function(m) {
    const statusClass = m.m1Activated ? (m.status==='triggered'?'triggered':'activated') : '';
    const dotClass    = m.m1Activated ? 'green' : 'amber';
    const progW       = m.activationProgress || 0;
    const progColor   = m.m1Activated ? '#27862A' : '#F59E0B';
    const cmp         = m.cmp ? STAS.rupee(m.cmp) : '—';
    const avg         = parseFloat(m.avgPrice||0);
    const sellTgt     = m.sellTarget||0;
    const m1Pct = m.m1Pct!==null ? parseFloat(m.m1Pct) : null;
    const m1PctHtml = m1Pct!==null
      ? `<span class="${m1Pct>=0?'green':'red'}">${m1Pct>=0?'▲':'▼'} ${Math.abs(m1Pct).toFixed(2)}%</span>`
      : '—';
    const m2gain = m.m2GainFromFirst!==null
      ? `<span class="green">+${parseFloat(m.m2GainFromFirst).toFixed(2)}%</span>` : '—';
    const avgHtml  = avg > 0 ? STAS.rupee(avg) : '<span style="color:var(--gray);font-size:11px;">Not set</span>';
    const sellHtml = (sellTgt > 0 && avg > 0) ? STAS.rupee(sellTgt) : '<span style="color:var(--gray);font-size:11px;">—</span>';
    return `
      <div class="m1-card ${statusClass}" id="m1card_${m.id}">
        <div class="m1-sym">
          <span><span class="dot ${dotClass}"></span> <strong>${m.symbol}</strong></span>
          <span style="font-size:11px;color:var(--gray);font-weight:400;">Cycle #${m.cycleNumber}</span>
        </div>
        <div class="m1-row"><span class="label">Base Buy</span><span class="val">${STAS.rupee(m.baseBuyPrice)}</span></div>
        <div class="m1-row">
          <span class="label">M1 Activation ${m.isEdited?'<span style="font-size:9px;color:var(--amber);">(edited)</span>':''}</span>
          <span class="val blue">${STAS.rupee(m.effectiveActivationPrice)}</span>
        </div>
        <div class="m1-row"><span class="label">M1 Buy signal at</span><span class="val green">${STAS.rupee(m.effectiveTriggerPrice)}</span></div>
        <div class="m1-row"><span class="label">M1 from CMP</span><span class="val">${m1PctHtml}</span></div>
        <div class="m1-row"><span class="label">Sell Target</span><span class="val red">${sellHtml}</span></div>
        <div class="m1-row"><span class="label">M2 High (trail)</span><span class="val amber">${STAS.rupee(m.m2HighestPrice)} ${m2gain}</span></div>
        <div class="m1-row"><span class="label">Avg Price</span><span class="val">${avgHtml}</span></div>
        <div class="m1-row"><span class="label">CMP</span><span class="val">${cmp}</span></div>
        <div style="margin:10px 0 4px;">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--gray);margin-bottom:3px;">
            <span>Base ₹${parseFloat(m.baseBuyPrice).toFixed(0)}</span>
            <span>M1 ${progW}% reached</span>
          </div>
          <div class="prog-bar"><div class="prog-fill" style="width:${progW}%;background:${progColor};"></div></div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px solid var(--border);">
          <span style="font-size:11px;">Status: <strong class="${dotClass}">${m.status.toUpperCase()}</strong></span>
          <span style="display:flex;gap:8px;font-size:11px;">
            <button class="btn-link" onclick="STAS.editM1Card('${m.portfolioId}','${m.id}',${m.effectiveActivationPrice},${m.effectiveTriggerPrice})">✏ Edit M1</button>
            <button class="btn-link" onclick="STAS.startEditM2('${m.portfolioId}',${m.m2HighestPrice||0})">✏ Edit M2</button>
            <button class="btn-link red" onclick="STAS.deleteM1Cycle('${m.id}','${m.symbol}')">🗑 Delete</button>
          </span>
        </div>
      </div>`;
};

STAS._renderM1Page = function() {
  const data  = STAS._m1Filtered !== null ? STAS._m1Filtered : (STAS._m1Data || []);
  const state = STAS._pg.m1;
  const container = document.getElementById('m1Cards');
  const slice = STAS.pageSlice(data, state);
  container.innerHTML = slice.map(m => STAS._m1CardHtml(m)).join('');
  STAS.renderPagination('m1Pagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.m1.page = val;
    if (type === 'size') { STAS._pg.m1.size = val; STAS._pg.m1.page = 1; }
    STAS._renderM1Page();
  });
};

STAS.editM1Card = function(portfolioId, m1Id, currentAct, currentTrig) {
  const newAct = prompt(`M1 Activation price (current: ₹${parseFloat(currentAct).toFixed(2)}):`, currentAct);
  if (!newAct || isNaN(parseFloat(newAct))) return;
  STAS.api('updateM1M2',{portfolioId,m1Id,field:'activation',value:parseFloat(newAct)},'POST').then(res => {
    if (res.status==='ok') {
      STAS.toast(`M1 updated. New trigger: ₹${res.data?.newTrigger||'—'}`);
      STAS.loadM1Tracker(); STAS.loadDashboard();
    } else STAS.toast(res.message,'error');
  });
};

STAS.deleteM1Cycle = async function(m1Id, symbol) {
  if (!confirm(`Delete this M1 cycle for ${symbol}?\n\nThis removes the cycle from tracker. The stock remains in your portfolio.`)) return;
  const res = await STAS.api('deleteM1Cycle',{m1Id},'POST');
  if (res.status==='ok') {
    STAS.toast(`${symbol} M1 cycle removed`);
    const card = document.getElementById(`m1card_${m1Id}`);
    if (card) { card.style.opacity='0.3'; setTimeout(()=>card.remove(),300); }
    setTimeout(()=>STAS.loadM1Tracker(), 400);
  } else STAS.toast(res.message,'error');
};

// ============================================================
// 09-settings.js
// ============================================================
STAS._settings = null;
STAS.loadSettings = async function() {
  const res = await STAS.api('getSettings');
  if (res.status!=='ok') return;
  const s = res.data; STAS._settings = s;
  document.getElementById('setActivation').value = s.activationPct;
  document.getElementById('setPullback').value   = s.pullbackPct;
  document.getElementById('setSell').value        = s.sellTargetPct;
  document.getElementById('setMaxBuy').value      = s.maxBuyCount;
  document.getElementById('stripActivation').textContent = s.activationPct+'%';
  document.getElementById('stripPullback').textContent   = s.pullbackPct+'%';
  document.getElementById('stripSell').textContent       = s.sellTargetPct+'%';
  document.getElementById('stripMaxBuy').textContent     = s.maxBuyCount;
};
STAS.saveSettings = async function() {
  const payload = {
    activationPct: parseFloat(document.getElementById('setActivation').value),
    pullbackPct:   parseFloat(document.getElementById('setPullback').value),
    sellTargetPct: parseFloat(document.getElementById('setSell').value),
    maxBuyCount:   parseInt(document.getElementById('setMaxBuy').value),
  };
  const msgEl = document.getElementById('settingsSaveMsg');
  const res   = await STAS.api('saveSettings',payload,'POST');
  if (res.status==='ok') {
    STAS.toast('Settings saved'); STAS.loadSettings();
    msgEl.className='alert alert-success py-2 px-3'; msgEl.textContent='✅ Settings saved'; msgEl.style.display='';
    setTimeout(()=>msgEl.style.display='none',3000);
  } else {
    msgEl.className='alert alert-danger py-2 px-3'; msgEl.textContent=res.message||'Error'; msgEl.style.display='';
  }
};

// ============================================================
// 10-admin.js
// ============================================================
STAS._usersData = [];
STAS._usersFiltered = null;

STAS.loadUsers = async function() {
  const res   = await STAS.api('getUsers');
  const tbody = document.getElementById('usersTableBody');
  if (res.status!=='ok') { tbody.innerHTML='<tr><td colspan="7" class="text-center text-muted py-4">Access denied.</td></tr>'; return; }
  STAS._usersData = res.data || [];
  STAS._usersFiltered = null;
  STAS._pg.usr.page = 1;
  STAS._renderUsersPage();
};

STAS._applyUsrFilter = function() {
  const text = (document.getElementById('usrSearch')?.value || '').trim().toLowerCase();
  let data = STAS._usersData || [];
  if (text) data = data.filter(u => (u.name||'').toLowerCase().includes(text) || (u.email||'').toLowerCase().includes(text));
  STAS._usersFiltered = text ? data : null;
  const badge = document.getElementById('usrFilterBadge');
  if (badge) {
    if (text) { badge.textContent = data.length + ' users'; badge.style.display = ''; }
    else badge.style.display = 'none';
  }
  STAS._pg.usr.page = 1;
  STAS._renderUsersPage();
};
STAS._clearUsrFilter = function() {
  const el = document.getElementById('usrSearch'); if (el) el.value = '';
  STAS._usersFiltered = null;
  const badge = document.getElementById('usrFilterBadge'); if (badge) badge.style.display='none';
  STAS._pg.usr.page = 1;
  STAS._renderUsersPage();
};

STAS._renderUsersPage = function() {
  const data  = STAS._usersFiltered !== null ? STAS._usersFiltered : (STAS._usersData || []);
  const state = STAS._pg.usr;
  const tbody = document.getElementById('usersTableBody');
  if (!data.length) {
    tbody.innerHTML='<tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>';
    const pg = document.getElementById('usersPagination'); if (pg) pg.innerHTML='';
    return;
  }
  const slice = STAS.pageSlice(data, state);
  tbody.innerHTML = slice.map(u => `
    <tr>
      <td><strong>${u.name}</strong></td>
      <td>${u.email}</td>
      <td><span class="${u.role==='superadmin'?'sig-sell':'sig-wait'}">${u.role}</span></td>
      <td>${u.lastLogin?STAS.dateStr(u.lastLogin):'Never'}</td>
      <td>${u.isActive?'<span class="green">Active</span>':'<span class="red">Inactive</span>'}</td>
      <td><span class="plan-free-badge">🆓 Free</span></td>
      <td>${u.role!=='superadmin'?`<button class="btn-link" onclick="STAS.toggleUser('${u.id}')">${u.isActive?'Deactivate':'Activate'}</button>`:'—'}</td>
    </tr>`).join('');
  STAS.renderPagination('usersPagination', state, data.length, function(type, val) {
    if (type === 'page') STAS._pg.usr.page = val;
    if (type === 'size') { STAS._pg.usr.size = val; STAS._pg.usr.page = 1; }
    STAS._renderUsersPage();
  });
};

STAS.toggleUser = async function(userId) {
  if (!confirm('Toggle user status?')) return;
  const res = await STAS.api('toggleUser',{userId},'POST');
  if (res.status==='ok') { STAS.toast('Updated'); STAS.loadUsers(); }
  else STAS.toast(res.message,'error');
};

// ============================================================
// 11-reports.js  (P&L Analytics — clean report)
// ============================================================
STAS._reportsData = [];
STAS._reportsFiltered = null;

STAS.loadReports = async function() {
  const res = await STAS.api('getPortfolio');
  const el  = document.getElementById('reportsBody');
  if (res.status!=='ok' || !res.data.length) {
    el.innerHTML='<div class="text-center text-muted py-4">No portfolio data.</div>'; return;
  }
  STAS._reportsData = res.data;
  STAS._reportsFiltered = null;
  const si = document.getElementById('repSearch'); if (si) si.value = '';
  STAS._pg.rep.page = 1;
  STAS._renderReportsPage();
};

STAS._applyRepFilter = function() {
  const text = (document.getElementById('repSearch')?.value || '').trim().toUpperCase();
  let data = STAS._reportsData || [];
  if (text) data = data.filter(r => (r.symbol||'').toUpperCase().includes(text));
  STAS._reportsFiltered = text ? data : null;
  const badge = document.getElementById('repFilterBadge');
  if (badge) {
    if (text) {
      const inv = data.reduce((a,r)=>a+parseFloat(r.totalInvestment||0),0);
      badge.textContent = data.length + ' stocks · ' + STAS.rupee(inv);
      badge.style.display = '';
    } else badge.style.display = 'none';
  }
  STAS._pg.rep.page = 1;
  STAS._renderReportsPage();
};
STAS._clearRepFilter = function() {
  const el = document.getElementById('repSearch'); if (el) el.value = '';
  STAS._reportsFiltered = null;
  const badge = document.getElementById('repFilterBadge'); if (badge) badge.style.display='none';
  STAS._pg.rep.page = 1;
  STAS._renderReportsPage();
};

STAS._renderReportsPage = function() {
  const rows  = STAS._reportsFiltered !== null ? STAS._reportsFiltered : (STAS._reportsData || []);
  const state = STAS._pg.rep;
  const s     = STAS._settings || { sellTargetPct:30 };
  const el    = document.getElementById('reportsBody');

  // Totals always from full dataset (not filtered)
  const allRows = STAS._reportsData || [];
  const ti  = allRows.reduce((a,r) => a + parseFloat(r.totalInvestment || 0), 0);
  const tc  = allRows.reduce((a,r) => a + (parseFloat(r.cmp||0) * parseInt(r.totalQty||0)), 0);
  const tpl = tc - ti;
  const tplPct = ti > 0 ? ((tpl / ti) * 100) : 0;
  const gainers = allRows.filter(r => {
    const pl = (parseFloat(r.cmp||0) * parseInt(r.totalQty||0)) - parseFloat(r.totalInvestment||0);
    return pl > 0;
  }).length;
  const losers = allRows.length - gainers;

  const slice = STAS.pageSlice(rows, state);

  el.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;">
      <div class="metric-card"><div class="metric-label">Total Invested</div><div class="metric-value">${STAS.rupee(ti)}</div></div>
      <div class="metric-card"><div class="metric-label">Current Value</div><div class="metric-value">${STAS.rupee(tc)}</div></div>
      <div class="metric-card"><div class="metric-label">Unrealised P&amp;L</div>
        <div class="metric-value ${tpl>=0?'green':'red'}">${STAS.rupee(tpl)}</div>
        <div class="metric-sub ${tpl>=0?'green':'red'}">${tplPct>=0?'+':''}${tplPct.toFixed(2)}%</div>
      </div>
      <div class="metric-card"><div class="metric-label">Stocks</div>
        <div class="metric-value">${rows.length}</div>
        <div class="metric-sub"><span class="green">${gainers} ▲</span> &nbsp; <span class="red">${losers} ▼</span></div>
      </div>
    </div>
    <div class="table-wrap">
    <table class="stas-table">
      <thead><tr>
        <th>Stock</th><th>Qty</th><th>Avg Rate</th><th>Invested</th>
        <th>CMP</th><th>Value</th><th>P&amp;L ₹</th><th>P&amp;L %</th>
        <th>Sell Target</th><th>M2 High</th><th>Status</th>
      </tr></thead>
      <tbody>
        ${slice.map(r => {
          const cmp = parseFloat(r.cmp || 0);
          const qty = parseInt(r.totalQty || 0);
          const inv = parseFloat(r.totalInvestment || 0);
          const val = cmp * qty;
          const pl  = val - inv;
          const pp  = inv > 0 ? (pl / inv) * 100 : 0;
          const st  = parseFloat(r.avgPrice || 0) * (1 + parseFloat(s.sellTargetPct) / 100);
          const atTarget = cmp >= st;
          return `<tr>
            <td><strong>${r.symbol}</strong><br><span style="font-size:10px;color:var(--gray);">${r.exchange}</span></td>
            <td>${qty}</td>
            <td>${STAS.rupee(r.avgPrice)}</td>
            <td>${STAS.rupee(inv)}</td>
            <td>${cmp ? STAS.rupee(cmp) : '—'}</td>
            <td>${val ? STAS.rupee(val) : '—'}</td>
            <td class="${pl >= 0 ? 'green' : 'red'}">${pl ? STAS.rupee(pl) : '—'}</td>
            <td class="${pp >= 0 ? 'green' : 'red'}">${pp ? (pp>0?'+':'') + pp.toFixed(2)+'%' : '—'}</td>
            <td class="${atTarget ? 'green' : ''}" style="font-weight:${atTarget?700:400};">${STAS.rupee(st)}</td>
            <td class="amber">${r.m2HighestPrice ? STAS.rupee(r.m2HighestPrice) : '—'}</td>
            <td>${atTarget ? '<span class="sig-sell">SELL</span>' : (pp >= 0 ? '<span class="sig-wait">HOLD</span>' : '<span class="sig-none">LOSS</span>')}</td>
          </tr>`;
        }).join('')}
      </tbody>
      <tfoot>
        <tr style="font-weight:600;background:var(--bg);">
          ${(() => {
            const fti = rows.reduce((a,r)=>a+parseFloat(r.totalInvestment||0),0);
            const ftc = rows.reduce((a,r)=>a+(parseFloat(r.cmp||0)*parseInt(r.totalQty||0)),0);
            const fpl = ftc-fti; const fpct = fti>0?(fpl/fti*100):0;
            return `<td>TOTAL (${rows.length})</td><td>—</td><td>—</td>
            <td>${STAS.rupee(fti)}</td><td>—</td>
            <td>${STAS.rupee(ftc)}</td>
            <td class="${fpl>=0?'green':'red'}">${STAS.rupee(fpl)}</td>
            <td class="${fpl>=0?'green':'red'}">${fpct>=0?'+':''}${fpct.toFixed(2)}%</td>
            <td colspan="3"></td>`;
          })()}
        </tr>
      </tfoot>
    </table>
    </div>`;

  STAS.renderPagination('reportsPagination', state, rows.length, function(type, val) {
    if (type === 'page') STAS._pg.rep.page = val;
    if (type === 'size') { STAS._pg.rep.size = val; STAS._pg.rep.page = 1; }
    STAS._renderReportsPage();
  });
};

// ── BOOT ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{ STAS.checkSession(); });
