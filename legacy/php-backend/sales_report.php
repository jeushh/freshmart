<?php
/**
 * =====================================================================================
 * backend/sales_report.php — ERP READ ADAPTER (Sales Analytics)
 * =====================================================================================
 * Read-only JSON endpoint aggregating sales_ledger + products for admin reporting.
 * Demonstrates the ERP-read side of the integration architecture.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported on this endpoint']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
// The single-order lookup below is also used by the POS refund flow (a Cashier
// needs to see what was actually sold on an order before refunding it), so it
// accepts pos.refund too. The aggregate dashboard further down stays gated to
// sales.view only — a Cashier shouldn't see full revenue/sales analytics just
// because they can process refunds.
requireAnyPermission(['sales.view', 'pos.refund']);
$hasFullSalesView = in_array('sales.view', $_SESSION['admin_permissions'] ?? [], true);

// ---- Single-order lookup (?order_id=ORD-...) ----
if (!empty($_GET['order_id'])) {
    $orderId = trim($_GET['order_id']);
    try {
        $stmt = $pdo->prepare("
            SELECT sl.item_sku AS sku, p.name, sl.quantity_sold, sl.total_price, sl.payment_method,
                   sl.discount_type, sl.discount_id_number, sl.timestamp
            FROM sales_ledger sl
            LEFT JOIN products p ON p.sku = sl.item_sku
            WHERE sl.order_id = :order_id
            ORDER BY sl.id ASC
        ");
        $stmt->execute([':order_id' => $orderId]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Order not found.']);
            exit;
        }
        $subtotal = 0.0;
        $lines = array_map(static function (array $r) use (&$subtotal): array {
            $subtotal += (float) $r['total_price'];
            return [
                'sku'           => $r['sku'],
                'name'          => $r['name'] ?? $r['sku'],
                'quantity_sold' => (int) $r['quantity_sold'],
                'line_total'    => round((float) $r['total_price'], 2),
            ];
        }, $rows);
        $subtotal    = round($subtotal, 2);
        // discount_type is the same across every row of one order — read once.
        $discountType = $rows[0]['discount_type'] ?? null;
        $isDiscounted = $discountType !== null;
        $discount    = $isDiscounted ? round($subtotal * 0.20, 2) : 0.0;
        $tax         = $isDiscounted ? 0.0 : round($subtotal * 0.12, 2);
        echo json_encode([
            'ok'                 => true,
            'order_id'           => $orderId,
            'payment_method'     => $rows[0]['payment_method'],
            'timestamp'          => $rows[0]['timestamp'],
            'discount_type'      => $discountType,
            'discount_id_number' => $rows[0]['discount_id_number'] ?? null,
            'lines'              => $lines,
            'subtotal'           => $subtotal,
            'discount'           => $discount,
            'tax'                => $tax,
            'total'              => round($subtotal - $discount + $tax, 2),
        ]);
    } catch (Throwable $e) {
        error_log('sales_report.php order_id: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
    }
    exit;
}

// Everything below is the aggregate dashboard — sales.view specifically,
// not just any permission that got past the requireAnyPermission() above.
if (!$hasFullSalesView) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Insufficient permissions.']);
    exit;
}

try {
    $todaySummary = $pdo->query("
        SELECT
            COALESCE(SUM(total_price), 0) AS total_revenue,
            COUNT(DISTINCT order_id) AS order_count
        FROM sales_ledger
        WHERE date(timestamp) = date('now', 'localtime')
    ")->fetch();

    $paymentBreakdownStmt = $pdo->query("
        SELECT payment_method, COALESCE(SUM(total_price), 0) AS revenue
        FROM sales_ledger
        WHERE date(timestamp) = date('now', 'localtime')
        GROUP BY payment_method
        ORDER BY revenue DESC
    ");
    $revenueByPaymentMethod = [];
    foreach ($paymentBreakdownStmt->fetchAll() as $row) {
        $revenueByPaymentMethod[$row['payment_method']] = round((float) $row['revenue'], 2);
    }

    $topSkusStmt = $pdo->query("
        SELECT
            sl.item_sku AS sku,
            p.name,
            SUM(sl.quantity_sold) AS quantity_sold,
            SUM(sl.total_price) AS revenue
        FROM sales_ledger sl
        LEFT JOIN products p ON p.sku = sl.item_sku
        GROUP BY sl.item_sku
        ORDER BY quantity_sold DESC
        LIMIT 5
    ");
    $topSellingSkus = array_map(static function (array $row): array {
        return [
            'sku'            => $row['sku'],
            'name'           => $row['name'] ?? $row['sku'],
            'quantity_sold'  => (int) $row['quantity_sold'],
            'revenue'        => round((float) $row['revenue'], 2),
        ];
    }, $topSkusStmt->fetchAll());

    $dailyRevenueStmt = $pdo->query("
        SELECT
            date(timestamp) AS day,
            COALESCE(SUM(total_price), 0) AS revenue,
            COUNT(DISTINCT order_id) AS order_count
        FROM sales_ledger
        WHERE date(timestamp) >= date('now', 'localtime', '-6 days')
        GROUP BY date(timestamp)
        ORDER BY day ASC
    ");
    $dailyRevenue = array_map(static function (array $row): array {
        return [
            'day'         => $row['day'],
            'revenue'     => round((float) $row['revenue'], 2),
            'order_count' => (int) $row['order_count'],
        ];
    }, $dailyRevenueStmt->fetchAll());

    $lowStockStmt = $pdo->query("
        SELECT id, name, sku, category, stock_quantity, unit, emoji
        FROM products
        WHERE stock_quantity <= 5
        ORDER BY stock_quantity ASC, name ASC
    ");
    $lowStockItems = array_map(static function (array $row): array {
        return [
            'id'              => (int) $row['id'],
            'name'            => $row['name'],
            'sku'             => $row['sku'],
            'category'        => $row['category'],
            'stock_quantity'  => (int) $row['stock_quantity'],
            'unit'            => $row['unit'],
            'emoji'           => $row['emoji'],
        ];
    }, $lowStockStmt->fetchAll());

    echo json_encode([
        'ok' => true,
        'today' => [
            'total_revenue' => round((float) $todaySummary['total_revenue'], 2),
            'order_count'   => (int) $todaySummary['order_count'],
        ],
        'revenue_by_payment_method' => $revenueByPaymentMethod,
        'top_selling_skus'          => $topSellingSkus,
        'daily_revenue_last_7_days' => $dailyRevenue,
        'low_stock_items'           => $lowStockItems,
    ]);
} catch (Throwable $e) {
    error_log('sales_report.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An internal error occurred.']);
}
