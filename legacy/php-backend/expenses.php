<?php
declare(strict_types=1);
/**
 * backend/expenses.php — EXPENSE RECORDS READ ADAPTER (GET-only, requires finance.manage)
 * Optional ?status= filter.
 */
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
    $status = trim((string) ($_GET['status'] ?? ''));
    $sql = 'SELECT * FROM expenses';
    $params = [];
    if ($status !== '') { $sql .= ' WHERE status = :s'; $params[':s'] = $status; }
    $sql .= ' ORDER BY id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map(static function (array $r): array {
        $r['id'] = (int) $r['id'];
        $r['amount'] = (float) $r['amount'];
        return $r;
    }, $stmt->fetchAll());
    echo json_encode(['ok' => true, 'expenses' => $rows]);
} catch (Throwable $e) {
    error_log('expenses.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load expenses.']);
}
