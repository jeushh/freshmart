<?php
declare(strict_types=1);
/**
 * backend/accounts_payable.php — ACCOUNTS PAYABLE READ ADAPTER (GET-only, requires finance.manage)
 * Auto-flags rows past their due_date as 'Overdue' (computed on read; does not persist
 * the transition since "today" changes every request — status column keeps Unpaid/
 * Partially Paid/Paid authoritative from payments, Overdue is a display-time derivation).
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
    $sql = "SELECT ap.*, s.name AS supplier_name, po.po_number
            FROM accounts_payable ap
            LEFT JOIN suppliers s ON s.id = ap.supplier_id
            LEFT JOIN purchase_orders po ON po.id = ap.purchase_order_id";
    $params = [];
    if ($status !== '') { $sql .= ' WHERE ap.status = :s'; $params[':s'] = $status; }
    $sql .= ' ORDER BY ap.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $today = date('Y-m-d');
    $rows = array_map(static function (array $r) use ($today): array {
        $r['id']            = (int) $r['id'];
        $r['total_amount']  = (float) $r['total_amount'];
        $r['amount_paid']   = (float) $r['amount_paid'];
        $r['balance']       = round($r['total_amount'] - $r['amount_paid'], 2);
        $r['display_status'] = $r['status'];
        if ($r['status'] !== 'Paid' && !empty($r['due_date']) && $r['due_date'] < $today) {
            $r['display_status'] = 'Overdue';
        }
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'payables' => $rows]);
} catch (Throwable $e) {
    error_log('accounts_payable.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load accounts payable.']);
}
