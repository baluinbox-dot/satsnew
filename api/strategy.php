<?php
// ============================================================
// api/strategy.php — v2.2 M1 Retroactive Activation Fix
//
// BUY SIGNAL fires when:
//   M1 activated AND CMP <= trailingHigh × (1 - pullPct%)
//   i.e. price dropped pullPct% from the highest point seen
//
// trailingHigh only moves UP, never down.
//
// FIX v2.2: M1 now activates retroactively using m2HighestPrice
//   If price spiked above activation threshold between refreshes,
//   m2HighestPrice proves it happened — M1 activates correctly.
// ============================================================

$user = requireAuth();
$db   = getDB();

function sc3(PDO $db, string $t, string $c): bool {
    return $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")->rowCount() > 0;
}
function se3(PDO $db): void {
    $p = [
        'firstBuyPrice'  => 'DECIMAL(10,2) DEFAULT 0',
        'lastBuyPrice'   => 'DECIMAL(10,2) DEFAULT 0',
        'm2HighestPrice' => 'DECIMAL(10,2) DEFAULT 0',
        'm2UpdatedAt'    => 'DATETIME DEFAULT NULL'
    ];
    foreach ($p as $c => $d)
        if (!sc3($db, 'st_portfolio', $c))
            $db->exec("ALTER TABLE st_portfolio ADD COLUMN `{$c}` {$d}");

    $m = [
        'customActivationPrice' => 'DECIMAL(10,2) DEFAULT NULL',
        'customTriggerPrice'    => 'DECIMAL(10,2) DEFAULT NULL',
        'isEdited'              => 'TINYINT(1) DEFAULT 0',
        'm2AtCycleStart'        => 'DECIMAL(10,2) DEFAULT 0',
    ];
    foreach ($m as $c => $d)
        if (!sc3($db, 'st_m1tracker', $c))
            $db->exec("ALTER TABLE st_m1tracker ADD COLUMN `{$c}` {$d}");

    if (!sc3($db, 'st_pricecache', 'changePercent'))
        $db->exec("ALTER TABLE st_pricecache ADD COLUMN changePercent DECIMAL(6,2) DEFAULT 0");
}

se3($db);
$hasC    = sc3($db, 'st_m1tracker', 'customActivationPrice');
$hasM2BS = sc3($db, 'st_m1tracker', 'm2AtCycleStart');

