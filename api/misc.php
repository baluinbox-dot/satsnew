<?php
// api/misc.php — v1.6 (added deleteSignal, pagination support)

// ── SIGNALS ──────────────────────────────────────────────────
if (in_array($action, ['getSignals','markSignalRead','markSignalActioned','deleteSignal'])) {
    $user = requireAuth();
    $db   = getDB();

    if ($action === 'getSignals') {
        $limit  = min(200, intval($_GET['limit'] ?? 100));
        $type   = strtoupper(trim($_GET['type'] ?? ''));
        $sql    = 'SELECT s.*, p.symbol FROM st_signals s JOIN st_portfolio p ON p.id=s.portfolioId WHERE s.ownerId=?';
        $params = [$user['id']];
        if ($type) { $sql .= ' AND s.signalType=?'; $params[] = $type; }
        $sql .= ' ORDER BY s.signalDate DESC LIMIT ' . $limit;
        $stmt = $db->prepare($sql); $stmt->execute($params);
        $signals = $stmt->fetchAll();
        $unread = $db->prepare('SELECT COUNT(*) FROM st_signals WHERE ownerId=? AND isRead=0');
        $unread->execute([$user['id']]);
        jsonOk(['signals'=>$signals, 'unreadCount'=>(int)$unread->fetchColumn()]);
    }
    if ($action === 'markSignalRead') {
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $id   = trim($body['id'] ?? '');
        if ($id==='all') $db->prepare('UPDATE st_signals SET isRead=1 WHERE ownerId=?')->execute([$user['id']]);
        else             $db->prepare('UPDATE st_signals SET isRead=1 WHERE id=? AND ownerId=?')->execute([$id,$user['id']]);
        jsonOk([], 'Marked read');
    }
    if ($action === 'markSignalActioned') {
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $db->prepare('UPDATE st_signals SET isActioned=1,isRead=1 WHERE id=? AND ownerId=?')
           ->execute([trim($body['id']??''), $user['id']]);
        jsonOk([], 'Actioned');
    }
    if ($action === 'deleteSignal') {
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $id   = trim($body['id'] ?? '');
        if (!$id) jsonError('Signal ID required');
        $db->prepare('DELETE FROM st_signals WHERE id=? AND ownerId=?')->execute([$id, $user['id']]);
        jsonOk([], 'Signal deleted');
    }
    exit;
}

