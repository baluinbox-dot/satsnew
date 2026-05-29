<?php
// ============================================================
// api/index.php — v1.5
// ============================================================
define('STAS_API_CONTEXT', true);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/config.php';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login': case 'logout': case 'register': case 'checkSession':
        require __DIR__ . '/auth.php'; break;

    case 'getPortfolio': case 'addStock': case 'deleteStock':
    case 'getBuyHistory': case 'updateM1M2':
        require __DIR__ . '/portfolio.php'; break;

    case 'getTransactions': case 'deleteTransaction':
    case 'getSignals': case 'markSignalRead': case 'markSignalActioned': case 'deleteSignal':
    case 'getSettings': case 'saveSettings':
    case 'getDashboard':
    case 'getUsers': case 'toggleUser':
    case 'deleteM1Cycle':
        require __DIR__ . '/misc.php'; break;

    case 'runStrategyCheck': case 'getM1Tracker':
        require __DIR__ . '/strategy.php'; break;

    case 'getPrices': case 'refreshPrices':
        require __DIR__ . '/market.php'; break;

    case 'searchStock':
        require __DIR__ . '/stock_search.php'; break;

    default:
        http_response_code(404);
        echo json_encode(['status'=>'error','message'=>"Unknown action: $action"]);
}
