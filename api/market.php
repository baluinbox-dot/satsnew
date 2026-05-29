<?php
// ============================================================
// api/market.php — Live price fetch via Yahoo Finance
// Now also stores changePercent (CHG%)
// ============================================================

$user = requireAuth();

// Add changePercent column if missing (safe to run multiple times)
try {
    $dbCheck = getDB();
    $dbCheck->query("ALTER TABLE st_pricecache ADD COLUMN IF NOT EXISTS changePercent DECIMAL(6,2) DEFAULT 0");
} catch(Exception $e) { /* column may already exist */ }

switch ($action) {

    case 'getPrices':
        $db   = getDB();
        $stmt = $db->prepare('SELECT DISTINCT p.symbol, p.exchange FROM st_portfolio p WHERE p.ownerId = ? AND p.isActive = 1');
        $stmt->execute([$user['id']]);
        $stocks = $stmt->fetchAll();
        $prices = [];
        foreach ($stocks as $s) {
            $pc = $db->prepare('SELECT * FROM st_pricecache WHERE symbol = ?');
            $pc->execute([$s['symbol']]);
            $row = $pc->fetch();
            if ($row) $prices[$s['symbol']] = $row;
        }
        jsonOk($prices);
        break;

    case 'refreshPrices':
        $db   = getDB();
        $stmt = $db->prepare('SELECT DISTINCT p.symbol, p.exchange FROM st_portfolio p WHERE p.ownerId = ? AND p.isActive = 1');
        $stmt->execute([$user['id']]);
        $stocks = $stmt->fetchAll();

        if (empty($stocks)) jsonOk([], 'No stocks to refresh');

        $updated = []; $failed = [];

        foreach ($stocks as $s) {
            // Cache: skip if < 2 min old
            $chk = $db->prepare('SELECT symbol FROM st_pricecache WHERE symbol = ? AND fetchedAt > DATE_SUB(NOW(), INTERVAL 2 MINUTE)');
            $chk->execute([$s['symbol']]);
            if ($chk->fetch()) { $updated[] = $s['symbol'] . ' (cached)'; continue; }

            $suffix   = ($s['exchange'] === 'BSE') ? '.BO' : '.NS';
            $yahooSym = $s['symbol'] . $suffix;

            $result = fetchYahooPrice($yahooSym, $s['symbol'], $s['exchange'], $db);
            if ($result) $updated[] = $s['symbol'];
            else         $failed[]  = $s['symbol'];

            usleep(400000); // 400ms between calls
        }

        jsonOk(['updated' => $updated, 'failed' => $failed], 'Price refresh complete');
        break;
}

function fetchYahooPrice(string $yahooSym, string $symbol, string $exchange, PDO $db): bool {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSym}?interval=1m&range=1d";
    $ctx = stream_context_create([
        'http' => ['method'=>'GET','timeout'=>8,'header'=>"User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n"],
        'ssl'  => ['verify_peer'=>false,'verify_peer_name'=>false]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return false;

    $json = json_decode($raw, true);
    $meta = $json['chart']['result'][0]['meta'] ?? null;
    if (!$meta) return false;

    $lastPrice     = floatval($meta['regularMarketPrice']           ?? 0);
    $prevClose     = floatval($meta['chartPreviousClose']           ?? $meta['previousClose'] ?? 0);
    $dayHigh       = floatval($meta['regularMarketDayHigh']         ?? 0);
    $dayLow        = floatval($meta['regularMarketDayLow']          ?? 0);
    $volume        = intval($meta['regularMarketVolume']            ?? 0);
    $changePct     = ($prevClose > 0) ? round((($lastPrice - $prevClose) / $prevClose) * 100, 2) : 0;

    if (!$lastPrice) return false;

    $db->prepare('
        INSERT INTO st_pricecache (id, symbol, exchange, lastPrice, dayHigh, dayLow, volume, changePercent, fetchedAt)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            lastPrice=VALUES(lastPrice), dayHigh=VALUES(dayHigh), dayLow=VALUES(dayLow),
            volume=VALUES(volume), changePercent=VALUES(changePercent), fetchedAt=NOW()
    ')->execute([genId(), $symbol, $exchange, $lastPrice, $dayHigh, $dayLow, $volume, $changePct]);

    return true;
}