switch ($action) {

    // ══════════════════════════════════════════════════════════
    case 'runStrategyCheck':
    // ══════════════════════════════════════════════════════════
        $settings = getSettings($user['id']);
        $pullPct  = floatval($settings['pullbackPct']);
        $actPct   = floatval($settings['activationPct']);
        $sellPct  = floatval($settings['sellTargetPct']);

        $caSel = $hasC
            ? 'IFNULL(m.customActivationPrice,0) AS customActivationPrice, IFNULL(m.isEdited,0) AS isEdited,'
            : '0 AS customActivationPrice, 0 AS isEdited,';
        $m2bsSel = $hasM2BS ? 'IFNULL(m.m2AtCycleStart,0) AS m2AtCycleStart,' : '0 AS m2AtCycleStart,';

        $stmt = $db->prepare("
            SELECT p.id AS portfolioId, p.symbol, p.avgPrice, p.totalQty, p.buyCount,
                   IFNULL(p.lastBuyPrice,0)   AS lastBuyPrice,
                   IFNULL(p.m2HighestPrice,0) AS m2HighestPrice,
                   m.id AS trackerId, m.activationPrice,
                   IFNULL(m.highestPrice,0)   AS highestPrice,
                   {$caSel}
                   {$m2bsSel}
                   m.m1Activated, m.status AS m1Status, m.cycleNumber, m.baseBuyPrice,
                   pc.lastPrice AS cmp
            FROM st_portfolio p
            LEFT JOIN st_m1tracker m ON m.portfolioId=p.id AND m.status NOT IN ('done','sold')
            LEFT JOIN st_pricecache pc ON pc.symbol=p.symbol
            WHERE p.ownerId=? AND p.isActive=1
        ");
        $stmt->execute([$user['id']]);
        $stocks  = $stmt->fetchAll();
        $results = [];

        foreach ($stocks as $s) {
            $cmp         = floatval($s['cmp']          ?? 0);
            $portfolioId = $s['portfolioId'];
            $avgPrice    = floatval($s['avgPrice']     ?? 0);
            $lastBuyRate = floatval($s['lastBuyPrice'] ?? 0);
            $trackerId   = $s['trackerId']             ?? null;

            if (!$cmp) {
                $results[] = ['symbol' => $s['symbol'], 'action' => 'no_price'];
                continue;
            }

            $actions = [];

            // ── M2: all-time high tracker, never resets ───────────
            $curM2 = floatval($s['m2HighestPrice'] ?? 0);
            if ($cmp > $curM2) {
                $db->prepare('UPDATE st_portfolio SET m2HighestPrice=?, m2UpdatedAt=NOW() WHERE id=?')
                   ->execute([$cmp, $portfolioId]);
                $curM2     = $cmp; // use fresh value below
                $actions[] = 'm2_up';
            }

            // ── M1 trailing logic ─────────────────────────────────
            if ($trackerId) {
                $m1Activated = (bool)$s['m1Activated'];
                $m1Status    = $s['m1Status'];
                $currentHigh = floatval($s['highestPrice'] ?? 0);

                // Activation threshold — user-edited or auto-calculated
                $actThresh = ($s['isEdited'] && $s['customActivationPrice'] > 0)
                           ? floatval($s['customActivationPrice'])
                           : floatval($s['activationPrice'] ?? 0);

                // ── STEP 1: Activate M1 ───────────────────────────
                //
                // FIX v2.2: Also check m2HighestPrice (all-time high).
                // If price spiked above activation between two refreshes,
                // CMP would be below threshold now but m2High proves it
                // was there. Retroactively activate M1 using that peak.
                //
                $m2High             = floatval($curM2);   // freshest m2 value
                $m2BaseAtCycleStart = floatval($s['m2AtCycleStart'] ?? 0);
                // Only retroactively activate if m2 grew BEYOND the baseline recorded when
                // the current cycle was created — prevents old m2 highs triggering new cycles
                $alreadyCrossed = ($actThresh > 0 && $m2High >= $actThresh && $m2High > $m2BaseAtCycleStart);

                if (!$m1Activated && $actThresh > 0 && ($cmp >= $actThresh || $alreadyCrossed)) {

                    // Use the highest known price as trail start:
                    //   live cross  → max(cmp, m2High)
                    //   historical  → m2High  (price was there, now below)
                    $activationHigh = ($cmp >= $actThresh)
                                    ? max($cmp, $m2High)
                                    : $m2High;

                    $currentHigh = $activationHigh;
                    $newTrig     = round($activationHigh * (1 - $pullPct / 100), 2);

                    $db->prepare('UPDATE st_m1tracker
                                  SET m1Activated=1, m1ActivatedAt=NOW(), status="activated",
                                      highestPrice=?, triggerPrice=?
                                  WHERE id=?')
                       ->execute([$activationHigh, $newTrig, $trackerId]);

                    $m1Activated = true;
                    $m1Status    = 'activated';
                    $actions[]   = $alreadyCrossed
                                 ? 'm1_activated_via_m2_history'
                                 : 'm1_activated';
                }

                // ── STEP 2: Update trailing high (only goes up) ───
                if ($m1Activated && $cmp > $currentHigh) {
                    $currentHigh = $cmp;
                    $newTrig     = round($cmp * (1 - $pullPct / 100), 2);
                    $db->prepare('UPDATE st_m1tracker SET highestPrice=?, triggerPrice=? WHERE id=?')
                       ->execute([$currentHigh, $newTrig, $trackerId]);
                    $actions[] = 'm1_trail_up';
                }

                // ── STEP 3: BUY signal — dropped pullPct% from trail
                if ($m1Activated && $currentHigh > 0) {
                    $buyZone = round($currentHigh * (1 - $pullPct / 100), 2);

                    // Fire only when status = 'activated' (not already triggered)
                    if ($m1Status === 'activated'
                        && $cmp <= $buyZone
                        && $s['buyCount'] < $settings['maxBuyCount']
                    ) {
                        $dropPct = round((($currentHigh - $cmp) / $currentHigh) * 100, 1);
                        $pp      = $avgPrice > 0
                                 ? round((($cmp - $avgPrice) / $avgPrice) * 100, 2)
                                 : 0;
                        $msg = "{$s['symbol']} M1 pullback! "
                             . "Peak ₹" . number_format($currentHigh, 2)
                             . " → CMP ₹" . number_format($cmp, 2)
                             . " (−{$dropPct}% from peak). Buy signal.";

                        createSignal($portfolioId, $user['id'], 'BUY', $cmp, $avgPrice, $pp, $msg);
                        $db->prepare("UPDATE st_m1tracker SET status='triggered' WHERE id=?")
                           ->execute([$trackerId]);
                        $actions[] = 'buy_signal_m1';
                    }

                    // Reset to 'activated' if price recovers above trail high
                    // (allows next pullback cycle to fire again)
                    if ($m1Status === 'triggered' && $cmp > $currentHigh) {
                        $db->prepare("UPDATE st_m1tracker SET status='activated' WHERE id=?")
                           ->execute([$trackerId]);
                        $actions[] = 'm1_reset_to_activated';
                    }
                }
            }

            // ── Drop-20: 20% below last buy rate ──────────────────
            if ($lastBuyRate > 0) {
                $drop20 = round($lastBuyRate * 0.80, 2);
                if ($cmp <= $drop20 && $s['buyCount'] < $settings['maxBuyCount']) {
                    $pp  = $avgPrice > 0
                         ? round((($cmp - $avgPrice) / $avgPrice) * 100, 2)
                         : 0;
                    $msg = "{$s['symbol']} dropped 20% from last buy ₹"
                         . number_format($lastBuyRate, 2)
                         . " | CMP ₹" . number_format($cmp, 2);
                    createSignal($portfolioId, $user['id'], 'BUY', $cmp, $avgPrice, $pp, $msg);
                    $actions[] = 'buy_drop20';
                }
            }

            // ── Sell signal: avg cost + sellPct% ──────────────────
            if ($avgPrice > 0) {
                $sellTgt = round($avgPrice * (1 + $sellPct / 100), 2);
                $pp      = round((($cmp - $avgPrice) / $avgPrice) * 100, 2);
                if ($cmp >= $sellTgt) {
                    $msg = "{$s['symbol']} +{$sellPct}% target! "
                         . "Avg ₹" . number_format($avgPrice, 2)
                         . " | CMP ₹" . number_format($cmp, 2)
                         . " | P&L +" . number_format($pp, 1) . "%.";
                    createSignal($portfolioId, $user['id'], 'SELL', $cmp, $avgPrice, $pp, $msg);
                    $actions[] = 'sell';
                }
            }

            $results[] = ['symbol' => $s['symbol'], 'cmp' => $cmp, 'actions' => $actions];
        }

        jsonOk(['checked' => count($results), 'results' => $results]);
        break;

    // ══════════════════════════════════════════════════════════
    case 'getM1Tracker':
    // ══════════════════════════════════════════════════════════
        $caSel = $hasC
            ? 'IFNULL(m.customActivationPrice,0) AS customActivationPrice, IFNULL(m.isEdited,0) AS isEdited,'
            : '0 AS customActivationPrice, 0 AS isEdited,';
        $m2bsSel2 = $hasM2BS ? 'IFNULL(m.m2AtCycleStart,0) AS m2AtCycleStart,' : '0 AS m2AtCycleStart,';

        $stmt = $db->prepare("
            SELECT m.id, m.portfolioId, m.cycleNumber, m.baseBuyPrice,
                   m.activationPrice, m.triggerPrice,
                   IFNULL(m.highestPrice,0) AS highestPrice,
                   m.m1Activated, m.status,
                   {$caSel}
                   {$m2bsSel2}
                   p.symbol, p.avgPrice, p.totalQty, p.buyCount,
                   IFNULL(p.firstBuyPrice,0)  AS firstBuyPrice,
                   IFNULL(p.lastBuyPrice,0)   AS lastBuyPrice,
                   IFNULL(p.m2HighestPrice,0) AS m2HighestPrice,
                   pc.lastPrice AS cmp, pc.changePercent
            FROM st_m1tracker m
            JOIN st_portfolio p ON p.id=m.portfolioId
            LEFT JOIN st_pricecache pc ON pc.symbol=p.symbol
            WHERE m.ownerId=? AND m.status NOT IN ('done','sold')
            ORDER BY p.symbol ASC
        ");
        $stmt->execute([$user['id']]);
        $rows     = $stmt->fetchAll();
        $settings = getSettings($user['id']);

        foreach ($rows as &$row) {
            $cmp         = floatval($row['cmp']         ?? 0);
            $base        = floatval($row['baseBuyPrice'] ?? 0);
            $trailHigh   = floatval($row['highestPrice'] ?? 0);
            $m1Activated = (bool)$row['m1Activated'];
            $m2High      = floatval($row['m2HighestPrice'] ?? 0);

            // Effective activation price (user-edited or auto)
            $actP = ($row['isEdited'] && $row['customActivationPrice'] > 0)
                  ? floatval($row['customActivationPrice'])
                  : floatval($row['activationPrice'] ?? 0);

            // ── FIX v2.2 + v2.3: effective trail high for display ────
            // m2High must exceed its value at cycle creation (m2AtCycleStart)
            // to prove the price crossed activation AFTER the new buy.
            $m2BaseAtCycleStart = floatval($row['m2AtCycleStart'] ?? 0);
            $alreadyCrossed  = ($actP > 0 && $m2High >= $actP && $m2High > $m2BaseAtCycleStart);
            $effectiveHigh   = $m1Activated  ? $trailHigh
                             : ($alreadyCrossed ? $m2High : 0);

            // Trigger price — always computed from effective trail high
            $dynTrig = $effectiveHigh > 0
                     ? round($effectiveHigh * (1 - floatval($settings['pullbackPct']) / 100), 2)
                     : 0;

            $row['effectiveActivationPrice'] = $actP;
            $row['effectiveTriggerPrice']    = $dynTrig;
            $row['trailingHigh']             = $effectiveHigh;
            $row['sellTarget']               = round(
                floatval($row['avgPrice']) * (1 + $settings['sellTargetPct'] / 100), 2
            );

            // Activation progress 0–100% (before M1 fires)
            $range = $actP - $base;
            $row['activationProgress'] = (!$m1Activated && !$alreadyCrossed && $range > 0 && $cmp >= $base)
                ? min(100, round((($cmp - $base) / $range) * 100))
                : (($m1Activated || $alreadyCrossed) ? 100 : 0);

            // M1%: how far CMP is from trail high (negative = in buy zone)
            $row['m1Pct'] = ($effectiveHigh > 0 && $cmp > 0)
                ? round((($cmp - $effectiveHigh) / $effectiveHigh) * 100, 2)
                : null;

            // M2 data
            $fbp = floatval($row['firstBuyPrice'] ?? 0);
            $row['m2GainFromFirst'] = ($m2High > 0 && $fbp > 0)
                ? round((($m2High - $fbp) / $fbp) * 100, 2)
                : null;
            $row['m2Pct'] = ($cmp > 0 && $m2High > 0)
                ? round((($cmp - $m2High) / $m2High) * 100, 2)
                : null;
        }
        unset($row);

        jsonOk($rows);
        break;
}
