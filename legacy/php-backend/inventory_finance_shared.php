<?php
/**
 * =====================================================================================
 * backend/inventory_finance_shared.php — SHARED WRITE HELPERS
 * =====================================================================================
 * Common functions used by every endpoint that touches inventory stock, financial
 * transactions, or the audit trail. Centralised here so every write path (POS sale,
 * refund, stock receiving, manual adjustment, payroll, expenses, supplier payments)
 * produces movement/transaction/audit rows in exactly the same shape.
 *
 * require_once this AFTER auth.php and db.php (needs $pdo and $_SESSION).
 * =====================================================================================
 */

declare(strict_types=1);

/**
 * Records one row in inventory_movements. Callers are responsible for actually
 * writing the new stock_quantity to products — this function only logs the change.
 * previousStock/newStock are recorded explicitly (not re-derived) so the movement
 * ledger stays correct even if called inside a larger multi-statement transaction.
 */
function recordInventoryMovement(
    PDO $pdo,
    int $productId,
    string $sku,
    string $movementType,
    int $quantity,
    int $previousStock,
    int $newStock,
    ?string $referenceId,
    ?string $notes = null
): void {
    $username = $_SESSION['admin_username'] ?? 'system';
    $pdo->prepare(
        'INSERT INTO inventory_movements
            (product_id, sku, movement_type, quantity, previous_stock, new_stock, reference_id, performed_by, notes)
         VALUES (:pid, :sku, :type, :qty, :prev, :new, :ref, :by, :notes)'
    )->execute([
        ':pid'   => $productId,
        ':sku'   => $sku,
        ':type'  => $movementType,
        ':qty'   => $quantity,
        ':prev'  => $previousStock,
        ':new'   => $newStock,
        ':ref'   => $referenceId,
        ':by'    => $username,
        ':notes' => $notes,
    ]);
}

/**
 * Records one row in financial_transactions. direction is derived automatically
 * from transaction_type unless explicitly overridden.
 */
function recordFinancialTransaction(
    PDO $pdo,
    string $transactionType,
    float $amount,
    ?string $referenceType = null,
    ?string $referenceId = null,
    ?string $description = null,
    ?string $category = null,
    ?string $paymentMethod = null,
    ?string $direction = null
): int {
    $inTypes = ['Sale'];
    $dir = $direction ?? (in_array($transactionType, $inTypes, true) ? 'In' : 'Out');
    $username = $_SESSION['admin_username'] ?? 'system';
    $stmt = $pdo->prepare(
        'INSERT INTO financial_transactions
            (transaction_type, amount, direction, reference_type, reference_id, description, category, payment_method, created_by)
         VALUES (:type, :amount, :dir, :rtype, :rid, :desc, :cat, :pm, :by)'
    );
    $stmt->execute([
        ':type'   => $transactionType,
        ':amount' => round($amount, 2),
        ':dir'    => $dir,
        ':rtype'  => $referenceType,
        ':rid'    => $referenceId,
        ':desc'   => $description,
        ':cat'    => $category,
        ':pm'     => $paymentMethod,
        ':by'     => $username,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Appends one row to audit_logs. Never throws — logging failures must never abort a business transaction. */
function logAudit(PDO $pdo, string $action, ?string $entityType = null, ?string $entityId = null, ?string $details = null): void
{
    try {
        $username = $_SESSION['admin_username'] ?? 'system';
        $pdo->prepare(
            'INSERT INTO audit_logs (username, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (:u, :a, :et, :eid, :d, :ip, :ua)'
        )->execute([
            ':u'   => $username,
            ':a'   => $action,
            ':et'  => $entityType,
            ':eid' => $entityId,
            ':d'   => $details,
            ':ip'  => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            ':ua'  => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('logAudit failed: ' . $e->getMessage());
    }
}

/** Generates a sequential-looking human PO number: PO-20260726-3492 */
function generatePoNumber(): string
{
    return 'PO-' . date('Ymd') . '-' . random_int(1000, 9999);
}

/** Generates a restock request reference: RR-20260726-3492 */
function generateRestockRefNumber(): string
{
    return 'RR-' . date('Ymd') . '-' . random_int(1000, 9999);
}

/**
 * Recomputes and persists a purchase_order's status + restock_request status based
 * on cumulative accepted quantities across purchase_order_items vs quantity_ordered.
 * Shared by receive_purchase_order.php so the "Partially/Fully Received" rollup logic
 * lives in exactly one place.
 */
function recalcPurchaseOrderStatus(PDO $pdo, int $purchaseOrderId): string
{
    $items = $pdo->prepare('SELECT quantity_ordered, quantity_received FROM purchase_order_items WHERE purchase_order_id = :id');
    $items->execute([':id' => $purchaseOrderId]);
    $rows = $items->fetchAll();

    $totalOrdered  = 0;
    $totalReceived = 0;
    foreach ($rows as $r) {
        $totalOrdered  += (int) $r['quantity_ordered'];
        $totalReceived += (int) $r['quantity_received'];
    }

    if ($totalReceived <= 0) {
        $status = 'Ordered';
    } elseif ($totalReceived >= $totalOrdered) {
        $status = 'Fully Received';
    } else {
        $status = 'Partially Received';
    }

    $pdo->prepare('UPDATE purchase_orders SET status = :status WHERE id = :id')
        ->execute([':status' => $status, ':id' => $purchaseOrderId]);

    // Roll the same signal up to the linked restock request, if any.
    $poStmt = $pdo->prepare('SELECT restock_request_id FROM purchase_orders WHERE id = :id');
    $poStmt->execute([':id' => $purchaseOrderId]);
    $restockRequestId = $poStmt->fetchColumn();

    if ($restockRequestId) {
        $rrStatus = $status === 'Fully Received' ? 'Completed' : ($status === 'Partially Received' ? 'Partially Received' : 'Ordered');
        $pdo->prepare('UPDATE restock_requests SET status = :status WHERE id = :id')
            ->execute([':status' => $rrStatus, ':id' => (int) $restockRequestId]);
    }

    return $status;
}
