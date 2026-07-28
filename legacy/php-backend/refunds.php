<?php
/**
 * =====================================================================================
 * backend/refunds.php — POS REFUND HISTORY READ ADAPTER
 * =====================================================================================
 * GET-only, requires pos.refund or sales.view (processing refunds and auditing
 * them are related but distinct concerns — a sales analyst should be able to
 * see refund history without necessarily being allowed to process one).
 * Optional ?order_id= filter.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

requireAnyPermission(['pos.refund', 'sales.view']);

try {
    $orderId = trim((string)($_GET['order_id'] ?? ''));

    if ($orderId !== '') {
        $stmt = $pdo->prepare(
            'SELECT r.*, p.name AS product_name
             FROM refunds r
             LEFT JOIN products p ON p.sku = r.item_sku
             WHERE r.order_id = :oid
             ORDER BY r.created_at DESC'
        );
        $stmt->execute([':oid' => $orderId]);
    } else {
        $stmt = $pdo->query(
            'SELECT r.*, p.name AS product_name
             FROM refunds r
             LEFT JOIN products p ON p.sku = r.item_sku
             ORDER BY r.created_at DESC
             LIMIT 100'
        );
    }

    echo json_encode(['ok' => true, 'refunds' => $stmt->fetchAll()]);
} catch (Throwable $e) {
    error_log('refunds.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load refunds.']);
}
