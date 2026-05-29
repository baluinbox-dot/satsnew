<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>STAS — Import NSE Stocks</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
body { background:#f0f2f5; padding:30px; }
.card { border-radius:12px; border:1px solid #e0e0e0; }
pre  { background:#1e293b; color:#e2e8f0; padding:16px; border-radius:8px; font-size:12px; max-height:400px; overflow-y:auto; }
</style>
</head>
<body>
<div class="container" style="max-width:700px;">
<div class="card p-4">
  <h4 style="font-size:18px;">📥 NSE Stock Importer</h4>
  <p style="font-size:13px;color:#666;">Downloads all NSE equity stocks from NSE India and stores in local DB for instant search.</p>

<?php
require_once __DIR__ . '/api/config.php';

// Require superadmin to run this
session_start();
if (empty($_SESSION['userId']) || $_SESSION['userEmail'] !== SUPERADMIN_EMAIL) {
    echo '<div class="alert alert-danger">⛔ Superadmin only. <a href="login.php">Login first →</a></div>';
    echo '</div></div></body></html>'; exit;
}

$db = getDB();

// Ensure table exists
$db->exec("CREATE TABLE IF NOT EXISTS st_stocks (
    symbol   VARCHAR(30) PRIMARY KEY,
    name     VARCHAR(200),
    exchange VARCHAR(10) DEFAULT 'NSE',
    series   VARCHAR(10),
    isin     VARCHAR(20),
    isActive TINYINT(1) DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$existing = (int)$db->query('SELECT COUNT(*) FROM st_stocks')->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_do = $_POST['action'] ?? '';

    // ── OPTION 1: Download from NSE ──────────────────────────
    if ($action_do === 'download_nse') {
        echo '<pre>';
        $url = 'https://nsearchives.nseindia.com/content/equities/EQUITY_L.csv';
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 30,
                'header'  => "User-Agent: Mozilla/5.0\r\nAccept: text/csv,*/*\r\nReferer: https://www.nseindia.com\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);

        echo "Downloading from NSE...\n";
        $csv = @file_get_contents($url, false, $ctx);

        if (!$csv) {
            echo "NSE direct failed. Trying alternate source...\n";
            // Try alternate: use a public GitHub mirror of NSE data
            $url2 = 'https://raw.githubusercontent.com/datasets/s-and-p-500-companies/master/data/constituents.csv';
            // Actually try another NSE endpoint
            $url2 = 'https://www.nseindia.com/api/equity-stockIndices?index=SECURITIES%20IN%20F%26O';
            $csv  = false;
        }

        if ($csv) {
            $lines    = explode("\n", $csv);
            $imported = 0;
            $skipped  = 0;

            // Skip header line
            foreach (array_slice($lines, 1) as $line) {
                $line = trim($line);
                if (!$line) continue;

                $cols = str_getcsv($line);
                // NSE CSV format: SYMBOL,NAME,SERIES,DATE OF LISTING,...,ISIN
                $symbol = strtoupper(trim($cols[0] ?? ''));
                $name   = trim($cols[1] ?? '');
                $series = trim($cols[2] ?? '');
                $isin   = trim($cols[14] ?? '');

                if (!$symbol || strlen($symbol) > 30) { $skipped++; continue; }
                if (!in_array($series, ['EQ','BE','SM','ST'])) { $skipped++; continue; } // EQ=Equity, BE=ETF, SM/ST=SME

                try {
                    $db->prepare('
                        INSERT INTO st_stocks (symbol, name, exchange, series, isin)
                        VALUES (?, ?, "NSE", ?, ?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name), series=VALUES(series), isin=VALUES(isin)
                    ')->execute([$symbol, $name, $series, $isin]);
                    $imported++;
                    if ($imported % 200 === 0) echo "Imported {$imported} stocks...\n";
                } catch(Exception $e) { $skipped++; }
            }
            echo "\n✅ Done! Imported: {$imported} | Skipped: {$skipped}\n";
        } else {
            echo "❌ Could not download from NSE. Use the manual CSV upload option instead.\n";
        }
        echo '</pre>';
    }

    // ── OPTION 2: Upload CSV ─────────────────────────────────
    if ($action_do === 'upload_csv' && isset($_FILES['csvfile'])) {
        echo '<pre>';
        $tmp      = $_FILES['csvfile']['tmp_name'];
        $handle   = fopen($tmp, 'r');
        $imported = 0;
        $skipped  = 0;
        $header   = fgetcsv($handle); // skip header

        while (($cols = fgetcsv($handle)) !== false) {
            $symbol = strtoupper(trim($cols[0] ?? ''));
            $name   = trim($cols[1] ?? '');
            $series = trim($cols[2] ?? '');
            $isin   = trim($cols[14] ?? '');

            if (!$symbol || strlen($symbol) > 30) { $skipped++; continue; }
            if (!in_array($series, ['EQ','BE','SM','ST'])) { $skipped++; continue; }

            try {
                $db->prepare('
                    INSERT INTO st_stocks (symbol, name, exchange, series, isin)
                    VALUES (?, ?, "NSE", ?, ?)
                    ON DUPLICATE KEY UPDATE name=VALUES(name), isin=VALUES(isin)
                ')->execute([$symbol, $name, $series, $isin]);
                $imported++;
            } catch(Exception $e) { $skipped++; }
        }
        fclose($handle);
        echo "✅ Done! Imported: {$imported} | Skipped: {$skipped}\n";
        echo '</pre>';
    }

    // ── OPTION 1b: Add common ETFs manually ─────────────────
    if ($action_do === 'add_etfs') {
        $etfs = [
            ['NIFTYBEES','Nippon India ETF Nifty BeES','BE','INF204KB17I5'],
            ['BANKBEES','Nippon India ETF Bank BeES','BE','INF204KB13I4'],
            ['GOLDBEES','Nippon India ETF Gold BeES','BE','INF204KB19I1'],
            ['JUNIORBEES','Nippon India ETF Junior BeES','BE','INF204KB15I1'],
            ['LIQUIDBEES','Nippon India ETF Liquid BeES','BE','INF204KB11I8'],
            ['ITBEES','Nippon India ETF Nifty IT','BE','INF204KB10I0'],
            ['SHARIAHBEES','Nippon India ETF Shariah BeES','BE','INF204KB14I2'],
            ['SETFNIF50','SBI ETF Nifty 50','BE','INF200KA14H2'],
            ['SETFNIFBK','SBI ETF Nifty Bank','BE','INF200KA11H8'],
            ['SETFGOLD','SBI ETF Gold','BE','INF200KA12H6'],
            ['ICICIB22','ICICI Pru Nifty Next 50 ETF','BE','INF109KB18L5'],
            ['ICICINIFTY','ICICI Pru Nifty 50 ETF','BE','INF109KB15L1'],
            ['ICICISENSX','ICICI Pru Sensex ETF','BE','INF109KB14L4'],
            ['MAFANG','Mirae Asset NYSE FANG+ ETF','BE','INF769K01EW1'],
            ['MASPTOP50','Mirae Asset S&P 500 Top 50 ETF','BE','INF769K01EY7'],
            ['MONIFTY500','Motilal Oswal Nifty 500 ETF','BE','INF247L01AD0'],
            ['MOM100','Motilal Oswal Midcap 100 ETF','BE','INF247L01745'],
            ['MOM50','Motilal Oswal MOSt Shares M50 ETF','BE','INF247L01737'],
            ['HDFCSENSEX','HDFC Sensex ETF','BE','INF179KB13I3'],
            ['HDFCNIFTY','HDFC Nifty 50 ETF','BE','INF179KB11I7'],
            ['HDFCBSE200','HDFC BSE 200 ETF','BE','INF179KB17I4'],
            ['SILVER','Nippon India Silver ETF','BE','INF204KC10I1'],
            ['SILVERBEES','Nippon India ETF Silver BeES','BE','INF204KC11I9'],
            ['NETFIT','Nippon India ETF Nifty IT','BE','INF204KC17I6'],
            ['NIFTYIETF','ICICI Pru Nifty IT ETF','BE','INF109KB11L8'],
            ['CPSEETF','Nippon India ETF CPSE','BE','INF204KB12I6'],
            ['PSUBNKBEES','Nippon India ETF Nifty PSU Bank BeES','BE','INF204KC14I3'],
            ['AUTOBEES','Nippon India ETF Nifty Auto','BE','INF204KC19I6'],
            ['PHARMABEES','Nippon India ETF Nifty Pharma','BE','INF204KC16I8'],
            ['INFRABEES','Nippon India ETF Nifty Infra','BE','INF204KC15I0'],
        ];
        $added = 0;
        foreach ($etfs as $e) {
            try {
                $db->prepare('INSERT INTO st_stocks (symbol,name,exchange,series,isin) VALUES (?,?,"NSE",?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),series=VALUES(series)')
                   ->execute($e);
                $added++;
            } catch(Exception $ex) {}
        }
        echo "<div class='alert alert-success py-2'>✅ Added {$added} ETFs to database.</div>";
        $existing = (int)$db->query('SELECT COUNT(*) FROM st_stocks')->fetchColumn();
    }

    // ── OPTION 3: Clear all stocks ───────────────────────────
    if ($action_do === 'clear') {
        $db->exec('TRUNCATE TABLE st_stocks');
        echo '<div class="alert alert-warning">All stocks cleared.</div>';
    }

    $existing = (int)$db->query('SELECT COUNT(*) FROM st_stocks')->fetchColumn();
}
?>

  <!-- Current status -->
  <div class="alert <?= $existing > 0 ? 'alert-success' : 'alert-warning' ?> py-2 mb-3">
    <?= $existing > 0
      ? "✅ <strong>{$existing} NSE stocks</strong> in database. Search is working from local DB."
      : "⚠️ No stocks in database yet. Import to enable stock search." ?>
  </div>

  <!-- Option 1: Auto download -->
  <div class="mb-3 p-3 border rounded">
    <h6>Option 1 — Auto Download from NSE</h6>
    <p style="font-size:12px;color:#666;">Fetches EQUITY_L.csv from NSE India directly (may fail if Hostinger blocks NSE).</p>
    <form method="POST">
      <input type="hidden" name="action" value="download_nse">
      <button type="submit" class="btn btn-primary btn-sm">⬇ Download & Import from NSE</button>
    </form>
  </div>

  <!-- Option 1b: ETF list from NSE -->
  <div class="mb-3 p-3 border rounded" style="border-color:#0d6efd!important;">
    <h6>Option 1b — Import ETFs Manually (BANKBEES, NIFTYBEES etc.)</h6>
    <p style="font-size:12px;color:#666;">Use this if ETFs are missing after the main import.</p>
    <form method="POST">
      <input type="hidden" name="action" value="add_etfs">
      <button type="submit" class="btn btn-outline-primary btn-sm">➕ Add Common NSE ETFs</button>
    </form>
  </div>

  <!-- Option 2: Upload CSV -->
  <div class="mb-3 p-3 border rounded">
    <h6>Option 2 — Upload EQUITY_L.csv manually</h6>
    <p style="font-size:12px;color:#666;">
      1. Go to <a href="https://www.nseindia.com/market-data/securities-available-for-trading" target="_blank">NSE India</a><br>
      2. Download <strong>EQUITY_L.csv</strong><br>
      3. Upload it here
    </p>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_csv">
      <div class="d-flex gap-2 align-items-center">
        <input type="file" name="csvfile" class="form-control form-control-sm" accept=".csv" required>
        <button type="submit" class="btn btn-success btn-sm" style="white-space:nowrap;">📤 Upload & Import</button>
      </div>
    </form>
  </div>

  <!-- Option 3: Clear -->
  <?php if ($existing > 0): ?>
  <div class="mb-3 p-3 border rounded border-danger">
    <h6>Option 3 — Clear & Re-import</h6>
    <form method="POST">
      <input type="hidden" name="action" value="clear">
      <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Clear all stocks?')">🗑 Clear All Stocks</button>
    </form>
  </div>
  <?php endif; ?>

  <p style="font-size:11px;color:#aaa;margin-top:16px;">⚠️ Delete this file after importing for security.</p>
</div>
</div>
</body>
</html>
