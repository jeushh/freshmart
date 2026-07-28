<?php
/**
 * =====================================================================================
 * backend/purchase_order_update_status.php — PO STATUS TRANSITION ADAPTER
 * =====================================================================================
 * POST-only, requires inventory.manage. Handles the manual, non-receiving status
 * transitions a Purchase Order can go through before stock actually arrives:
 *   Pending -> Approved -> Ordered, or either -> Cancelled.
 * Receiving-driven transitions (Partially/Fully Received) are owned exclusively by
 * receive_purchase_order.php / recalcPurchaseOrderStatus() — not settable here.
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

const PO_ALLOWED_TRANSITIONS = [
    'Pending'  => ['Approved', 'Ordered', 'Cancelled'],
    'Approved' => ['Ordered', 'Cancelled'],
];

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $post = is_array($raw) ? $raw : $_POST;

    $poId      = isset($post['id']) ? (int) $post['id'] : 0;
    $newStatus = trim((string) ($post['status'] ?? ''));

    if ($poId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid id is required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = :id');
    $stmt->execute([':id' => $poId]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Purchase order not found.']);
        exit;
    }

    $allowed = PO_ALLOWED_TRANSITIONS[$currentStatus] ?? [];
    if (!in_array($newStatus, $allowed, true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Cannot move a {$currentStatus} purchase order to {$newStatus}."]);
        exit;
    }

    $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id')
        ->execute([':status' => $newStatus, ':id' => $poId]);

    logAudit($pdo, "Purchase order status: {$currentStatus} -> {$newStatus}", 'purchase_order', (string) $poId, null);

    echo json_encode(['ok' => true, 'status' => $newStatus]);
} catch (Throwable $e) {
    error_log('purchase_order_update_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update purchase order status.']);
}
