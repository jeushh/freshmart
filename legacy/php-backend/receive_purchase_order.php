<?php
/**
 * =====================================================================================
 * backend/receive_purchase_order.php — STOCK RECEIVING ADAPTER
 * =====================================================================================
 * POST-only, requires inventory.manage. This is the endpoint that closes the loop on
 * the full restock workflow:
 *
 *   PO items -> receive (full or partial) -> product stock increases -> inventory
 *   movement recorded -> PO status recalculated -> linked restock request status
 *   recalculated -> financial transaction (Purchase) posted -> accounts_payable
 *   entry created/updated.
 *
 * Payload:
 *   {
 *     "purchase_order_id": 5,
 *     "notes": "optional",
 *     "invoice_number": "optional, feeds accounts_payable",
 *     "due_date": "optional YYYY-MM-DD, feeds accounts_payable",
 *     "items": [
 *       { "purchase_order_item_id": 12, "received_quantity": 46, "damaged_quantity": 0,
 *         "rejected_quantity": 0, "batch_no": "optional", "expiration_date": "optional" }
 *     ]
 *   }
 *
 * Only ACCEPTED units (received - damaged - rejected) are added to sellable stock.
 * Damaged/rejected units are recorded on the receiving line for traceability but
 * never touch stock_quantity.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported on this endpoint']);
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

    $poId          = isset($post['purchase_order_id']) ? (int) $post['purchase_order_id'] : 0;
    $notes         = trim((string) ($post['notes'] ?? ''));
    $invoiceNumber = trim((string) ($post['invoice_number'] ?? ''));
    $dueDate       = trim((string) ($post['due_date'] ?? ''));
    $items         = is_array($post['items'] ?? null) ? $post['items'] : [];

    if ($poId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'A valid purchase_order_id is required.']);
        exit;
    }
    if (empty($items)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'At least one item to receive is required.']);
        exit;
    }

    $pdo->beginTransaction();

    $poStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
    $poStmt->execute([':id' => $poId]);
    $po = $poStmt->fetch();

    if (!$po) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Purchase order not found.']);
        exit;
    }
    if (in_array($po['status'], ['Fully Received', 'Cancelled'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => "Purchase order is already '{$po['status']}' and cannot be received again."]);
        exit;
    }

    $receivedBy = $_SESSION['admin_username'] ?? 'unknown';

    $receivingStmt = $pdo->prepare(
        "INSERT INTO stock_receivings (purchase_order_id, received_by, receiving_date, notes)
         VALUES (:po, :by, datetime('now'), :notes)"
    );
    $receivingStmt->execute([':po' => $poId, ':by' => $receivedBy, ':notes' => $notes ?: null]);
    $receivingId = (int) $pdo->lastInsertId();

    $itemLookupStmt = $pdo->prepare('SELECT * FROM purchase_order_items WHERE id = :id AND purchase_order_id = :po');
    $productLookupStmt = $pdo->prepare('SELECT id, sku, name, stock_quantity, reorder_level FROM products WHERE id = :id');
    $lineInsert = $pdo->prepare(
        'INSERT INTO stock_receiving_items
            (stock_receiving_id, purchase_order_item_id, product_id, sku, received_quantity, damaged_quantity,
             rejected_quantity, batch_no, expiration_date, unit_cost)
         VALUES (:rid, :poi, :pid, :sku, :recv, :dmg, :rej, :batch, :exp, :cost)'
    );

    $totalPurchaseCost = 0.0;
    $receivedLines = [];

    foreach ($items as $item) {
        $poItemId       = isset($item['purchase_order_item_id']) ? (int) $item['purchase_order_item_id'] : 0;
        $receivedQty    = isset($item['received_quantity']) ? (int) $item['received_quantity'] : 0;
        $damagedQty     = isset($item['damaged_quantity']) ? (int) $item['damaged_quantity'] : 0;
        $rejectedQty    = isset($item['rejected_quantity']) ? (int) $item['rejected_quantity'] : 0;
        $batchNo        = trim((string) ($item['batch_no'] ?? '')) ?: null;
        $expirationDate = trim((string) ($item['expiration_date'] ?? '')) ?: null;

        if ($poItemId <= 0 || $receivedQty <= 0) {
            continue; // skip lines the user left at zero (partial receiving is allowed)
        }
        if ($damagedQty < 0 || $rejectedQty < 0 || ($damagedQty + $rejectedQty) > $receivedQty) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'damaged_quantity + rejected_quantity cannot exceed received_quantity.']);
            exit;
        }

        $itemLookupStmt->execute([':id' => $poItemId, ':po' => $poId]);
        $poItem = $itemLookupStmt->fetch();
        if (!$poItem) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "Purchase order item #{$poItemId} not found on this PO."]);
            exit;
        }

        $remainingOrdered = (int) $poItem['quantity_ordered'] - (int) $poItem['quantity_received'];
        if ($receivedQty > $remainingOrdered) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'ok' => false,
                'error' => "Cannot receive {$receivedQty} of {$poItem['sku']} — only {$remainingOrdered} unit(s) remain on this PO line.",
            ]);
            exit;
        }

        $acceptedQty = $receivedQty - $damagedQty - $rejectedQty;
        $unitCost = (float) $poItem['unit_cost'];

        $lineInsert->execute([
            ':rid' => $receivingId, ':poi' => $poItemId, ':pid' => $poItem['product_id'], ':sku' => $poItem['sku'],
            ':recv' => $receivedQty, ':dmg' => $damagedQty, ':rej' => $rejectedQty,
            ':batch' => $batchNo, ':exp' => $expirationDate, ':cost' => $unitCost,
        ]);

        // Advance quantity_received on the PO line by the FULL received amount
        // (damaged/rejected still count as "received against the PO", they just
        // don't become sellable stock) so the PO can't be over-received.
        $pdo->prepare('UPDATE purchase_order_items SET quantity_received = quantity_received + :qty WHERE id = :id')
            ->execute([':qty' => $receivedQty, ':id' => $poItemId]);

        if ($acceptedQty > 0 && $poItem['product_id']) {
            $productLookupStmt->execute([':id' => (int) $poItem['product_id']]);
            $product = $productLookupStmt->fetch();
            if ($product) {
                $prevStock = (int) $product['stock_quantity'];
                $newStock  = $prevStock + $acceptedQty;
                $pdo->prepare('UPDATE products SET stock_quantity = :s WHERE id = :id')
                    ->execute([':s' => $newStock, ':id' => $product['id']]);

                recordInventoryMovement(
                    $pdo, (int) $product['id'], $product['sku'], 'Receiving', $acceptedQty, $prevStock, $newStock,
                    $po['po_number'], "Stock receiving #{$receivingId}" . ($batchNo ? " (batch {$batchNo})" : '')
                );
            }
        }

        $totalPurchaseCost += $acceptedQty * $unitCost;
        $receivedLines[] = ['sku' => $poItem['sku'], 'accepted' => $acceptedQty, 'damaged' => $damagedQty, 'rejected' => $rejectedQty];
    }

    if (empty($receivedLines)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No valid receiving lines were submitted.']);
        exit;
    }

    // Recalculate PO + linked restock request status from cumulative received qty.
    $newPoStatus = recalcPurchaseOrderStatus($pdo, $poId);
    $pdo->prepare("UPDATE purchase_orders SET received_at = datetime('now') WHERE id = :id")->execute([':id' => $poId]);

    // ---- Finance integration: record the purchase cost + accounts payable -------
    $financialTxnId = null;
    if ($totalPurchaseCost > 0) {
        $financialTxnId = recordFinancialTransaction(
            $pdo, 'Purchase', $totalPurchaseCost, 'purchase_order', (string) $poId,
            "Stock receiving for PO {$po['po_number']}", 'Inventory Purchase', null
        );

        $apStmt = $pdo->prepare('SELECT id, total_amount, amount_paid FROM accounts_payable WHERE purchase_order_id = :po');
        $apStmt->execute([':po' => $poId]);
        $ap = $apStmt->fetch();
        if ($ap) {
            $newTotal = (float) $ap['total_amount'] + $totalPurchaseCost;
            $status = ((float) $ap['amount_paid']) >= $newTotal ? 'Paid' : (((float) $ap['amount_paid']) > 0 ? 'Partially Paid' : 'Unpaid');
            $pdo->prepare('UPDATE accounts_payable SET total_amount = :t, status = :s WHERE id = :id')
                ->execute([':t' => $newTotal, ':s' => $status, ':id' => $ap['id']]);
        } else {
            $pdo->prepare(
                'INSERT INTO accounts_payable (supplier_id, purchase_order_id, invoice_number, total_amount, amount_paid, due_date, status)
                 VALUES (:sup, :po, :inv, :total, 0, :due, \'Unpaid\')'
            )->execute([
                ':sup' => $po['supplier_id'], ':po' => $poId, ':inv' => $invoiceNumber ?: null,
                ':total' => $totalPurchaseCost, ':due' => $dueDate ?: null,
            ]);
        }
    }

    logAudit($pdo, 'Stock received', 'purchase_order', (string) $poId, "Receiving #{$receivingId}, PO now {$newPoStatus}, cost ₱" . number_format($totalPurchaseCost, 2));

    $pdo->commit();

    echo json_encode([
        'ok'                 => true,
        'stock_receiving_id' => $receivingId,
        'purchase_order_status' => $newPoStatus,
        'lines'              => $receivedLines,
        'total_purchase_cost' => round($totalPurchaseCost, 2),
        'financial_transaction_id' => $financialTxnId,
        'message' => "Receiving #{$receivingId} recorded. PO #{$poId} is now {$newPoStatus}.",
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('receive_purchase_order.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
}
