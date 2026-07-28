<?php
/**
 * =====================================================================================
 * backend/finance_dashboard.php — FINANCE DASHBOARD AGGREGATE READ ADAPTER
 * =====================================================================================
 * GET-only, requires finance.manage. Aggregates across financial_transactions,
 * products (inventory value), and accounts_payable into the KPI set requested:
 * Total Sales, Total Expenses, Net Income, Inventory Value, Purchase Costs,
 * Payroll Expenses, Accounts Payable, Cash Balance.
 *
 * Cash Balance = SUM(direction='In') - SUM(direction='Out') across every financial
 * transaction ever posted (Sale in; Refund/Purchase/Supplier Payment/Payroll/Expense
 * out) — a running ledger balance, not just today's till.
 *
 * Net Income = Sales - Refunds - Purchases - Payroll - Expenses. Supplier Payments
 * are excluded from Net Income (they settle a payable already expensed at Purchase
 * time) but ARE included in Cash Balance, since real cash actually left the bank.
 * =====================================================================================
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Only GET is supported.']);
    exit;
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
requirePermission('finance.manage');

try {
    $sumByType = function (string $type) use ($pdo): float {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE transaction_type = :t');
        $stmt->execute([':t' => $type]);
        return (float) $stmt->fetchColumn();
    };

    $totalSales    = $sumByType('Sale');
    $totalRefunds  = $sumByType('Refund');
    $totalPurchase = $sumByType('Purchase');
    $totalPayroll  = $sumByType('Payroll');
    $totalExpenses = $sumByType('Expense');
    $totalSupplierPayments = $sumByType('Supplier Payment');

    $netIncome = $totalSales - $totalRefunds - $totalPurchase - $totalPayroll - $totalExpenses;

    $cashIn  = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE direction = 'In'")->fetchColumn();
    $cashOut = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE direction = 'Out'")->fetchColumn();
    $cashBalance = $cashIn - $cashOut;

    $inventoryValue = (float) $pdo->query(
        "SELECT COALESCE(SUM(stock_quantity * cost_price), 0) FROM products WHERE status = 'Active'"
    )->fetchColumn();

    $accountsPayable = (float) $pdo->query(
        "SELECT COALESCE(SUM(total_amount - amount_paid), 0) FROM accounts_payable WHERE status != 'Paid'"
    )->fetchColumn();

    echo json_encode([
        'ok' => true,
        'dashboard' => [
            'total_sales'            => round($totalSales, 2),
            'total_refunds'          => round($totalRefunds, 2),
            'total_expenses'         => round($totalExpenses, 2),
            'net_income'             => round($netIncome, 2),
            'inventory_value'        => round($inventoryValue, 2),
            'purchase_costs'         => round($totalPurchase, 2),
            'payroll_expenses'       => round($totalPayroll, 2),
            'accounts_payable'       => round($accountsPayable, 2),
            'supplier_payments_paid' => round($totalSupplierPayments, 2),
            'cash_balance'           => round($cashBalance, 2),
        ],
    ]);
} catch (Throwable $e) {
    error_log('finance_dashboard.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load finance dashboard.']);
}
