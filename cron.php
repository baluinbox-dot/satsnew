<?php
// ============================================================
// cron.php — STAS Auto Price Update & Strategy Check
//
// Runs every 15 min during market hours WITHOUT app being open.
// Called by Hostinger Cron Job.
//
// URL:  https://stas.finopsdigital.com/cron.php?key=STAS_CRON_2706
// CLI:  php /home/u900311706/public_html/stas/cron.php
// ============================================================

define('STAS_CRON_CONTEXT', true);
define('STAS_API_CONTEXT',  true);

require_once __DIR__ . '/api/config.php';

// ── CRON SECRET KEY ──────────────────────────────────────────
// Change this to any secret string you want
define('CRON_SECRET', 'STAS_CRON_2706');

// ── AUTH: Allow CLI (no key needed) or web with correct key ──
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    $key = $_GET['key'] ?? '';
    if ($key !== CRON_SECRET) {
        http_response_code(403);
        die('Unauthorized. Use: cron.php?key=' . CRON_SECRET);
    }
}

// ── MARKET HOURS CHECK (IST = UTC+5:30) ──────────────────────
// NSE market: Mon–Fri, 9:15 AM – 3:30 PM IST
// Cron will still run but skip price fetch outside hours
$tz     = new DateTimeZone('Asia/Kolkata');
$now    = new DateTime('now', $tz);
$dayNum = (int)$now->format('N'); // 1=Mon, 7=Sun
$hour   = (int)$now->format('H');
$min    = (int)$now->format('i');
$timeV  = $hour * 100 + $min; // e.g. 9:15 = 915, 15:30 = 1530

$isWeekday     = ($dayNum >= 1 && $dayNum <= 5);
$isMarketHours = ($timeV >= 900 && $timeV <= 1540); // 9:00 AM – 3:40 PM buffer
$isMarketOpen  = $isWeekday && $isMarketHours;

// ── LOGGING ───────────────────────────────────────────────────
$logFile = __DIR__ . '/cron_log.txt';
function clog(string $msg): void {
    global $logFile, $isCLI;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    if ($GLOBALS['isCLI']) echo $line;
}

clog('─── CRON START ─────────────────────────────────────────');
clog("Market open: " . ($isMarketOpen ? 'YES' : 'NO (skipping price fetch)') . " | Day:{$dayNum} Time:{$timeV}");

$db = getDB();

