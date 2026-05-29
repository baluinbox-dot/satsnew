// ============================================================
// PATCH for js/02-05-modules.js
// Replace ONLY the onSymbolInput, fetchStockSearch, selectStock functions
// ============================================================

STAS.onSymbolInput = function() {
  const q   = document.getElementById('addSymbol').value.trim();
  const drop = document.getElementById('stockDropdown');

  clearTimeout(STAS._searchTimer);

  if (q.length < 1) { drop.style.display = 'none'; return; }

  // After 2 chars start searching
  if (q.length >= 2) {
    STAS._searchTimer = setTimeout(() => STAS.fetchStockSearch(q), 400);
  }

  STAS.updateAddPreview();
};

STAS.fetchStockSearch = async function(q) {
  const drop = document.getElementById('stockDropdown');
  drop.innerHTML = '<div class="search-loading">🔍 Searching...</div>';
  drop.style.display = '';

  try {
    const res  = await fetch(`api/index.php?action=searchStock&q=${encodeURIComponent(q.toUpperCase())}`);
    const data = await res.json();

    if (data.status !== 'ok') {
      drop.innerHTML = '<div class="search-loading" style="color:var(--red);">Search error. Type symbol manually.</div>';
      return;
    }

    const results = data.data?.results || [];
    const source  = data.data?.source  || 'yahoo';
    const total   = data.data?.total   || 0;

    if (!results.length) {
      // Show "use as typed" option
      const sym = q.toUpperCase();
      drop.innerHTML = `
        <div class="stock-option" onclick="STAS.selectStock('${sym}','NSE')">
          <div>
            <div class="stock-option-sym">${sym}</div>
            <div class="stock-option-name">Use "${sym}" as typed</div>
          </div>
          <span class="stock-option-exch">NSE</span>
        </div>
        <div style="padding:7px 13px;font-size:10px;color:var(--gray);border-top:1px solid var(--border);">
          No match found. Import NSE stocks via <a href="import_stocks.php" target="_blank">import_stocks.php</a>
        </div>`;
      return;
    }

    const sourceTag = source === 'local'
      ? `<span style="font-size:10px;color:var(--green);">📦 ${total.toLocaleString()} stocks in DB</span>`
      : `<span style="font-size:10px;color:var(--amber);">⚠️ Live search (import stocks for faster results)</span>`;

    drop.innerHTML = results.map(r => `
      <div class="stock-option" onclick="STAS.selectStock('${r.symbol}','${r.exchange}')">
        <div>
          <div class="stock-option-sym">${r.symbol}</div>
          <div class="stock-option-name">${r.name || r.symbol}</div>
        </div>
        <span class="stock-option-exch">${r.exchange}</span>
      </div>
    `).join('') + `<div style="padding:6px 13px;border-top:1px solid var(--border);">${sourceTag}</div>`;

  } catch(e) {
    const sym = q.toUpperCase();
    drop.innerHTML = `
      <div class="stock-option" onclick="STAS.selectStock('${sym}','NSE')">
        <div class="stock-option-sym">${sym}</div>
        <div class="stock-option-name">Use "${sym}" as typed</div>
      </div>`;
  }
};

STAS.selectStock = function(symbol, exchange) {
  document.getElementById('addSymbol').value   = symbol;
  document.getElementById('addExchange').value = exchange || 'NSE';
  document.getElementById('stockDropdown').style.display = 'none';
  document.getElementById('addPrice').focus();
  STAS.updateAddPreview();
};
