<?php
/**
 * =====================================================================================
 * backend/stock_receivings.php — STOCK RECEIVING HISTORY READ ADAPTER
 * =====================================================================================
 * GET-only, requires inventory.manage. Optional ?purchase_order_id= filter.
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
requirePermission('inventory.manage');

try {
    $poId = isset($_GET['purchase_order_id']) && $_GET['purchase_order_id'] !== '' ? (int) $_GET['purchase_order_id'] : null;

    $sql = "SELECT sr.*, po.po_number FROM stock_receivings sr LEFT JOIN purchase_orders po ON po.id = sr.purchase_order_id";
    $params = [];
    if ($poId !== null) {
        $sql .= ' WHERE sr.purchase_order_id = :po';
        $params[':po'] = $poId;
    }
    $sql .= ' ORDER BY sr.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $receivings = $stmt->fetchAll();

    $itemsStmt = $pdo->prepare(
        'SELECT sri.*, p.name AS product_name FROM stock_receiving_items sri
         LEFT JOIN products p ON p.id = sri.product_id
         WHERE sri.stock_receiving_id = :rid'
    );

    $result = array_map(static function (array $r) use ($itemsStmt): array {
        $itemsStmt->execute([':rid' => (int) $r['id']]);
        $r['id'] = (int) $r['id'];
        $r['purchase_order_id'] = (int) $r['purchase_order_id'];
        $r['items'] = array_map(static function (array $i): array {
            $i['id'] = (int) $i['id'];
            $i['received_quantity'] = (int) $i['received_quantity'];
            $i['damaged_quantity']  = (int) $i['damaged_quantity'];
            $i['rejected_quantity'] = (int) $i['rejected_quantity'];
            $i['unit_cost']         = (float) $i['unit_cost'];
            return $i;
        }, $itemsStmt->fetchAll());
        return $r;
    }, $receivings);

    echo json_encode(['ok' => true, 'receivings' => $result]);
} catch (Throwable $e) {
    error_log('stock_receivings.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load stock receivings.']);
}
