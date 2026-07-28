<?php
/**
 * =====================================================================================
 * backend/restock_requests.php — RESTOCK REQUEST READ ADAPTER
 * =====================================================================================
 * GET-only. Anyone who can create (restock.request) or approve (restock.approve)
 * restock requests can view the list. Optional ?status= filter.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAnyPermission(['restock.request', 'restock.approve', 'inventory.manage']);

try {
    $status = trim((string) ($_GET['status'] ?? ''));

    $sql = "SELECT rr.*, p.name AS product_name, p.emoji, s.name AS supplier_name,
                   po.id AS linked_po_id, po.po_number AS linked_po_number
            FROM restock_requests rr
            LEFT JOIN products p ON p.id = rr.product_id
            LEFT JOIN suppliers s ON s.id = rr.supplier_id
            LEFT JOIN purchase_orders po ON po.id = rr.purchase_order_id";
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE rr.status = :status';
        $params[':status'] = $status;
    }
    $sql .= ' ORDER BY rr.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(static function (array $r): array {
        $r['id']                   = (int) $r['id'];
        $r['product_id']           = (int) $r['product_id'];
        $r['current_stock']        = (int) $r['current_stock'];
        $r['reorder_level']        = (int) $r['reorder_level'];
        $r['max_stock']            = (int) $r['max_stock'];
        $r['recommended_quantity'] = (int) $r['recommended_quantity'];
        $r['requested_quantity']   = (int) $r['requested_quantity'];
        $r['supplier_id']          = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null;
        $r['purchase_order_id']    = $r['purchase_order_id'] !== null ? (int) $r['purchase_order_id'] : null;
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'requests' => $rows]);
} catch (Throwable $e) {
    error_log('restock_requests.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load restock requests.']);
}
