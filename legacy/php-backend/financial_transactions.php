<?php
/**
 * =====================================================================================
 * backend/financial_transactions.php — FINANCIAL TRANSACTIONS LEDGER READ ADAPTER
 * =====================================================================================
 * GET-only, requires finance.manage. Optional ?type=, ?from=, ?to= (YYYY-MM-DD) filters.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requirePermission('finance.manage');

try {
    $type = trim((string) ($_GET['type'] ?? ''));
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));

    $where = [];
    $params = [];
    if ($type !== '') { $where[] = 'transaction_type = :type'; $params[':type'] = $type; }
    if ($from !== '') { $where[] = "date(created_at) >= :from"; $params[':from'] = $from; }
    if ($to !== '')   { $where[] = "date(created_at) <= :to";   $params[':to'] = $to; }

    $sql = 'SELECT * FROM financial_transactions';
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY id DESC LIMIT 1000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(static function (array $r): array {
        $r['id']     = (int) $r['id'];
        $r['amount'] = (float) $r['amount'];
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'transactions' => $rows]);
} catch (Throwable $e) {
    error_log('financial_transactions.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load financial transactions.']);
}
