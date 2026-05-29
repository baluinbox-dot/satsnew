<?php
// ============================================================
// api/config.php — DB connection + shared helpers
// NOTE: No header() calls here — headers are set in index.php only
// So this file can be safely included from setup.php too
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'u900311706_stas');
define('DB_USER', 'u900311706_stas');
define('DB_PASS', 'Stas@2706');   // ← change this

define('SUPERADMIN_EMAIL', 'baluinbox@gmail.com');
define('APP_NAME', 'STAS');
define('APP_VERSION', '1.0.0');

// --- DB connection (singleton) ---
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // Only call jsonError if we are in API context (headers already set)
            if (defined('STAS_API_CONTEXT')) {
                jsonError('Database connection failed: ' . $e->getMessage(), 500);
            } else {
                throw $e; // Let setup.php handle it
            }
        }
    }
    return $pdo;
}

// --- Response helpers (used only in API context) ---
function jsonOk($data = [], string $message = 'success'): void {
    echo json_encode(['status' => 'ok', 'message' => $message, 'data' => $data]);
    exit;
}

function jsonError(string $message = 'error', int $code = 400): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message, 'data' => null]);
    exit;
}

// --- Generate a unique ID ---
function genId(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// --- Session: get logged-in user or die ---
function requireAuth(): array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['userId'])) {
        jsonError('Not authenticated', 401);
    }
    return [
        'id'    => $_SESSION['userId'],
        'email' => $_SESSION['userEmail'],
        'role'  => $_SESSION['userRole'],
        'name'  => $_SESSION['userName'],
    ];
}

function requireSuperAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'superadmin') jsonError('Access denied', 403);
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if (!in_array($user['role'], ['superadmin', 'admin'])) jsonError('Access denied', 403);
    return $user;
}

function getSettings(string $ownerId): array {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM st_settings WHERE ownerId = ? LIMIT 1');
    $stmt->execute([$ownerId]);
    $row  = $stmt->fetch();
    if ($row) return $row;
    return [
        'activationPct'  => 10.00,
        'pullbackPct'    => 5.00,
        'sellTargetPct'  => 30.00,
        'maxBuyCount'    => 5,
        'niftyFilterOn'  => 0,
        'dma200FilterOn' => 0,
    ];
}

function recalcPortfolio(string $portfolioId): void {
    $db   = getDB();
    $stmt = $db->prepare('
        SELECT SUM(buyPrice * qty) AS totalValue, SUM(qty) AS totalQty, COUNT(*) AS buyCount
        FROM st_transactions WHERE portfolioId = ?
    ');
    $stmt->execute([$portfolioId]);
    $row = $stmt->fetch();

    $avgPrice        = ($row['totalQty'] > 0) ? round($row['totalValue'] / $row['totalQty'], 2) : 0;
    $totalInvestment = round($row['totalValue'], 2);
    $totalQty        = (int)$row['totalQty'];
    $buyCount        = (int)$row['buyCount'];

    $db->prepare('
        UPDATE st_portfolio
        SET avgPrice = ?, totalQty = ?, totalInvestment = ?, buyCount = ?
        WHERE id = ?
    ')->execute([$avgPrice, $totalQty, $totalInvestment, $buyCount, $portfolioId]);
}

function createM1Cycle(string $portfolioId, string $ownerId, float $buyPrice, int $cycleNum, array $settings, float $m2AtCycleStart = 0): void {
    $db              = getDB();
    $activationPrice = round($buyPrice * (1 + $settings['activationPct'] / 100), 2);
    $triggerPrice    = round($activationPrice * (1 - $settings['pullbackPct'] / 100), 2);

    $db->prepare('
        INSERT INTO st_m1tracker
            (id, ownerId, portfolioId, cycleNumber, baseBuyPrice, activationPrice, triggerPrice, status, m2AtCycleStart)
        VALUES (?, ?, ?, ?, ?, ?, ?, "waiting", ?)
    ')->execute([genId(), $ownerId, $portfolioId, $cycleNum, $buyPrice, $activationPrice, $triggerPrice, $m2AtCycleStart]);
}

function createSignal(string $portfolioId, string $ownerId, string $type, float $price,
                      float $avgPrice, float $profitPct, string $msg): void {
    $db   = getDB();
    $stmt = $db->prepare('
        SELECT id FROM st_signals
        WHERE portfolioId = ? AND signalType = ? AND isActioned = 0
          AND signalDate > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        LIMIT 1
    ');
    $stmt->execute([$portfolioId, $type]);
    if ($stmt->fetch()) return;

    $db->prepare('
        INSERT INTO st_signals (id, ownerId, portfolioId, signalType, signalPrice, avgPrice, profitPct, message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([genId(), $ownerId, $portfolioId, $type, $price, $avgPrice, $profitPct, $msg]);
}
