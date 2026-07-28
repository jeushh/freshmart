<?php
/**
 * =====================================================================================
 * backend/restock_request_review.php — RESTOCK REQUEST APPROVAL ADAPTER
 * =====================================================================================
 * POST-only, requires restock.approve (Manager / Operations Manager / Admin).
 *
 *   decision = 'Rejected' -> requires rejection_reason, status -> Rejected. No PO.
 *   decision = 'Approved' -> status -> Approved, THEN a Purchase Order (status
 *              'Ordered') is created and linked back via restock_requests.purchase_order_id
 *              and purchase_orders.restock_request_id, and the restock request status
 *              advances to 'Purchase Order Created' — all inside one transaction so the
 *              approval and PO creation can never be split apart by a partial failure.
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

requirePermission('restock.approve');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $requestId        = isset($post['id']) ? (int) $post['id'] : 0;
    $decision         = trim((string) ($post['decision'] ?? ''));
    $rejectionReason  = trim((string) ($post['rejection_reason'] ?? ''));
    $expectedDelivery = trim((string) ($post['expected_delivery_date'] ?? ''));

    if ($requestId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id is required.']);
        exit;
    }
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'decision must be Approved or Rejected.']);
        exit;
    }
    if ($decision === 'Rejected' && $rejectionReason === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A rejection_reason is required when rejecting a restock request.']);
        exit;
    }

    $pdo->beginTransaction();

    $reqStmt = $pdo->prepare('SELECT * FROM restock_requests WHERE id = :id');
    $reqStmt->execute([':id' => $requestId]);
    $req = $reqStmt->fetch();

    if (!$req) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Restock request not found.']);
        exit;
    }
    if ($req['status'] !== 'Pending Approval') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Only a Pending Approval request can be reviewed (current status: {$req['status']})."]);
        exit;
    }

    $reviewerUsername = $_SESSION['admin_username'] ?? 'unknown';

    if ($decision === 'Rejected') {
        $pdo->prepare(
            "UPDATE restock_requests SET status='Rejected', reviewed_by=:by, reviewed_at=datetime('now'), review_notes=:notes WHERE id=:id"
        )->execute([':by' => $reviewerUsername, ':notes' => $rejectionReason, ':id' => $requestId]);

        logAudit($pdo, 'Restock request rejected', 'restock_request', (string) $requestId, $rejectionReason);
        $pdo->commit();
        echo json_encode(['ok' => true, 'status' => 'Rejected']);
        exit;
    }

    // ---- Approved: mark approved, then create the linked Purchase Order ----
    $pdo->prepare(
        "UPDATE restock_requests SET status='Approved', reviewed_by=:by, reviewed_at=datetime('now'), review_notes=:notes WHERE id=:id"
    )->execute([':by' => $reviewerUsername, ':notes' => null, ':id' => $requestId]);

    $productStmt = $pdo->prepare('SELECT cost_price FROM products WHERE id = :id');
    $productStmt->execute([':id' => (int) $req['product_id']]);
    $costPrice = (float) ($productStmt->fetchColumn() ?: 0);

    $poNumber = generatePoNumber();
    $pdo->prepare(
        "INSERT INTO purchase_orders
            (po_number, restock_request_id, supplier_id, sku, quantity_ordered, order_date, expected_delivery_date, notes, status)
         VALUES (:po, :rrid, :sup, :sku, :qty, datetime('now'), :eta, :notes, 'Ordered')"
    )->execute([
        ':po' => $poNumber, ':rrid' => $requestId, ':sup' => $req['supplier_id'], ':sku' => $req['sku'],
        ':qty' => (int) $req['requested_quantity'], ':eta' => $expectedDelivery ?: null,
        ':notes' => "Auto-created from restock request {$req['ref_number']}",
    ]);
    $poId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO purchase_order_items (purchase_order_id, product_id, sku, quantity_ordered, quantity_received, unit_cost)
         VALUES (:po, :pid, :sku, :qty, 0, :cost)'
    )->execute([
        ':po' => $poId, ':pid' => (int) $req['product_id'], ':sku' => $req['sku'],
        ':qty' => (int) $req['requested_quantity'], ':cost' => $costPrice,
    ]);

    $pdo->prepare(
        "UPDATE restock_requests SET status='Purchase Order Created', purchase_order_id=:po WHERE id=:id"
    )->execute([':po' => $poId, ':id' => $requestId]);

    logAudit($pdo, 'Restock request approved; PO created', 'restock_request', (string) $requestId, $poNumber);
    logAudit($pdo, 'Purchase order created', 'purchase_order', (string) $poId, "From restock request {$req['ref_number']}");

    $pdo->commit();
    echo json_encode(['ok' => true, 'status' => 'Purchase Order Created', 'purchase_order_id' => $poId, 'po_number' => $poNumber]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('restock_request_review.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to review restock request.']);
}
