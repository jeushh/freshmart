<?php
/**
 * =====================================================================================
 * backend/purchase_order_create.php — MANUAL PURCHASE ORDER CREATE ADAPTER
 * =====================================================================================
 * POST-only, requires inventory.manage. Creates a Purchase Order directly (not via a
 * restock request) — e.g. bulk-ordering several SKUs from one supplier at once.
 * Supports multiple products in one PO via purchase_order_items.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inventory_finance_shared.php';

requirePermission('inventory.manage');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $supplierId       = isset($post['supplier_id']) && $post['supplier_id'] !== '' ? (int) $post['supplier_id'] : null;
    $expectedDelivery = trim((string) ($post['expected_delivery_date'] ?? ''));
    $notes            = trim((string) ($post['notes'] ?? ''));
    $items            = is_array($post['items'] ?? null) ? $post['items'] : [];

    if (empty($items)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'At least one item (product_id + quantity) is required.']);
        exit;
    }

    $pdo->beginTransaction();

    $validatedItems = [];
    $productStmt = $pdo->prepare('SELECT id, sku, cost_price FROM products WHERE id = :id');
    foreach ($items as $item) {
        $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $qty       = isset($item['quantity']) ? (int) $item['quantity'] : 0;
        if ($productId <= 0 || $qty <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Each item requires a valid product_id and quantity > 0.']);
            exit;
        }
        $productStmt->execute([':id' => $productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "Product #{$productId} not found."]);
            exit;
        }
        $unitCost = isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : (float) $product['cost_price'];
        $validatedItems[] = ['product_id' => $productId, 'sku' => $product['sku'], 'qty' => $qty, 'unit_cost' => $unitCost];
    }

    $poNumber = generatePoNumber();
    // Header sku/quantity_ordered kept as a convenience summary of the first line item.
    $pdo->prepare(
        "INSERT INTO purchase_orders (po_number, supplier_id, sku, quantity_ordered, order_date, expected_delivery_date, notes, status)
         VALUES (:po, :sup, :sku, :qty, datetime('now'), :eta, :notes, 'Pending')"
    )->execute([
        ':po' => $poNumber, ':sup' => $supplierId, ':sku' => $validatedItems[0]['sku'],
        ':qty' => $validatedItems[0]['qty'], ':eta' => $expectedDelivery ?: null, ':notes' => $notes ?: null,
    ]);
    $poId = (int) $pdo->lastInsertId();

    $itemInsert = $pdo->prepare(
        'INSERT INTO purchase_order_items (purchase_order_id, product_id, sku, quantity_ordered, quantity_received, unit_cost)
         VALUES (:po, :pid, :sku, :qty, 0, :cost)'
    );
    foreach ($validatedItems as $vi) {
        $itemInsert->execute([
            ':po' => $poId, ':pid' => $vi['product_id'], ':sku' => $vi['sku'], ':qty' => $vi['qty'], ':cost' => $vi['unit_cost'],
        ]);
    }

    logAudit($pdo, 'Purchase order created (manual)', 'purchase_order', (string) $poId, $poNumber . ' — ' . count($validatedItems) . ' item(s)');
    $pdo->commit();

    echo json_encode(['ok' => true, 'id' => $poId, 'po_number' => $poNumber]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('purchase_order_create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create purchase order.']);
}
