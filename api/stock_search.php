<?php
// ============================================================
// api/stock_search.php — Search NSE stocks from local DB
// Falls back to Yahoo Finance if local DB is empty
// ============================================================

$user = requireAuth();
$q    = strtoupper(trim($_GET['q'] ?? ''));

if (strlen($q) < 1) { jsonOk(['results' => [], 'source' => 'local']); }

$db = getDB();

// ── Ensure st_stocks table exists ───────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS st_stocks (
    symbol   VARCHAR(30) PRIMARY KEY,
    name     VARCHAR(200),
    exchange VARCHAR(10) DEFAULT 'NSE',
    series   VARCHAR(10),
    isin     VARCHAR(20),
    isActive TINYINT(1) DEFAULT 1,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Check if we have stocks in local DB ──────────────────────
$count = (int)$db->query('SELECT COUNT(*) FROM st_stocks')->fetchColumn();

if ($count > 0) {
    // ── Search from local DB ─────────────────────────────────
    $like  = $q . '%';    // symbol starts with query
    $like2 = '%' . $q . '%'; // or name contains query
    $stmt  = $db->prepare('
        SELECT symbol, name, exchange
        FROM st_stocks
        WHERE isActive = 1
          AND (symbol LIKE ? OR name LIKE ?)
        ORDER BY
            CASE WHEN symbol LIKE ? THEN 0 ELSE 1 END,
            LENGTH(symbol) ASC,
            symbol ASC
        LIMIT 10
    ');
    $stmt->execute([$like, $like2, $like]);
    $results = $stmt->fetchAll();

    jsonOk(['results' => $results, 'source' => 'local', 'total' => $count]);
}

// ── Fallback: Yahoo Finance if local DB empty ────────────────
$url = 'https://query1.finance.yahoo.com/v1/finance/search'
     . '?q=' . urlencode($q) . '.NS&quotesCount=10&country=India&lang=en-IN&newsCount=0';

$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'timeout' => 5,
        'header'  => "User-Agent: Mozilla/5.0\r\nAccept: application/json\r\n",
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

$raw = @file_get_contents($url, false, $ctx);
$results = [];

if ($raw) {
    $data   = json_decode($raw, true);
    $quotes = $data['quotes'] ?? [];
    foreach ($quotes as $qt) {
        if (!in_array($qt['exchange'] ?? '', ['NSI','NSE'])) continue;
        $sym = preg_replace('/\.(NS|BO)$/', '', $qt['symbol'] ?? '');
        if (!$sym) continue;
        $results[] = [
            'symbol'   => $sym,
            'name'     => $qt['longname'] ?? $qt['shortname'] ?? $sym,
            'exchange' => 'NSE',
        ];
    }
}

if (empty($results)) {
    // Last resort: return the typed symbol as-is so user can proceed
    $results[] = [
        'symbol'   => $q,
        'name'     => $q . ' (type manually)',
        'exchange' => 'NSE',
    ];
}

jsonOk(['results' => $results, 'source' => 'yahoo', 'total' => 0]);
