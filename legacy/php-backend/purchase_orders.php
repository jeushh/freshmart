<?php
/**
 * =====================================================================================
 * backend/purchase_orders.php — PURCHASE ORDER READ ADAPTER
 * =====================================================================================
 * GET-only, requires inventory.manage or restock.approve. Returns purchase_orders with
 * their line items (purchase_order_items), supplier, and linked restock request.
 * Optional ?status= filter.
 *
 *   /backend/purchase_orders.php                -> all POs, newest first
 *   /backend/purchase_orders.php?status=Ordered  -> only Ordered POs
 *   /backend/purchase_orders.php?id=5            -> single PO with items
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requireAnyPermission(['inventory.manage', 'restock.approve']);

const PO_VALID_STATUSES = ['Pending', 'Approved', 'Ordered', 'Partially Received', 'Fully Received', 'Cancelled'];

try {
    $statusFilter = trim($_GET['status'] ?? '');
    $idFilter     = isset($_GET['id']) && $_GET['id'] !== '' ? (int) $_GET['id'] : null;

    if ($statusFilter !== '' && !in_array($statusFilter, PO_VALID_STATUSES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid status filter.']);
        exit;
    }

    $sql = "SELECT po.*, s.name AS supplier_name, rr.ref_number AS restock_ref_number
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN restock_requests rr ON rr.id = po.restock_request_id";
    $where = [];
    $params = [];
    if ($idFilter !== null) { $where[] = 'po.id = :id'; $params[':id'] = $idFilter; }
    if ($statusFilter !== '') { $where[] = 'po.status = :status'; $params[':status'] = $statusFilter; }
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY po.created_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $poRows = $stmt->fetchAll();

    $itemsStmt = $pdo->prepare(
        'SELECT poi.*, p.name AS product_name, p.emoji
         FROM purchase_order_items poi
         LEFT JOIN products p ON p.id = poi.product_id
         WHERE poi.purchase_order_id = :po_id'
    );

    $purchaseOrders = array_map(static function (array $po) use ($itemsStmt): array {
        $itemsStmt->execute([':po_id' => (int) $po['id']]);
        $items = array_map(static function (array $i): array {
            return [
                'id'                => (int) $i['id'],
                'product_id'        => $i['product_id'] !== null ? (int) $i['product_id'] : null,
                'sku'               => $i['sku'],
                'product_name'      => $i['product_name'] ?? $i['sku'],
                'emoji'             => $i['emoji'] ?? '🛒',
                'quantity_ordered'  => (int) $i['quantity_ordered'],
                'quantity_received' => (int) $i['quantity_received'],
                'unit_cost'         => (float) $i['unit_cost'],
            ];
        }, $itemsStmt->fetchAll());

        return [
            'id'                     => (int) $po['id'],
            'po_number'              => $po['po_number'],
            'restock_request_id'     => $po['restock_request_id'] !== null ? (int) $po['restock_request_id'] : null,
            'restock_ref_number'     => $po['restock_ref_number'],
            'supplier_id'            => $po['supplier_id'] !== null ? (int) $po['supplier_id'] : null,
            'supplier_name'          => $po['supplier_name'],
            'order_date'             => $po['order_date'],
            'expected_delivery_date' => $po['expected_delivery_date'],
            'notes'                  => $po['notes'],
            'status'                 => $po['status'],
            'created_at'             => $po['created_at'],
            'received_at'            => $po['received_at'],
            'items'                  => $items,
        ];
    }, $poRows);

    echo json_encode(['ok' => true, 'purchase_orders' => $purchaseOrders]);

} catch (Throwable $e) {
    error_log('purchase_orders.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
}
