<?php
session_start();
if (empty($_SESSION['userId'])) { header('Location: login.php'); exit; }
?><!DOCTYPE html>
<html lang="en">
<head>
    
   
  <link rel="manifest" href="manifest.json">
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('./sw.js');
    }
  </script>



<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>STAS — Smart Trailing Accumulation System</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div id="sidebar" class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">📈</div>
    <div><div class="logo-name">STAS</div><div class="logo-sub">Smart Trailing System</div></div>
  </div>
  <div class="nav-group">
    <div class="nav-section-label">MAIN</div>
    <a class="nav-link active" href="#" data-page="dashboard"><span class="nav-icon">📊</span> Dashboard</a>
    <a class="nav-link" href="#" data-page="portfolio"><span class="nav-icon">💼</span> Portfolio <span class="nav-badge" id="navBuyBadge" style="display:none;"></span></a>
    <a class="nav-link" href="#" data-page="signals"><span class="nav-icon">🔔</span> Signals <span class="nav-badge red" id="navSignalBadge" style="display:none;"></span></a>
    <a class="nav-link" href="#" data-page="transactions"><span class="nav-icon">🔄</span> Transactions</a>
  </div>
  <div class="nav-group">
    <div class="nav-section-label">ANALYSIS</div>
    <a class="nav-link" href="#" data-page="m1tracker"><span class="nav-icon">🎯</span> M1 Tracker</a>
    <a class="nav-link" href="#" data-page="reports"><span class="nav-icon">📋</span> Reports</a>
  </div>
  <div class="nav-group">
    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-link" href="#" data-page="settings"><span class="nav-icon">⚙️</span> Strategy Settings</a>
    <a class="nav-link superadmin-only" href="#" data-page="admin" style="display:none;"><span class="nav-icon">👥</span> Users</a>
  </div>
  <div class="sidebar-footer">
    <div style="padding:6px 12px 0;text-align:center;">
      <span style="background:#e8f5e9;color:#2e7d32;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;border:1px solid #a5d6a7;">🆓 FREE PLAN</span>
    </div>
    <div class="user-chip">
      <div class="user-avatar" id="sidebarAvatar"><?= strtoupper(substr($_SESSION['userName']??'U',0,1)) ?></div>
      <div class="user-info">
        <div class="user-name" id="sidebarName"><?= htmlspecialchars($_SESSION['userName']??'') ?></div>
        <div class="user-role" id="sidebarRole"><?= htmlspecialchars($_SESSION['userRole']??'') ?></div>
      </div>
      <span class="logout-btn" onclick="STAS.logout()">↩</span>
    </div>
  </div>
</div>