// ── TRANSACTIONS ──────────────────────────────────────────────
if (in_array($action, ['getTransactions','deleteTransaction'])) {
    $user = requireAuth();
    $db   = getDB();

    if ($action === 'getTransactions') {
        $portfolioId = trim($_GET['portfolioId'] ?? '');
        if ($portfolioId) {
            $stmt = $db->prepare('SELECT t.*,p.symbol FROM st_transactions t JOIN st_portfolio p ON p.id=t.portfolioId WHERE t.portfolioId=? AND t.ownerId=? ORDER BY t.txDate ASC,t.buyNumber ASC');
            $stmt->execute([$portfolioId,$user['id']]);
        } else {
            $stmt = $db->prepare('SELECT t.*,p.symbol FROM st_transactions t JOIN st_portfolio p ON p.id=t.portfolioId WHERE t.ownerId=? ORDER BY t.txDate DESC LIMIT 200');
            $stmt->execute([$user['id']]);
        }
        jsonOk($stmt->fetchAll());
    }

    if ($action === 'deleteTransaction') {
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $id   = trim($body['id'] ?? '');
        if (!$id) jsonError('Transaction ID required');

        $chk = $db->prepare('SELECT t.portfolioId, t.buyNumber, t.buyPrice FROM st_transactions t WHERE t.id=? AND t.ownerId=?');
        $chk->execute([$id, $user['id']]);
        $tx = $chk->fetch();
        if (!$tx) jsonError('Transaction not found', 403);

        $portfolioId = $tx['portfolioId'];
        $db->prepare('DELETE FROM st_transactions WHERE id=?')->execute([$id]);
        recalcPortfolio($portfolioId);

        $portStmt = $db->prepare('SELECT avgPrice, totalQty, buyCount FROM st_portfolio WHERE id=?');
        $portStmt->execute([$portfolioId]);
        $port = $portStmt->fetch();

        $lastTx = $db->prepare('SELECT buyPrice, buyNumber FROM st_transactions WHERE portfolioId=? ORDER BY txDate DESC, buyNumber DESC LIMIT 1');
        $lastTx->execute([$portfolioId]);
        $lastBuyRow = $lastTx->fetch();

        if ($lastBuyRow) {
            $db->prepare('UPDATE st_portfolio SET lastBuyPrice=? WHERE id=?')->execute([$lastBuyRow['buyPrice'], $portfolioId]);
            $settings = getSettings($user['id']);
            $db->prepare("UPDATE st_m1tracker SET status='done' WHERE portfolioId=? AND status NOT IN ('done','sold')")->execute([$portfolioId]);
            // Pass current m2High as cycle baseline so old highs don't trigger false activation
            $m2q = $db->prepare('SELECT IFNULL(m2HighestPrice,0) FROM st_portfolio WHERE id=?');
            $m2q->execute([$portfolioId]);
            $currentM2 = floatval($m2q->fetchColumn() ?? 0);
            createM1Cycle($portfolioId, $user['id'], $lastBuyRow['buyPrice'], $lastBuyRow['buyNumber'], $settings, $currentM2);
            jsonOk(['avgPrice'=>$port['avgPrice'],'totalQty'=>$port['totalQty'],'buyCount'=>$port['buyCount'],'newLastBuy'=>$lastBuyRow['buyPrice']],
                "Transaction deleted. New avg: ₹{$port['avgPrice']} | Qty: {$port['totalQty']}");
        } else {
            $db->prepare('UPDATE st_portfolio SET avgPrice=0,totalQty=0,totalInvestment=0,buyCount=0,lastBuyPrice=0,firstBuyPrice=0,m2HighestPrice=0 WHERE id=?')->execute([$portfolioId]);
            $db->prepare("UPDATE st_m1tracker SET status='done' WHERE portfolioId=? AND status NOT IN ('done','sold')")->execute([$portfolioId]);
            $db->prepare('UPDATE st_portfolio SET isActive=0 WHERE id=?')->execute([$portfolioId]);
            jsonOk(['avgPrice'=>0,'totalQty'=>0], 'Last transaction deleted. Stock removed.');
        }
    }
    exit;
}

// ── SETTINGS ──────────────────────────────────────────────────
if (in_array($action, ['getSettings','saveSettings'])) {
    $user = requireAuth();
    $db   = getDB();
    if ($action === 'getSettings') { jsonOk(getSettings($user['id'])); }
    if ($action === 'saveSettings') {
        $body    = json_decode(file_get_contents('php://input'),true) ?? [];
        $actPct  = max(1,min(50,floatval($body['activationPct'] ??10)));
        $pullPct = max(1,min(20,floatval($body['pullbackPct']   ??5)));
        $sellPct = max(5,min(200,floatval($body['sellTargetPct']??30)));
        $maxBuy  = max(1,min(10,intval($body['maxBuyCount']     ??5)));
        $chk = $db->prepare('SELECT id FROM st_settings WHERE ownerId=?');
        $chk->execute([$user['id']]);
        if ($chk->fetch())
            $db->prepare('UPDATE st_settings SET activationPct=?,pullbackPct=?,sellTargetPct=?,maxBuyCount=? WHERE ownerId=?')
               ->execute([$actPct,$pullPct,$sellPct,$maxBuy,$user['id']]);
        else
            $db->prepare('INSERT INTO st_settings (id,ownerId,activationPct,pullbackPct,sellTargetPct,maxBuyCount) VALUES (?,?,?,?,?,?)')
               ->execute([genId(),$user['id'],$actPct,$pullPct,$sellPct,$maxBuy]);
        jsonOk([], 'Settings saved');
    }
    exit;
}

