<?php
/**
 * =====================================================================================
 * backend/inventory_movements.php — INVENTORY MOVEMENT HISTORY READ ADAPTER
 * =====================================================================================
 * GET-only, requires inventory.manage. Optional ?product_id= or ?sku= filter, plus
 * ?type= (movement_type) filter. Supports the "Stock Movement" report.
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

requirePermission('inventory.manage');

try {
    $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int) $_GET['product_id'] : null;
    $sku       = trim((string) ($_GET['sku'] ?? ''));
    $type      = trim((string) ($_GET['type'] ?? ''));

    $where  = [];
    $params = [];
    if ($productId !== null) { $where[] = 'm.product_id = :pid'; $params[':pid'] = $productId; }
    if ($sku !== '')          { $where[] = 'm.sku = :sku';       $params[':sku'] = $sku; }
    if ($type !== '')         { $where[] = 'm.movement_type = :type'; $params[':type'] = $type; }

    $sql = 'SELECT m.id, m.product_id, m.sku, p.name AS product_name, m.movement_type, m.quantity,
                   m.previous_stock, m.new_stock, m.reference_id, m.performed_by, m.notes, m.created_at
            FROM inventory_movements m
            LEFT JOIN products p ON p.id = m.product_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY m.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = array_map(static function (array $r): array {
        $r['id']             = (int) $r['id'];
        $r['product_id']     = (int) $r['product_id'];
        $r['quantity']       = (int) $r['quantity'];
        $r['previous_stock'] = (int) $r['previous_stock'];
        $r['new_stock']      = (int) $r['new_stock'];
        return $r;
    }, $stmt->fetchAll());

    echo json_encode(['ok' => true, 'movements' => $rows]);
} catch (Throwable $e) {
    error_log('inventory_movements.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load inventory movements.']);
}
