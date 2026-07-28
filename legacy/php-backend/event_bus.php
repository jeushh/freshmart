<?php
/**
 * =====================================================================================
 * backend/event_bus.php — ENTERPRISE SERVICE BUS (ESB) / MIDDLEWARE LAYER
 * =====================================================================================
 * This is the central integration point of the system. The frontend does not talk to
 * the database at all for writes — it POSTs a single transaction payload here, and this
 * router orchestrates every downstream effect as one atomic unit of work:
 *
 *    1. OrderReceived         — payload parsed & validated
 *    2. StockValidated        — every line item checked against live inventory
 *    3. StockDeducted         — inventory decremented (IMS write), inventory_movements row
 *                                written per SKU (type=Sale), InventoryLow emitted per SKU
 *                                whose remaining stock fell to/under its own reorder_level
 *    4. LedgerPosted          — transaction rows appended to sales_ledger (ERP write), plus
 *                                one financial_transactions row (type=Sale) for the whole order
 *    5. PaymentConfirmed      — final acknowledgement assembled
 *
 * Low stock no longer auto-opens a Purchase Order here — see restock_request_create.php /
 * restock_request_review.php for the human-approved Restock Request -> PO workflow.
 *
 * Each of those steps is recorded into an `eventTrail` array returned to the client,
 * which the frontend renders into its live Event Bus console for real-time visibility
 * into the integration pipeline as it executes.
 *
 * All writes happen inside a single PDO transaction: if ANY line item fails stock
 * validation, the whole transaction is rolled back and NOTHING is written — this is
 * the atomicity guarantee you'd expect from a real ESB coordinating multiple systems
 * (inventory, accounting, and procurement) that must stay consistent with each other.
 *
 * Requires an authenticated session with pos.access (Cashier or System Administrator) — every
 * ledger row is attributed to $_SESSION['admin_username'] via sales_ledger.cashier_username.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only POST is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php'; // provides $pdo
require_once __DIR__ . '/inventory_finance_shared.php';
requirePermission('pos.access');
requireCsrfToken();

$cashierUsername = $_SESSION['admin_username'] ?? 'unknown';

const TAX_RATE = 0.12;
const VALID_PAYMENT_METHODS = ['Cash', 'Card', 'Wallet'];

/** Appends a structured event to the trail — mirrors EventBus.publish() on the frontend. */
function traceEvent(array &$trail, string $type, array $payload): void
{
    $trail[] = [
        'type'      => $type,
        'timestamp' => (new DateTime())->format(DateTime::ATOM),
        'payload'   => $payload,
    ];
}

$eventTrail = [];

