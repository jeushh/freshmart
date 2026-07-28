<?php
/**
 * =====================================================================================
 * backend/process_refund.php — POS REFUND ADAPTER
 * =====================================================================================
 * POST-only, requires pos.refund. Refunds one or more line items from a completed
 * sale (identified by order_id): validates each SKU was actually sold on that
 * order and that the requested refund quantity doesn't exceed (original qty -
 * already refunded qty), inserts one refunds row per line, and restocks
 * products.stock_quantity accordingly — all in a single transaction, same
 * atomicity pattern as event_bus.php's checkout.
 *
 * A refund never edits or deletes the original sales_ledger rows — it's a
 * separate compensating record, the same principle as an accounting reversal
 * entry never touching the original journal entry.
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

requirePermission('pos.refund');
requireCsrfToken();

try {
    $raw  = json_decode(file_get_contents('php://input'), true);
    $body = is_array($raw) ? $raw : [];

    $orderId = trim((string)($body['order_id'] ?? ''));
    $reason  = trim((string)($body['reason'] ?? '')) ?: null;
    $lines   = is_array($body['lines'] ?? null) ? $body['lines'] : [];

    if ($orderId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'order_id is required.']);
        exit;
    }
    if (empty($lines)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'At least one line item (sku + qty) is required.']);
        exit;
    }

    $pdo->beginTransaction();

    $totalRefunded = 0.0;
    $refundedLines  = [];

    foreach ($lines as $line) {
        $sku = trim((string)($line['sku'] ?? ''));
        $qty = (int)($line['qty'] ?? 0);

        if ($sku === '' || $qty <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Each line requires a valid sku and qty > 0.']);
            exit;
        }

        // Original sale for this SKU on this order — unit price derived from the
        // originally recorded line total, so a refund reflects what was actually
        // charged (including any per-order discount already baked into total_price).
        $soldStmt = $pdo->prepare(
            'SELECT SUM(quantity_sold) AS sold_qty, SUM(total_price) AS sold_total
             FROM sales_ledger WHERE order_id = :oid AND item_sku = :sku'
        );
        $soldStmt->execute([':oid' => $orderId, ':sku' => $sku]);
        $sold = $soldStmt->fetch();

        if (!$sold || (int)$sold['sold_qty'] === 0) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "SKU {$sku} was not part of order {$orderId}."]);
            exit;
        }

        $soldQty      = (int)$sold['sold_qty'];
        $unitPrice    = (float)$sold['sold_total'] / $soldQty;

        $alreadyRefundedStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity_refunded), 0) FROM refunds WHERE order_id = :oid AND item_sku = :sku'
        );
        $alreadyRefundedStmt->execute([':oid' => $orderId, ':sku' => $sku]);
        $alreadyRefunded = (int)$alreadyRefundedStmt->fetchColumn();

        $remaining = $soldQty - $alreadyRefunded;
        if ($qty > $remaining) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'ok'    => false,
                'error' => "Cannot refund {$qty} of {$sku} — only {$remaining} unit(s) remain refundable on this order.",
            ]);
            exit;
        }

        $refundAmount = round($unitPrice * $qty, 2);

        $pdo->prepare(
            'INSERT INTO refunds (order_id, item_sku, quantity_refunded, refund_amount, reason, processed_by)
             VALUES (:oid, :sku, :qty, :amount, :reason, :by)'
        )->execute([
            ':oid'    => $orderId,
            ':sku'    => $sku,
            ':qty'    => $qty,
            ':amount' => $refundAmount,
            ':reason' => $reason,
            ':by'     => $_SESSION['admin_username'] ?? 'unknown',
        ]);

        // Restock — refunded items go back into sellable inventory.
        $productStmt = $pdo->prepare('SELECT id, stock_quantity FROM products WHERE sku = :sku');
        $productStmt->execute([':sku' => $sku]);
        $product = $productStmt->fetch();
        if ($product) {
            $prevStock = (int) $product['stock_quantity'];
            $newStock  = $prevStock + $qty;
            $pdo->prepare('UPDATE products SET stock_quantity = :s WHERE id = :id')
                ->execute([':s' => $newStock, ':id' => $product['id']]);
            recordInventoryMovement(
                $pdo, (int) $product['id'], $sku, 'Refund', $qty, $prevStock, $newStock,
                $orderId, $reason ?: 'Refund'
            );
        }

        $totalRefunded  += $refundAmount;
        $refundedLines[] = ['sku' => $sku, 'qty' => $qty, 'refund_amount' => $refundAmount];
    }

    if ($totalRefunded > 0) {
        recordFinancialTransaction(
            $pdo, 'Refund', $totalRefunded, 'refund', $orderId,
            'POS refund for order ' . $orderId, 'Refund', null, 'Out'
        );
    }
    logAudit($pdo, 'Refund processed', 'order', $orderId, 'Total refunded: ' . round($totalRefunded, 2));

    $pdo->commit();

    echo json_encode([
        'ok'             => true,
        'order_id'       => $orderId,
        'lines'          => $refundedLines,
        'total_refunded' => round($totalRefunded, 2),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('process_refund.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to process refund.']);
}
