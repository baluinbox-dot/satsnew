<?php
// ============================================================
// api/portfolio.php — v2.1 — Clean trailing M1
//
// FIXES:
// 1. Only show trailing high when M1 is actually activated
// 2. ALWAYS compute trigger from highestPrice × (1 - pullPct%)
//    — never use customTriggerPrice (was causing ₹3188 bug)
// 3. needPctForM1 = how much price must rise to activate M1
// ============================================================

$user = requireAuth();
$db   = getDB();

function col3(PDO $db, string $t, string $c): bool {
    return $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")->rowCount() > 0;
}
function ensureCols3(PDO $db): void {
    $p=['firstBuyPrice'=>'DECIMAL(10,2) DEFAULT 0','lastBuyPrice'=>'DECIMAL(10,2) DEFAULT 0',
        'm2HighestPrice'=>'DECIMAL(10,2) DEFAULT 0','m2UpdatedAt'=>'DATETIME DEFAULT NULL'];
    foreach($p as $c=>$d) if(!col3($db,'st_portfolio',$c)) $db->exec("ALTER TABLE st_portfolio ADD COLUMN `{$c}` {$d}");
    $m=['customActivationPrice'=>'DECIMAL(10,2) DEFAULT NULL','customTriggerPrice'=>'DECIMAL(10,2) DEFAULT NULL','isEdited'=>'TINYINT(1) DEFAULT 0','m2AtCycleStart'=>'DECIMAL(10,2) DEFAULT 0'];
    foreach($m as $c=>$d) if(!col3($db,'st_m1tracker',$c)) $db->exec("ALTER TABLE st_m1tracker ADD COLUMN `{$c}` {$d}");
    if(!col3($db,'st_pricecache','changePercent')) $db->exec("ALTER TABLE st_pricecache ADD COLUMN changePercent DECIMAL(6,2) DEFAULT 0");
}

ensureCols3($db);
$hasCustom = col3($db, 'st_m1tracker', 'customActivationPrice');
$settings  = getSettings($user['id']);
$pullPct   = floatval($settings['pullbackPct']);  // e.g. 5
$actPct    = floatval($settings['activationPct']); // e.g. 10
$sellPct   = floatval($settings['sellTargetPct']); // e.g. 30