// ── DASHBOARD ─────────────────────────────────────────────────
if ($action === 'getDashboard') {
    $user = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare('SELECT SUM(p.totalInvestment) AS ti, SUM(p.totalQty) AS tq, COUNT(p.id) AS sc FROM st_portfolio p WHERE p.ownerId=? AND p.isActive=1');
    $stmt->execute([$user['id']]);
    $summary = $stmt->fetch();
    $stmt2 = $db->prepare('SELECT SUM(p.totalQty*pc.lastPrice) AS cv FROM st_portfolio p JOIN st_pricecache pc ON pc.symbol=p.symbol WHERE p.ownerId=? AND p.isActive=1');
    $stmt2->execute([$user['id']]);
    $cv = $stmt2->fetch();
    $ti = round(floatval($summary['ti']??0),2);
    $curVal = round(floatval($cv['cv']??0),2);
    $pl = round($curVal-$ti,2);
    $plPct = $ti>0 ? round(($pl/$ti)*100,2) : 0;
    $sigStmt = $db->prepare('SELECT signalType,COUNT(*) AS cnt FROM st_signals WHERE ownerId=? AND isRead=0 GROUP BY signalType');
    $sigStmt->execute([$user['id']]);
    $sigCounts=['BUY'=>0,'SELL'=>0];
    foreach($sigStmt->fetchAll() as $sig) $sigCounts[$sig['signalType']]=(int)$sig['cnt'];
    jsonOk(['totalInvested'=>$ti,'currentValue'=>$curVal,'totalPL'=>$pl,'totalPLPct'=>$plPct,
            'stockCount'=>(int)($summary['sc']??0),'totalQty'=>(int)($summary['tq']??0),
            'buySignals'=>$sigCounts['BUY'],'sellSignals'=>$sigCounts['SELL']]);
    exit;
}

// ── DELETE M1 CYCLE ───────────────────────────────────────────
if ($action === 'deleteM1Cycle') {
    $user = requireAuth();
    $db   = getDB();
    $body = json_decode(file_get_contents('php://input'),true) ?? [];
    $m1Id = trim($body['m1Id'] ?? '');
    if (!$m1Id) jsonError('M1 ID required');

    // Fetch cycle + portfolio info in one query
    $chk = $db->prepare('SELECT m.portfolioId FROM st_m1tracker m
                          JOIN st_portfolio p ON p.id=m.portfolioId AND p.ownerId=?
                          WHERE m.id=?');
    $chk->execute([$user['id'], $m1Id]);
    $m1 = $chk->fetch();
    if (!$m1) jsonError('Not found', 403);

    $portfolioId = $m1['portfolioId'];
    $db->prepare("UPDATE st_m1tracker SET status='done' WHERE id=?")->execute([$m1Id]);

    // Auto-recreate a fresh cycle from the current last buy rate
    $pStmt = $db->prepare('SELECT lastBuyPrice, buyCount, IFNULL(m2HighestPrice,0) AS m2 FROM st_portfolio WHERE id=?');
    $pStmt->execute([$portfolioId]);
    $port = $pStmt->fetch();
    if ($port && floatval($port['lastBuyPrice']) > 0) {
        $settings = getSettings($user['id']);
        createM1Cycle($portfolioId, $user['id'], floatval($port['lastBuyPrice']), intval($port['buyCount']), $settings, floatval($port['m2']));
        jsonOk([], 'M1 reset — new cycle started from buy rate ₹'.number_format($port['lastBuyPrice'],2));
    } else {
        jsonOk([], 'M1 cycle removed');
    }
    exit;
}

// ── ADMIN ─────────────────────────────────────────────────────
if (in_array($action, ['getUsers','toggleUser'])) {
    $user = requireAdmin();
    $db   = getDB();
    if ($action === 'getUsers') {
        $stmt = $db->prepare('SELECT id,name,email,role,isActive,lastLogin,createdAt FROM st_users ORDER BY createdAt DESC');
        $stmt->execute(); jsonOk($stmt->fetchAll());
    }
    if ($action === 'toggleUser') {
        requireSuperAdmin();
        $body = json_decode(file_get_contents('php://input'),true) ?? [];
        $tid  = trim($body['userId']??'');
        if (!$tid) jsonError('User ID required');
        $chk = $db->prepare('SELECT role FROM st_users WHERE id=?'); $chk->execute([$tid]);
        $t = $chk->fetch();
        if ($t['role']==='superadmin') jsonError('Cannot deactivate superadmin');
        $db->prepare('UPDATE st_users SET isActive=1-isActive WHERE id=?')->execute([$tid]);
        jsonOk([], 'Toggled');
    }
    exit;
}
