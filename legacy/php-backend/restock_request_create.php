<?php
/**
 * =====================================================================================
 * backend/restock_request_create.php — RESTOCK REQUEST CREATE ADAPTER
 * =====================================================================================
 * POST-only, requires restock.request (Cashier, Inventory Staff, or Admin).
 * The "[REQUEST RESTOCK]" button on the Low Stock screen posts here.
 *
 *   recommended_quantity = max_stock - current_stock
 *
 * requested_quantity defaults to the recommended amount but the user may override it
 * (still validated: must be > 0). One open (non-terminal) restock request per product
 * is enforced to avoid duplicate/competing requests for the same SKU.
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

requirePermission('restock.request');
requireCsrfToken();

const OPEN_STATUSES = ['Pending Approval', 'Approved', 'Purchase Order Created', 'Ordered', 'Partially Received'];
const VALID_PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $productId = isset($post['product_id']) ? (int) $post['product_id'] : 0;
    $priority  = trim((string) ($post['priority'] ?? 'Normal'));
    $reason    = trim((string) ($post['reason'] ?? ''));
    $notes     = trim((string) ($post['notes'] ?? ''));
    $requestedQtyInput = isset($post['requested_quantity']) && $post['requested_quantity'] !== ''
        ? (int) $post['requested_quantity'] : null;

    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid product_id is required.']);
        exit;
    }
    if (!in_array($priority, VALID_PRIORITIES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'priority must be Low, Normal, High, or Urgent.']);
        exit;
    }

    $pdo->beginTransaction();

    $productStmt = $pdo->prepare('SELECT id, name, sku, stock_quantity, reorder_level, max_stock, supplier_id FROM products WHERE id = :id');
    $productStmt->execute([':id' => $productId]);
    $product = $productStmt->fetch();

    if (!$product) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Product not found.']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count(OPEN_STATUSES), '?'));
    $dupStmt = $pdo->prepare(
        "SELECT id FROM restock_requests WHERE product_id = ? AND status IN ($placeholders) LIMIT 1"
    );
    $dupStmt->execute(array_merge([$productId], OPEN_STATUSES));
    if ($dupStmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'An open restock request already exists for this product.']);
        exit;
    }

    $currentStock = (int) $product['stock_quantity'];
    $reorderLevel = (int) $product['reorder_level'];
    $maxStock     = (int) $product['max_stock'];
    $recommended  = max(0, $maxStock - $currentStock);
    $requestedQty = $requestedQtyInput !== null ? $requestedQtyInput : $recommended;

    if ($requestedQty <= 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'requested_quantity must be greater than zero.']);
        exit;
    }

    $refNumber = generateRestockRefNumber();
    $requestedBy = $_SESSION['admin_username'] ?? 'unknown';

    $pdo->prepare(
        'INSERT INTO restock_requests
            (ref_number, product_id, sku, current_stock, reorder_level, max_stock,
             recommended_quantity, requested_quantity, supplier_id, requested_by, priority, reason, notes, status)
         VALUES (:ref, :pid, :sku, :cur, :reorder, :max, :rec, :req, :sup, :by, :prio, :reason, :notes, \'Pending Approval\')'
    )->execute([
        ':ref' => $refNumber, ':pid' => $productId, ':sku' => $product['sku'], ':cur' => $currentStock,
        ':reorder' => $reorderLevel, ':max' => $maxStock, ':rec' => $recommended, ':req' => $requestedQty,
        ':sup' => $product['supplier_id'], ':by' => $requestedBy, ':prio' => $priority,
        ':reason' => $reason ?: null, ':notes' => $notes ?: null,
    ]);
    $newId = (int) $pdo->lastInsertId();

    logAudit($pdo, 'Restock request created', 'restock_request', (string) $newId, "{$product['name']} qty {$requestedQty}");

    $pdo->commit();
    echo json_encode(['ok' => true, 'id' => $newId, 'ref_number' => $refNumber, 'recommended_quantity' => $recommended]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('restock_request_create.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create restock request.']);
}