<div id="mainContent" class="main-content">
  <div class="topbar">
    <div class="topbar-left">
      <button class="btn-hamburger d-lg-none" onclick="STAS.toggleSidebar()">☰</button>
      <div>
        <div class="topbar-title" id="pageTitle">Dashboard</div>
        <div class="topbar-sub" id="pageSubtitle">Live market · NSE</div>
      </div>
    </div>
    <div class="topbar-right">
      <span class="price-time" id="lastRefreshTime"></span>
      <button class="btn-outline-sm" id="btnRefresh" onclick="STAS.refreshPrices()">🔄 Refresh</button>
      <button class="btn-primary-sm" onclick="STAS.openAddStockModal()">+ Add Stock</button>
    </div>
  </div>

  <!-- DASHBOARD PAGE -->
  <div class="page active" id="page-dashboard">
    <div class="settings-strip">
      <div class="settings-chip"><span class="chip-label">Activation</span><span class="chip-val blue" id="stripActivation">10%</span></div>
      <div class="settings-chip"><span class="chip-label">Pullback</span><span class="chip-val amber" id="stripPullback">5%</span></div>
      <div class="settings-chip"><span class="chip-label">Sell Target</span><span class="chip-val green" id="stripSell">30%</span></div>
      <div class="settings-chip"><span class="chip-label">Drop-20 Signal</span><span class="chip-val red">ON</span></div>
      <div class="settings-chip"><span class="chip-label">Max Buys</span><span class="chip-val" id="stripMaxBuy">5</span></div>
    </div>
    <div class="metrics-grid">
      <div class="metric-card"><div class="metric-label">Total Investment</div><div class="metric-value" id="mTotalInvested">Loading...</div><div class="metric-sub" id="mStockCount"></div></div>
      <div class="metric-card"><div class="metric-label">Current Value</div><div class="metric-value" id="mCurrentValue">—</div><div class="metric-sub" id="mPL"></div></div>
      <div class="metric-card"><div class="metric-label">Active Signals</div><div class="metric-value red" id="mSignals">—</div><div class="metric-sub" id="mSignalBreakdown"></div></div>
      <div class="metric-card"><div class="metric-label">Overall P&amp;L</div><div class="metric-value" id="mPLPct">—</div><div class="metric-sub" id="mPLAmt"></div></div>
    </div>
    <div class="two-col-layout">
      <div class="card-panel">
        <div class="panel-header">
          <span>💼 Portfolio Holdings <small style="font-weight:400;font-size:11px;color:var(--gray);">· Click stock name to see buy history</small></span>
          <div id="portfolioSignalBadges"></div>
        </div>
        <div class="filter-bar">
          <input type="text" class="filt-input" id="dashSearch" placeholder="🔍 Search symbol..." oninput="STAS._applyDashFilter()">
          <button class="filt-clear" onclick="STAS._clearDashFilter()">✕ Clear</button>
          <span class="filt-badge" id="dashFilterBadge" style="display:none;"></span>
        </div>
        <div class="panel-body p-0">
          <!-- Desktop table — hidden on mobile -->
          <div class="d-none d-md-block">
          <div class="table-wrap">
            <table class="stas-table">
              <thead>
                <tr>
                                    <th>#</th>
                  <th>STOCK</th>
                  <th>QTY</th>
                  <th>AV.RATE / AV.%</th>
                  <th>BUY RATE / BR%</th>
                  <th>MAX1 / M1%</th>
                  <th>MAX2 / M2%</th>
                  <th>RATE / CHG%</th>
                  <th>SIGNAL</th>
                  <th>P&amp;L</th>
                  <th>INVESTED</th>
                  <th>MKT VALUE</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="portfolioTableBody">
                <tr><td colspan="13" class="text-center text-muted py-4">Loading...</td></tr>
              </tbody>
            </table>
          </div>
          </div><!-- end d-none d-md-block -->
        </div>
        <!-- Mobile Cards — shown only on mobile -->
        <div id="portfolioMobileCards" class="d-md-none px-2 py-2"></div>
        <!-- Pagination -->
        <div id="dashPagination" class="px-3 pb-2"></div>
      </div>
      <div class="card-panel signals-panel">
        <div class="panel-header">
          <span>🔔 Active Signals</span>
          <span class="badge-red" id="signalPanelCount" style="display:none;"></span>
        </div>
        <div class="panel-body p-0" id="signalsList"><div class="text-center text-muted py-4" style="font-size:13px;">Loading...</div></div>
        <div class="panel-footer"><button class="btn-link" onclick="STAS.markAllRead()">Mark all as read</button></div>
      </div>
    </div>
  </div>

  <!-- PORTFOLIO PAGE -->
  <div class="page" id="page-portfolio">
    <div class="card-panel">
      <div class="panel-header"><span>💼 All Holdings</span><button class="btn-primary-sm" onclick="STAS.openAddStockModal()">+ Add Stock</button></div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="portSearch" placeholder="🔍 Search symbol..." oninput="STAS._applyPortFilter()">
        <button class="filt-clear" onclick="STAS._clearPortFilter()">✕ Clear</button>
        <span class="filt-badge" id="portFilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body p-0">
        <!-- Desktop table — hidden on mobile -->
        <div class="d-none d-md-block">
        <div class="table-wrap">
          <table class="stas-table">
            <thead><tr>                  <th>#</th>
                  <th>STOCK</th>
                  <th>QTY</th>
                  <th>AV.RATE / AV.%</th>
                  <th>BUY RATE / BR%</th>
                  <th>MAX1 / M1%</th>
                  <th>MAX2 / M2%</th>
                  <th>RATE / CHG%</th>
                  <th>SIGNAL</th>
                  <th>P&amp;L</th>
                  <th>INVESTED</th>
                  <th>MKT VALUE</th>
                  <th></th></tr></thead>
            <tbody id="fullPortfolioBody"><tr><td colspan="13" class="text-center text-muted py-4">Loading...</td></tr></tbody>
          </table>
        </div>
        </div><!-- end d-none d-md-block -->
        <!-- Mobile Cards — shown only on mobile -->
        <div id="fullMobileCards" class="d-md-none px-2 py-2"></div>
        <!-- Pagination -->
        <div id="portPagination" class="px-3 pb-2"></div>
      </div>
    </div>
  </div>

  <!-- SIGNALS PAGE -->
  <div class="page" id="page-signals">
    <div class="card-panel">
      <div class="panel-header"><span>🔔 All Signals</span><div style="display:flex;gap:8px;"><select id="signalFilter" class="form-select form-select-sm" style="width:130px;" onchange="STAS._applySigFilter()"><option value="">All Types</option><option value="BUY">BUY only</option><option value="SELL">SELL only</option></select><button class="btn-outline-sm" onclick="STAS.markAllRead()">Mark all read</button></div></div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="sigSearch" placeholder="🔍 Search symbol..." oninput="STAS._applySigFilter()">
        <span class="filt-lbl">From</span>
        <input type="date" class="filt-date" id="sigFrom" onchange="STAS._applySigFilter()">
        <span class="filt-lbl">To</span>
        <input type="date" class="filt-date" id="sigTo" onchange="STAS._applySigFilter()">
        <button class="filt-clear" onclick="STAS._clearSigFilter()">✕ Clear</button>
        <span class="filt-badge" id="sigFilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body p-0" id="allSignalsList"><div class="text-center py-4 text-muted">Loading...</div></div>
      <div id="signalsPagination" class="px-3 pb-2"></div>
    </div>
  </div>

  <!-- TRANSACTIONS PAGE -->
  <div class="page" id="page-transactions">
    <div class="card-panel">
      <div class="panel-header"><span>🔄 Transaction History</span></div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="txSearch" placeholder="🔍 Search symbol..." oninput="STAS._applyTxFilter()">
        <span class="filt-lbl">From</span>
        <input type="date" class="filt-date" id="txFrom" onchange="STAS._applyTxFilter()">
        <span class="filt-lbl">To</span>
        <input type="date" class="filt-date" id="txTo" onchange="STAS._applyTxFilter()">
        <button class="filt-clear" onclick="STAS._clearTxFilter()">✕ Clear</button>
        <span class="filt-badge" id="txFilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body p-0"><div class="table-wrap">
        <table class="stas-table"><thead><tr><th>Date</th><th>Stock</th><th>Buy #</th><th>Price</th><th>Qty</th><th>Amount</th><th>Notes</th><th></th></tr></thead>
        <tbody id="txTableBody"><tr><td colspan="8" class="text-center text-muted py-4">Loading...</td></tr></tbody></table>
      </div></div>
      <div id="txPagination" class="px-3 pb-2"></div>
    </div>
  </div>

  <!-- M1 TRACKER PAGE -->
  <div class="page" id="page-m1tracker">
    <div class="card-panel">
      <div class="panel-header"><span>🎯 M1 Cycle Tracker</span><div class="m1-legend"><span class="dot green"></span>Active &nbsp;<span class="dot amber"></span>Waiting &nbsp;<span class="dot gray"></span>Not yet</div></div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="m1Search" placeholder="🔍 Search symbol..." oninput="STAS._applyM1Filter()">
        <button class="filt-clear" onclick="STAS._clearM1Filter()">✕ Clear</button>
        <span class="filt-badge" id="m1FilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body"><div id="m1Cards" class="m1-grid"><div class="text-center text-muted py-4">Loading M1 cycles...</div></div></div>
      <div id="m1Pagination" class="px-3 pb-2"></div>
    </div>
  </div>

  <!-- REPORTS PAGE -->
  <div class="page" id="page-reports">
    <div class="card-panel">
      <div class="panel-header">
        <span>📋 P&amp;L Analytics Report</span>
        <button class="btn-outline-sm" onclick="STAS.loadReports()">🔄 Refresh</button>
      </div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="repSearch" placeholder="🔍 Search symbol..." oninput="STAS._applyRepFilter()">
        <button class="filt-clear" onclick="STAS._clearRepFilter()">✕ Clear</button>
        <span class="filt-badge" id="repFilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body" id="reportsBody"><div class="text-center text-muted py-4">Loading...</div></div>
      <div id="reportsPagination" class="px-3 pb-2"></div>
    </div>
  </div>

  <!-- SETTINGS PAGE -->
  <div class="page" id="page-settings">
    <div class="card-panel" style="max-width:520px;">
      <div class="panel-header"><span>⚙️ Strategy Settings</span></div>
      <div class="panel-body">
        <div class="alert alert-info py-2 px-3 mb-3" style="font-size:13px;">All percentages are configurable. Changes apply to future signal checks.</div>
        <div id="settingsSaveMsg" style="display:none;"></div>
        <div class="setting-row"><div class="setting-label"><strong>Activation % (M1 trigger)</strong><small>Stock must rise this % from buy price before M1 activates.</small></div><div class="setting-input"><input type="number" id="setActivation" class="form-control" min="1" max="50" step="0.5"><span class="input-unit">%</span></div></div>
        <div class="setting-row"><div class="setting-label"><strong>Pullback % (M1 buy signal)</strong><small>After M1 activates, price must fall this % to generate BUY.</small></div><div class="setting-input"><input type="number" id="setPullback" class="form-control" min="1" max="20" step="0.5"><span class="input-unit">%</span></div></div>
        <div class="setting-row"><div class="setting-label"><strong>Sell Target %</strong><small>SELL signal when price is this % above average cost.</small></div><div class="setting-input"><input type="number" id="setSell" class="form-control" min="5" max="200" step="1"><span class="input-unit">%</span></div></div>
        <div class="setting-row"><div class="setting-label"><strong>Max Buy Count</strong><small>Maximum accumulation buys per stock.</small></div><div class="setting-input"><input type="number" id="setMaxBuy" class="form-control" min="1" max="10" step="1"><span class="input-unit">buys</span></div></div>
        <div class="setting-row"><div class="setting-label"><strong>20% Drop Signal</strong><small>BUY signal when price drops 20% below last buy rate. Always ON.</small></div><div class="setting-input"><span style="font-size:13px;font-weight:600;color:var(--green);">✅ Enabled</span></div></div>
        <button class="btn-primary-full mt-3" onclick="STAS.saveSettings()">💾 Save Settings</button>
      </div>
    </div>
  </div>

  <!-- ADMIN PAGE -->
  <div class="page" id="page-admin">
    <div class="card-panel">
      <div class="panel-header"><span>👥 User Management</span></div>
      <div class="filter-bar">
        <input type="text" class="filt-input" id="usrSearch" placeholder="🔍 Search name or email..." oninput="STAS._applyUsrFilter()">
        <button class="filt-clear" onclick="STAS._clearUsrFilter()">✕ Clear</button>
        <span class="filt-badge" id="usrFilterBadge" style="display:none;"></span>
      </div>
      <div class="panel-body p-0"><div class="table-wrap"><table class="stas-table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Last Login</th><th>Status</th><th>Plan</th><th>Action</th></tr></thead><tbody id="usersTableBody"><tr><td colspan="7" class="text-center text-muted py-4">Loading...</td></tr></tbody></table></div></div>
      <div id="usersPagination" class="px-3 pb-2"></div>
    </div>
  </div>