switch ($action) {

    case 'getPortfolio':
        // FIXED: Every user (including superadmin) sees ONLY their own stocks.
        // Superadmin viewing ALL users' data belongs in the admin panel only.
        $stmt = $db->prepare('SELECT p.* FROM st_portfolio p WHERE p.ownerId=? AND p.isActive=1 ORDER BY p.createdAt DESC');
        $stmt->execute([$user['id']]);
        $portfolio = $stmt->fetchAll();

        foreach ($portfolio as &$stock) {
            // Live price
            $pc = $db->prepare('SELECT lastPrice,changePercent,dayHigh,dayLow FROM st_pricecache WHERE symbol=?');
            $pc->execute([$stock['symbol']]);
            $price = $pc->fetch() ?: [];
            $stock['cmp']           = $price['lastPrice']     ?? null;
            $stock['changePercent'] = $price['changePercent'] ?? null;

            $cmp     = floatval($stock['cmp']           ?? 0);
            $avg     = floatval($stock['avgPrice']      ?? 0);
            $lastBuy = floatval($stock['lastBuyPrice']  ?? 0);
            $m2High  = floatval($stock['m2HighestPrice']?? 0);

            $stock['plAmt'] = ($cmp&&$avg>0) ? round(($cmp-$avg)*$stock['totalQty'],2)    : null;
            $stock['plPct'] = ($cmp&&$avg>0) ? round((($cmp-$avg)/$avg)*100,2)            : null;
            $stock['avPct'] = $stock['plPct'];
            $stock['brPct'] = ($cmp&&$lastBuy>0) ? round((($cmp-$lastBuy)/$lastBuy)*100,2): null;
            $stock['m2Pct'] = ($cmp&&$m2High>0)  ? round((($cmp-$m2High)/$m2High)*100,2)  : null;

            // ── M1 tracker ────────────────────────────────────────
            $m1q = 'SELECT id, activationPrice, IFNULL(highestPrice,0) AS highestPrice,
                           m1Activated, status, cycleNumber, baseBuyPrice'
                . ($hasCustom ? ', IFNULL(customActivationPrice,0) AS customActivationPrice, IFNULL(isEdited,0) AS isEdited' : ', 0 AS customActivationPrice, 0 AS isEdited')
                . ' FROM st_m1tracker WHERE portfolioId=? AND status NOT IN ("done","sold")
                    ORDER BY cycleNumber DESC LIMIT 1';
            $m1stmt = $db->prepare($m1q);
            $m1stmt->execute([$stock['id']]);
            $m1 = $m1stmt->fetch() ?: [];

            $m1Activated = (bool)($m1['m1Activated'] ?? 0);
            $trailHigh   = floatval($m1['highestPrice'] ?? 0); // trailing peak (only valid when activated)
            $actThresh   = floatval($m1['activationPrice'] ?? 0); // initial +10% threshold

            // User-edited activation threshold
            if (!empty($m1['customActivationPrice']) && floatval($m1['customActivationPrice']) > 0) {
                $actThresh = floatval($m1['customActivationPrice']);
            }

            $stock['m1Id']        = $m1['id']          ?? null;
            $stock['m1Activated'] = $m1Activated ? 1 : 0;
            $stock['m1Status']    = $m1['status']       ?? 'none';
            $stock['cycleNumber'] = $m1['cycleNumber']  ?? 0;
            $stock['baseBuyPrice']= $m1['baseBuyPrice'] ?? 0;

            // Activation threshold (shows in "need +X%" mode)
            $stock['activationThreshold'] = $actThresh;

            // ── Trailing high & trigger (ONLY meaningful when M1 activated) ──
            if ($m1Activated && $trailHigh > 0) {
                $stock['m1TrailingHigh'] = $trailHigh;
                // Trigger is ALWAYS trailingHigh × (1 - pullPct%)
                // Never use customTriggerPrice — that was causing the ₹3188 bug
                $stock['triggerPrice']   = round($trailHigh * (1 - $pullPct/100), 2);

                // M1% = how far CMP has dropped FROM trailing high
                // 0% = at peak, -5% = in buy zone
                $stock['m1Pct'] = ($cmp > 0)
                    ? round((($cmp - $trailHigh) / $trailHigh) * 100, 2) : null;
            } else {
                $stock['m1TrailingHigh'] = null;   // don't show trailing high before activation
                $stock['triggerPrice']   = null;
                $stock['m1Pct']          = null;
            }

            // How much price needs to rise to activate M1 (only when NOT activated)
            $stock['needPctForM1'] = (!$m1Activated && $cmp > 0 && $actThresh > 0)
                ? round((($actThresh - $cmp) / $cmp) * 100, 2) : null;

            // "activationPrice" kept for backward compat with JS
            $stock['activationPrice'] = $actThresh;
        }
        unset($stock);
        jsonOk($portfolio);
        break;

    // ──────────────────────────────────────────────────────────
    case 'addStock':
        $body     = json_decode(file_get_contents('php://input'),true) ?? [];
        $symbol   = strtoupper(trim($body['symbol']   ?? ''));
        $exchange = strtoupper(trim($body['exchange'] ?? 'NSE'));
        $buyPrice = floatval($body['buyPrice'] ?? 0);
        $qty      = intval($body['qty']        ?? 0);
        $txDate   = trim($body['txDate']       ?? date('Y-m-d'));
        $notes    = trim($body['notes']        ?? '');

        if (!$symbol)       jsonError('Stock symbol required');
        if ($buyPrice <= 0) jsonError('Buy price must be > 0');
        if ($qty <= 0)      jsonError('Quantity must be > 0');

        $chk = $db->prepare('SELECT id,buyCount,firstBuyPrice FROM st_portfolio WHERE ownerId=? AND symbol=? AND isActive=1 LIMIT 1');
        $chk->execute([$user['id'], $symbol]);
        $existing = $chk->fetch();

        if ($existing) {
            if ($existing['buyCount'] >= $settings['maxBuyCount'])
                jsonError("Max buys ({$settings['maxBuyCount']}) reached for $symbol");
            $portfolioId   = $existing['id'];
            $buyNumber     = $existing['buyCount'] + 1;
            $firstBuyPrice = floatval($existing['firstBuyPrice'] ?: $buyPrice);
        } else {
            $portfolioId   = genId();
            $firstBuyPrice = $buyPrice;
            $db->prepare('INSERT INTO st_portfolio (id,ownerId,symbol,exchange,notes,firstBuyPrice,lastBuyPrice,m2HighestPrice) VALUES (?,?,?,?,?,?,?,?)')
               ->execute([$portfolioId,$user['id'],$symbol,$exchange,$notes,$buyPrice,$buyPrice,$buyPrice]);
            $buyNumber = 1;
        }

        $db->prepare('INSERT INTO st_transactions (id,ownerId,portfolioId,buyPrice,qty,buyNumber,txDate,notes) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([genId(),$user['id'],$portfolioId,$buyPrice,$qty,$buyNumber,$txDate,$notes]);

        $db->prepare('UPDATE st_portfolio SET lastBuyPrice=? WHERE id=?')->execute([$buyPrice,$portfolioId]);
        recalcPortfolio($portfolioId);

        // Close old M1 cycles
        $db->prepare("UPDATE st_m1tracker SET status='done' WHERE portfolioId=? AND status NOT IN ('done','sold')")->execute([$portfolioId]);

        // M2: only goes up, never down — update BEFORE creating new M1 cycle
        $m2c = $db->prepare('SELECT IFNULL(m2HighestPrice,0) FROM st_portfolio WHERE id=?');
        $m2c->execute([$portfolioId]);
        $currentM2 = floatval($m2c->fetchColumn() ?? 0);
        if ($buyPrice > $currentM2)
            $db->prepare('UPDATE st_portfolio SET m2HighestPrice=?,m2UpdatedAt=NOW() WHERE id=?')->execute([$buyPrice,$portfolioId]);
        // Baseline for retroactive M1 activation: m2 must GROW beyond this level after the new buy
        $m2AtCycleStart = max($currentM2, $buyPrice);

        // New M1 cycle — m2AtCycleStart prevents the old m2 high from triggering false activation
        $actThreshold = round($buyPrice * (1 + $actPct/100), 2);
        $db->prepare('INSERT INTO st_m1tracker (id,ownerId,portfolioId,cycleNumber,baseBuyPrice,activationPrice,triggerPrice,highestPrice,status,m2AtCycleStart) VALUES (?,?,?,?,?,?,0,0,"waiting",?)')
           ->execute([genId(),$user['id'],$portfolioId,$buyNumber,$buyPrice,$actThreshold,$m2AtCycleStart]);

        jsonOk(['portfolioId'=>$portfolioId,'buyNumber'=>$buyNumber],
            "$symbol buy #$buyNumber. M1 activates at ₹".number_format($actThreshold,2));
        break;

    // ──────────────────────────────────────────────────────────
    case 'updateM1M2':
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $portfolioId = trim($body['portfolioId']??'');
        $m1Id  = trim($body['m1Id']  ?? '');
        $field = trim($body['field'] ?? '');
        $value = floatval($body['value'] ?? 0);

        if (!$portfolioId || $value <= 0) jsonError('Portfolio ID and value required');
        $chk = $db->prepare('SELECT id FROM st_portfolio WHERE id=? AND ownerId=?');
        $chk->execute([$portfolioId,$user['id']]);
        if (!$chk->fetch()) jsonError('Access denied',403);

        if ($field === 'm2') {
            $db->prepare('UPDATE st_portfolio SET m2HighestPrice=?,m2UpdatedAt=NOW() WHERE id=?')->execute([$value,$portfolioId]);
            jsonOk([],'M2 set to ₹'.number_format($value,2));
        } else {
            // Editing M1: set new activation threshold
            // triggerPrice is computed dynamically from highestPrice — NOT stored as custom
            if (!$m1Id) jsonError('M1 ID required');
            ensureCols3($db);
            $db->prepare('UPDATE st_m1tracker SET customActivationPrice=?,isEdited=1 WHERE id=? AND ownerId=?')
               ->execute([$value,$m1Id,$user['id']]);
            $newTrigger = round($value * (1 - $pullPct/100), 2);
            jsonOk(['newActivation'=>$value,'newTrigger'=>$newTrigger],
                'M1 activation set to ₹'.number_format($value,2).' | Buy signal at ₹'.number_format($newTrigger,2));
        }
        break;

    // ──────────────────────────────────────────────────────────
    case 'deleteStock':
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $pid  = trim($body['portfolioId']??'');
        if (!$pid) jsonError('Portfolio ID required');
        $chk = $db->prepare('SELECT id FROM st_portfolio WHERE id=? AND ownerId=? LIMIT 1');
        $chk->execute([$pid,$user['id']]);
        if (!$chk->fetch()) jsonError('Not found',403);
        $db->prepare('UPDATE st_portfolio SET isActive=0 WHERE id=?')->execute([$pid]);
        jsonOk([],'Stock removed');
        break;

    // ──────────────────────────────────────────────────────────
    case 'getBuyHistory':
        $pid = trim($_GET['portfolioId']??'');
        if (!$pid) jsonError('Portfolio ID required');
        $chk = $db->prepare('SELECT id,symbol,avgPrice,totalQty,totalInvestment FROM st_portfolio WHERE id=? AND ownerId=? LIMIT 1');
        $chk->execute([$pid,$user['id']]);
        $port = $chk->fetch();
        if (!$port) jsonError('Access denied',403);
        $stmt = $db->prepare('SELECT id,buyNumber,buyPrice,qty,ROUND(buyPrice*qty,2) AS total,txDate,notes FROM st_transactions WHERE portfolioId=? AND ownerId=? ORDER BY txDate ASC,buyNumber ASC');
        $stmt->execute([$pid,$user['id']]);
        jsonOk(['symbol'=>$port['symbol'],'avgPrice'=>$port['avgPrice'],'totalQty'=>$port['totalQty'],'totalInvestment'=>$port['totalInvestment'],'transactions'=>$stmt->fetchAll()]);
        break;
}