// ── STEP 1: GET ALL ACTIVE SYMBOLS FROM ALL USERS ─────────────
$symStmt = $db->query("
    SELECT DISTINCT p.symbol, p.exchange
    FROM st_portfolio p
    WHERE p.isActive = 1
    ORDER BY p.symbol
");
$symbols = $symStmt->fetchAll();
clog("Found " . count($symbols) . " unique symbols to check");

if (empty($symbols)) {
    clog("No symbols found. Exiting.");
    exit;
}

// ── STEP 2: FETCH PRICES (only during market hours) ───────────
$priceUpdated = 0;
$priceFailed  = 0;

if ($isMarketOpen) {
    clog("Fetching prices from Yahoo Finance...");
    foreach ($symbols as $s) {
        $suffix   = ($s['exchange'] === 'BSE') ? '.BO' : '.NS';
        $yahooSym = $s['symbol'] . $suffix;
        $ok       = fetchYahooPrice($yahooSym, $s['symbol'], $s['exchange'], $db);
        if ($ok) $priceUpdated++;
        else     $priceFailed++;
        // Small delay to avoid rate limiting
        usleep(150000); // 150ms between requests
    }
    clog("Prices: Updated={$priceUpdated} | Failed={$priceFailed}");
} else {
    clog("Outside market hours — skipping price fetch, running strategy check with cached prices");
}

// ── STEP 3: RUN STRATEGY CHECK FOR EVERY USER ─────────────────
$userStmt = $db->query("
    SELECT DISTINCT p.ownerId AS id
    FROM st_portfolio p
    WHERE p.isActive = 1
");
$users   = $userStmt->fetchAll();
$signals = 0;

clog("Running strategy check for " . count($users) . " users...");

foreach ($users as $userRow) {
    $userId   = $userRow['id'];
    $settings = getSettings($userId);
    $pullPct  = floatval($settings['pullbackPct']);
    $actPct   = floatval($settings['activationPct']);
    $sellPct  = floatval($settings['sellTargetPct']);

    // Get this user's portfolio with prices
    $stmt = $db->prepare("
        SELECT p.id AS portfolioId, p.symbol, p.avgPrice, p.totalQty, p.buyCount,
               IFNULL(p.lastBuyPrice,0)   AS lastBuyPrice,
               IFNULL(p.m2HighestPrice,0) AS m2HighestPrice,
               m.id AS trackerId, m.activationPrice,
               IFNULL(m.highestPrice,0)   AS highestPrice,
               IFNULL(m.customActivationPrice,0) AS customActivationPrice,
               IFNULL(m.isEdited,0) AS isEdited,
               m.m1Activated, m.status AS m1Status, m.cycleNumber, m.baseBuyPrice,
               pc.lastPrice AS cmp
        FROM st_portfolio p
        LEFT JOIN st_m1tracker m ON m.portfolioId=p.id AND m.status NOT IN ('done','sold')
        LEFT JOIN st_pricecache pc ON pc.symbol=p.symbol
        WHERE p.ownerId=? AND p.isActive=1
    ");
    $stmt->execute([$userId]);
    $stocks = $stmt->fetchAll();

    foreach ($stocks as $s) {
        $cmp         = floatval($s['cmp']           ?? 0);
        $portfolioId = $s['portfolioId'];
        $avgPrice    = floatval($s['avgPrice']       ?? 0);
        $lastBuyRate = floatval($s['lastBuyPrice']   ?? 0);
        $trackerId   = $s['trackerId']               ?? null;

        if (!$cmp) continue;

        // M2: all-time high
        $curM2 = floatval($s['m2HighestPrice'] ?? 0);
        if ($cmp > $curM2) {
            $db->prepare('UPDATE st_portfolio SET m2HighestPrice=?,m2UpdatedAt=NOW() WHERE id=?')
               ->execute([$cmp, $portfolioId]);
            $curM2 = $cmp;
        }

        // M1 trail (with retroactive activation fix)
        if ($trackerId) {
            $m1Activated = (bool)$s['m1Activated'];
            $m1Status    = $s['m1Status'];
            $currentHigh = floatval($s['highestPrice'] ?? 0);
            $actThresh   = ($s['isEdited'] && $s['customActivationPrice'] > 0)
                         ? floatval($s['customActivationPrice'])
                         : floatval($s['activationPrice'] ?? 0);
            $m2High      = floatval($curM2);
            $alreadyCrossed = ($actThresh > 0 && $m2High >= $actThresh);

            // Activate M1 (retroactive via m2 if needed)
            if (!$m1Activated && $actThresh > 0 && ($cmp >= $actThresh || $alreadyCrossed)) {
                $activationHigh = ($cmp >= $actThresh) ? max($cmp, $m2High) : $m2High;
                $currentHigh    = $activationHigh;
                $db->prepare('UPDATE st_m1tracker SET m1Activated=1,m1ActivatedAt=NOW(),status="activated",highestPrice=?,triggerPrice=? WHERE id=?')
                   ->execute([$activationHigh, round($activationHigh*(1-$pullPct/100),2), $trackerId]);
                $m1Activated = true;
                $m1Status    = 'activated';
            }

            // Trail high moves up only
            if ($m1Activated && $cmp > $currentHigh) {
                $currentHigh = $cmp;
                $db->prepare('UPDATE st_m1tracker SET highestPrice=?,triggerPrice=? WHERE id=?')
                   ->execute([$currentHigh, round($cmp*(1-$pullPct/100),2), $trackerId]);
            }

            // BUY signal on pullback
            if ($m1Activated && $currentHigh > 0) {
                $buyZone = round($currentHigh * (1 - $pullPct/100), 2);
                if ($m1Status==='activated' && $cmp<=$buyZone && $s['buyCount']<$settings['maxBuyCount']) {
                    $dropPct = round((($currentHigh-$cmp)/$currentHigh)*100,1);
                    $pp      = $avgPrice>0 ? round((($cmp-$avgPrice)/$avgPrice)*100,2) : 0;
                    $msg     = "{$s['symbol']} M1 pullback! Peak ₹".number_format($currentHigh,2)
                             . " → CMP ₹".number_format($cmp,2)." (−{$dropPct}%). 🔴 BUY signal.";
                    createSignal($portfolioId,$userId,'BUY',$cmp,$avgPrice,$pp,$msg);
                    $db->prepare("UPDATE st_m1tracker SET status='triggered' WHERE id=?")->execute([$trackerId]);
                    $signals++;
                }
                if ($m1Status==='triggered' && $cmp>$currentHigh) {
                    $db->prepare("UPDATE st_m1tracker SET status='activated' WHERE id=?")->execute([$trackerId]);
                }
            }
        }

        // Drop-20 signal
        if ($lastBuyRate > 0) {
            $drop20 = round($lastBuyRate * 0.80, 2);
            if ($cmp <= $drop20 && $s['buyCount'] < $settings['maxBuyCount']) {
                $pp  = $avgPrice>0 ? round((($cmp-$avgPrice)/$avgPrice)*100,2) : 0;
                $msg = "{$s['symbol']} dropped 20% from last buy ₹".number_format($lastBuyRate,2)." | CMP ₹".number_format($cmp,2);
                createSignal($portfolioId,$userId,'BUY',$cmp,$avgPrice,$pp,$msg);
                $signals++;
            }
        }

        // SELL signal
        if ($avgPrice > 0) {
            $sellTgt = round($avgPrice * (1 + $sellPct/100), 2);
            $pp      = round((($cmp-$avgPrice)/$avgPrice)*100, 2);
            if ($cmp >= $sellTgt) {
                $msg = "{$s['symbol']} +{$sellPct}% target! Avg ₹".number_format($avgPrice,2)
                     . " | CMP ₹".number_format($cmp,2)." | +".number_format($pp,1)."% 🎯";
                createSignal($portfolioId,$userId,'SELL',$cmp,$avgPrice,$pp,$msg);
                $signals++;
            }
        }
    }
}

clog("Strategy check done. Signals created: {$signals}");
clog("─── CRON END ───────────────────────────────────────────");

// Keep log file max 500 lines
$lines = file($logFile);
if (count($lines) > 500) {
    file_put_contents($logFile, implode('', array_slice($lines, -300)));
}

// Output summary for web calls
if (!$isCLI) {
    header('Content-Type: application/json');
    echo json_encode([
        'status'       => 'ok',
        'time'         => $now->format('Y-m-d H:i:s T'),
        'market_open'  => $isMarketOpen,
        'prices_updated'=> $priceUpdated,
        'signals'      => $signals,
        'users'        => count($users),
        'symbols'      => count($symbols),
    ]);
}

// ── PRICE FETCH FUNCTION (copied from market.php) ────────────
function fetchYahooPrice(string $yahooSym, string $symbol, string $exchange, PDO $db): bool {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$yahooSym}?interval=1m&range=1d";
    $ctx = stream_context_create(['http'=>[
        'timeout' => 10,
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\nAccept: application/json\r\n",
    ],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return false;
    $data = json_decode($json, true);
    $meta = $data['chart']['result'][0]['meta'] ?? null;
    if (!$meta) return false;

    $lastPrice = floatval($meta['regularMarketPrice'] ?? 0);
    $prevClose = floatval($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? 0);
    $dayHigh   = floatval($meta['regularMarketDayHigh']   ?? 0);
    $dayLow    = floatval($meta['regularMarketDayLow']    ?? 0);
    $volume    = intval($meta['regularMarketVolume']       ?? 0);
    $changePct = ($prevClose > 0) ? round((($lastPrice-$prevClose)/$prevClose)*100,2) : 0;

    if (!$lastPrice) return false;

    $db->prepare("
        INSERT INTO st_pricecache (id,symbol,exchange,lastPrice,dayHigh,dayLow,volume,changePercent,fetchedAt)
        VALUES (?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE lastPrice=VALUES(lastPrice),dayHigh=VALUES(dayHigh),
        dayLow=VALUES(dayLow),volume=VALUES(volume),changePercent=VALUES(changePercent),fetchedAt=NOW()
    ")->execute([genId(),$symbol,$exchange,$lastPrice,$dayHigh,$dayLow,$volume,$changePct]);
    return true;
}