</div>

<!-- ADD STOCK MODAL -->
<div class="modal fade" id="addStockModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Buy Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="addStockError" class="alert alert-danger py-2 px-3" style="display:none;font-size:13px;"></div>
        <div class="row g-3">
          <div class="col-7">
            <label class="form-label">Stock Symbol <span class="text-danger">*</span></label>
            <div class="stock-search-wrap">
              <input type="text" id="addSymbol" class="form-control text-uppercase" placeholder="Type to search NSE stocks..." oninput="STAS.onSymbolInput()" autocomplete="off">
              <div id="stockDropdown" class="stock-dropdown" style="display:none;"></div>
            </div>
          </div>
          <div class="col-5">
            <label class="form-label">Exchange</label>
            <select id="addExchange" class="form-select"><option value="NSE">NSE</option><option value="BSE">BSE</option></select>
          </div>
          <div class="col-6"><label class="form-label">Buy Price (₹) <span class="text-danger">*</span></label><input type="number" id="addPrice" class="form-control" placeholder="e.g. 1420.50" step="0.01" min="0.01" oninput="STAS.updateAddPreview()"></div>
          <div class="col-6"><label class="form-label">Quantity <span class="text-danger">*</span></label><input type="number" id="addQty" class="form-control" placeholder="e.g. 10" min="1" oninput="STAS.updateAddPreview()"></div>
          <div class="col-6"><label class="form-label">Buy Date</label><input type="date" id="addDate" class="form-control"></div>
          <div class="col-6"><label class="form-label">Notes (optional)</label><input type="text" id="addNotes" class="form-control" placeholder="Optional"></div>
        </div>
        <div id="avgPreview" style="display:none;margin-top:14px;background:#f0f5ff;border-radius:8px;padding:12px 14px;font-size:12px;">
          <strong>After this buy:</strong>
          <div class="row mt-2 g-1">
            <div class="col-6">New Average: <strong id="previewAvg">—</strong></div>
            <div class="col-6">Total Qty: <strong id="previewQty">—</strong></div>
            <div class="col-6">M1 Level: <strong id="previewM1" class="blue">—</strong></div>
            <div class="col-6">M1 Buy signal: <strong id="previewTrigger" class="green">—</strong></div>
            <div class="col-12">20% Drop signal at: <strong id="previewDrop20" class="red">—</strong> <span style="color:var(--gray);font-size:10px;">(buy if price falls here)</span></div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="addStockSubmitBtn" onclick="STAS.submitAddStock()">Record Buy &amp; Start M1 Cycle</button>
      </div>
    </div>
  </div>
</div>

<!-- BUY HISTORY MODAL -->
<div class="modal fade" id="buyHistoryModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="border:none;border-radius:10px;overflow:hidden;">
      <div class="history-header">
        <span class="history-close" data-bs-dismiss="modal">✕</span>
        <div class="history-title" id="historyTitle">BUY HISTORY</div>
      </div>
      <div class="history-summary" id="historySummary"></div>
      <div style="padding:0;">
        <table class="history-table" id="historyTable">
          <thead><tr><th>DATE</th><th>QTY</th><th>RATE</th><th>TOTAL</th><th></th></tr></thead>
          <tbody id="historyTableBody"></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
window.STAS_SESSION = {
  userId:    '<?= addslashes($_SESSION['userId']   ?? '') ?>',
  userName:  '<?= addslashes($_SESSION['userName']  ?? '') ?>',
  userEmail: '<?= addslashes($_SESSION['userEmail'] ?? '') ?>',
  userRole:  '<?= addslashes($_SESSION['userRole']  ?? '') ?>'
};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/01-config.js"></script>
<script src="js/02-05-modules.js"></script>
<script src="js/06-10-modules.js"></script>
</body>
</html>