// ---------------------------------------------------------------------------
// STEP 1 — Parse & validate the incoming payload (OrderReceived)
// ---------------------------------------------------------------------------
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body) || empty($body['items']) || !is_array($body['items'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payload must include a non-empty "items" array']);
    exit;
}

$paymentMethod = $body['paymentMethod'] ?? 'Cash';
if (!in_array($paymentMethod, VALID_PAYMENT_METHODS, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid paymentMethod. Must be Cash, Card, or Wallet']);
    exit;
}

// Senior Citizen (RA 9994) / PWD (RA 10754) discount: 20% off + VAT-exempt.
// The qualifying ID number is required and recorded — this isn't optional
// paperwork, BIR requires it on record for a VAT-exempt discounted sale.
$discountType = trim((string)($body['discountType'] ?? 'None'));
if (!in_array($discountType, ['None', 'SeniorCitizen', 'PWD'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'discountType must be None, SeniorCitizen, or PWD.']);
    exit;
}
$discountIdNumber = trim((string)($body['discountIdNumber'] ?? ''));
if ($discountType !== 'None' && $discountIdNumber === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A qualifying ID number is required for a Senior Citizen/PWD discount.']);
    exit;
}

$requestedItems = [];
foreach ($body['items'] as $item) {
    $sku = trim((string) ($item['sku'] ?? ''));
    $qty = (int) ($item['qty'] ?? 0);
    if ($sku === '' || $qty <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Each item requires a valid sku and qty > 0']);
        exit;
    }
    $requestedItems[] = ['sku' => $sku, 'qty' => $qty];
}

traceEvent($eventTrail, 'OrderReceived', [
    'lineCount'     => count($requestedItems),
    'paymentMethod' => $paymentMethod,
]);

// ---------------------------------------------------------------------------
// STEPS 2-4 — Validate stock, deduct inventory, post ledger — as ONE transaction
// ---------------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    $lineItems = [];
    $subtotal = 0.0;

    // STEP 2: StockValidated — read-check every line before writing anything
    $stockStmt = $pdo->prepare('SELECT id, name, price, stock_quantity, reorder_level FROM products WHERE sku = :sku');
    foreach ($requestedItems as $req) {
        $stockStmt->execute([':sku' => $req['sku']]);
        $product = $stockStmt->fetch();

        if (!$product) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => "Unknown SKU: {$req['sku']}"]);
            exit;
        }

        if ((int) $product['stock_quantity'] < $req['qty']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'ok'    => false,
                'error' => "Insufficient stock for {$product['name']} ({$req['sku']}). Available: {$product['stock_quantity']}, requested: {$req['qty']}",
            ]);
            exit;
        }

        $lineTotal = round((float) $product['price'] * $req['qty'], 2);
        $subtotal += $lineTotal;
        $lineItems[] = [
            'sku'           => $req['sku'],
            'name'          => $product['name'],
            'price'         => (float) $product['price'],
            'qty'           => $req['qty'],
            'lineTotal'     => $lineTotal,
            'productId'     => (int) $product['id'],
            'reorderLevel'  => (int) $product['reorder_level'],
        ];
    }

    traceEvent($eventTrail, 'StockValidated', ['lineCount' => count($lineItems)]);

    $orderId = 'ORD-' . date('Ymd-His') . '-' . random_int(100, 999);

    // STEP 3: StockDeducted — conditional UPDATE guards against race conditions:
    // if stock changed between the check above and now, rowCount() will be 0.
    $deductStmt = $pdo->prepare(
        'UPDATE products SET stock_quantity = stock_quantity - :qty
         WHERE sku = :sku AND stock_quantity >= :qty2'
    );
    $lowStockAlerts = [];
    $lookupStmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE sku = :sku');
    $lineBySku = [];
    foreach ($lineItems as $li) {
        $lineBySku[$li['sku']] = $li;
    }

    foreach ($requestedItems as $req) {
        $deductStmt->execute([':qty' => $req['qty'], ':sku' => $req['sku'], ':qty2' => $req['qty']]);
        if ($deductStmt->rowCount() !== 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => "Stock conflict while deducting {$req['sku']} — please retry"]);
            exit;
        }

        $lookupStmt->execute([':sku' => $req['sku']]);
        $remaining = (int) $lookupStmt->fetchColumn();
        $previousStock = $remaining + $req['qty'];
        $line = $lineBySku[$req['sku']];

        // Inventory movement — every sale gets logged into the full movement ledger.
        recordInventoryMovement(
            $pdo, $line['productId'], $req['sku'], 'Sale', -$req['qty'], $previousStock, $remaining,
            $orderId, "Sold via POS ({$paymentMethod})"
        );

        // Low stock detection now uses this product's own reorder_level, not a
        // single hardcoded threshold — matches the Inventory / Low Stock screen.
        if ($remaining <= (int) $line['reorderLevel']) {
            $lowStockAlerts[] = [
                'sku'          => $req['sku'],
                'name'         => $line['name'],
                'remaining'    => $remaining,
                'reorderLevel' => (int) $line['reorderLevel'],
                'outOfStock'   => $remaining <= 0,
            ];
        }
    }

    traceEvent($eventTrail, 'StockDeducted', ['items' => $requestedItems]);
    foreach ($lowStockAlerts as $alert) {
        traceEvent($eventTrail, 'InventoryLow', $alert);
    }

    // Totals — computed server-side so the client can never tamper with the amount charged.
    // Senior/PWD: 20% off the subtotal AND fully VAT-exempt (not just 12% of a
    // discounted amount — the tax itself is waived, per RA 9994 / RA 10754).
    $discountRate = $discountType !== 'None' ? 0.20 : 0.0;
    $discount = round($subtotal * $discountRate, 2);
    $isVatExempt = $discountType !== 'None';
    $tax = $isVatExempt ? 0.0 : round(($subtotal - $discount) * TAX_RATE, 2);
    $total = round($subtotal - $discount + $tax, 2);

    if ($discountType !== 'None') {
        traceEvent($eventTrail, 'DiscountApplied', [
            'discountType' => $discountType,
            'discountRate' => $discountRate,
            'amount'       => $discount,
            'vatExempt'    => true,
        ]);
    }

    // STEP 4a: the sale itself no longer auto-opens a Purchase Order. Per the
    // current Restock workflow, a low/out-of-stock SKU (already traced above as
    // InventoryLow) surfaces on the Inventory > Low Stock screen, where a user
    // with restock.request permission (Cashier, Inventory Staff, Admin) clicks
    // [Request Restock] to open a Restock Request, which a Manager then approves
    // before any PO exists. This keeps procurement a deliberate, human-approved step.

    // STEP 4b: LedgerPosted — append-only journal rows (one per line item)
    $ledgerStmt = $pdo->prepare(
        'INSERT INTO sales_ledger (order_id, item_sku, quantity_sold, total_price, payment_method, cashier_username, discount_type, discount_id_number, timestamp)
         VALUES (:order_id, :sku, :qty, :total, :method, :cashier, :dtype, :did, datetime("now"))'
    );
    foreach ($lineItems as $line) {
        $ledgerStmt->execute([
            ':order_id' => $orderId,
            ':sku'      => $line['sku'],
            ':qty'      => $line['qty'],
            ':total'    => $line['lineTotal'],
            ':method'   => $paymentMethod,
            ':cashier'  => $cashierUsername,
            ':dtype'    => $discountType !== 'None' ? $discountType : null,
            ':did'      => $discountType !== 'None' ? $discountIdNumber : null,
        ]);
    }

    traceEvent($eventTrail, 'LedgerPosted', ['orderId' => $orderId, 'rows' => count($lineItems)]);

    // Finance integration: one Sale revenue transaction per completed order.
    recordFinancialTransaction(
        $pdo, 'Sale', $total, 'sale', $orderId,
        'POS sale ' . $orderId, 'Revenue', $paymentMethod
    );

    $pdo->commit();

    // STEP 5: PaymentConfirmed — final acknowledgement
    traceEvent($eventTrail, 'PaymentConfirmed', [
        'orderId' => $orderId,
        'method'  => $paymentMethod,
        'amount'  => $total,
    ]);

    echo json_encode([
        'ok'         => true,
        'orderId'    => $orderId,
        'items'      => $lineItems,
        'totals'     => [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'tax'      => $tax,
            'total'    => $total,
        ],
        'paymentMethod' => $paymentMethod,
        'cashier'       => $cashierUsername,
        'eventTrail'    => $eventTrail,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('event_bus.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Transaction failed.']);
}
