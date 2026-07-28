<?php
declare(strict_types=1);
/**
 * =====================================================================================
 * backend/reports.php — INVENTORY / HR / FINANCE REPORTS READ ADAPTER
 * =====================================================================================
 * GET-only. ?type= selects the report family; each type gates on the permission that
 * already owns that module's data (POS/Sales reports remain in sales_report.php,
 * which is an existing, working feature this endpoint does not duplicate).
 *
 *   ?type=inventory_current    -> requires inventory.manage : current stock per product
 *   ?type=inventory_low_stock  -> requires inventory.manage : same shape as products.php?action=low_stock
 *   ?type=inventory_movement   -> requires inventory.manage : stock movement ledger (last 500)
 *   ?type=inventory_value      -> requires inventory.manage : total inventory value by category
 *   ?type=hr_employees         -> requires hr.employees.view : employee list
 *   ?type=hr_attendance        -> requires hr.attendance.view : attendance summary (?from=&to=)
 *   ?type=hr_leave             -> requires hr.requests.view : leave request history
 *   ?type=hr_overtime          -> requires payroll.manage : overtime hours from paid payroll
 *   ?type=hr_payroll           -> requires payroll.manage : payroll run history
 *   ?type=finance_revenue      -> requires finance.manage : revenue by day (?from=&to=)
 *   ?type=finance_expenses     -> requires finance.manage : expenses by category
 *   ?type=finance_profit_loss  -> requires finance.manage : Sales - Refunds - Purchases - Payroll - Expenses
 *   ?type=finance_cash_flow    -> requires finance.manage : cash in/out by day
 *   ?type=finance_payable      -> requires finance.manage : same shape as accounts_payable.php
 *   ?type=finance_purchases    -> requires finance.manage : inventory purchase transactions
 * =====================================================================================
 */
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

$type = trim((string) ($_GET['type'] ?? ''));
$from = trim((string) ($_GET['from'] ?? '')) ?: date('Y-m-01');
$to   = trim((string) ($_GET['to'] ?? ''))   ?: date('Y-m-d');

try {
    switch ($type) {
        case 'inventory_current':
            requirePermission('inventory.manage');
            $rows = $pdo->query(
                "SELECT p.id, p.name, p.sku, p.category, p.stock_quantity, p.unit, p.reorder_level,
                        p.max_stock, p.cost_price, p.price, s.name AS supplier_name, p.status
                 FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id
                 ORDER BY p.category, p.name"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'inventory_low_stock':
            requirePermission('inventory.manage');
            $rows = $pdo->query(
                "SELECT p.id, p.name, p.sku, p.stock_quantity, p.reorder_level, p.max_stock, s.name AS supplier_name,
                        CASE WHEN p.stock_quantity <= 0 THEN 'Out of Stock' ELSE 'Low Stock' END AS status_label
                 FROM products p LEFT JOIN suppliers s ON s.id = p.supplier_id
                 WHERE p.stock_quantity <= p.reorder_level AND p.status = 'Active'
                 ORDER BY p.stock_quantity ASC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'inventory_movement':
            requirePermission('inventory.manage');
            $rows = $pdo->query(
                "SELECT m.id, m.sku, p.name AS product_name, m.movement_type, m.quantity,
                        m.previous_stock, m.new_stock, m.performed_by, m.created_at
                 FROM inventory_movements m LEFT JOIN products p ON p.id = m.product_id
                 ORDER BY m.id DESC LIMIT 500"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'inventory_value':
            requirePermission('inventory.manage');
            $rows = $pdo->query(
                "SELECT category, SUM(stock_quantity) AS total_units, ROUND(SUM(stock_quantity * cost_price), 2) AS total_value
                 FROM products WHERE status = 'Active' GROUP BY category ORDER BY total_value DESC"
            )->fetchAll();
            $grandTotal = array_sum(array_column($rows, 'total_value'));
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows, 'grand_total' => round($grandTotal, 2)]);
            break;

        case 'hr_employees':
            requirePermission('hr.employees.view');
            $rows = $pdo->query(
                "SELECT id, employee_no, full_name, position, department, employment_status, hire_date, leave_balance
                 FROM employees ORDER BY department, full_name"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'hr_attendance':
            requirePermission('hr.attendance.view');
            $stmt = $pdo->prepare(
                "SELECT e.full_name, e.department, al.log_date, al.time_in, al.time_out, al.status
                 FROM attendance_logs al JOIN employees e ON e.id = al.employee_id
                 WHERE al.log_date BETWEEN :from AND :to
                 ORDER BY al.log_date DESC, e.full_name"
            );
            $stmt->execute([':from' => $from, ':to' => $to]);
            $rows = $stmt->fetchAll();
            $summary = ['Present' => 0, 'Late' => 0, 'Absent' => 0, 'On Leave' => 0];
            foreach ($rows as $r) { $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1; }
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows, 'summary' => $summary]);
            break;

        case 'hr_leave':
            requirePermission('hr.requests.view');
            $rows = $pdo->query(
                "SELECT hr.id, e.full_name, hr.request_type, hr.status, hr.start_date, hr.end_date, hr.reason, hr.created_at
                 FROM hr_requests hr JOIN employees e ON e.id = hr.employee_id
                 WHERE hr.request_type = 'Leave'
                 ORDER BY hr.created_at DESC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'hr_overtime':
            requirePermission('payroll.manage');
            $rows = $pdo->query(
                "SELECT e.full_name, pr.pay_period_start, pr.pay_period_end, pr.overtime_hours, pr.overtime_pay, pr.status
                 FROM payroll pr JOIN employees e ON e.id = pr.employee_id
                 WHERE pr.overtime_hours > 0 ORDER BY pr.pay_period_start DESC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'hr_payroll':
            requirePermission('payroll.manage');
            $rows = $pdo->query(
                "SELECT pr.id, e.full_name, pr.pay_period_start, pr.pay_period_end, pr.net_pay, pr.status, pr.paid_at
                 FROM payroll pr JOIN employees e ON e.id = pr.employee_id
                 ORDER BY pr.pay_period_start DESC"
            )->fetchAll();
            $totalPaid = array_sum(array_map(static fn($r) => $r['status'] === 'Paid' ? (float) $r['net_pay'] : 0, $rows));
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows, 'total_paid' => round($totalPaid, 2)]);
            break;

        case 'finance_revenue':
            requirePermission('finance.manage');
            $stmt = $pdo->prepare(
                "SELECT date(created_at) AS day, SUM(amount) AS total
                 FROM financial_transactions WHERE transaction_type = 'Sale' AND date(created_at) BETWEEN :from AND :to
                 GROUP BY date(created_at) ORDER BY day"
            );
            $stmt->execute([':from' => $from, ':to' => $to]);
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $stmt->fetchAll()]);
            break;

        case 'finance_expenses':
            requirePermission('finance.manage');
            $rows = $pdo->query(
                "SELECT category, COUNT(*) AS count, SUM(amount) AS total FROM expenses WHERE status='Approved' GROUP BY category ORDER BY total DESC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'finance_profit_loss':
            requirePermission('finance.manage');
            $sum = function (string $t) use ($pdo, $from, $to): float {
                $stmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM financial_transactions WHERE transaction_type = :t AND date(created_at) BETWEEN :from AND :to"
                );
                $stmt->execute([':t' => $t, ':from' => $from, ':to' => $to]);
                return (float) $stmt->fetchColumn();
            };
            $sales = $sum('Sale'); $refunds = $sum('Refund'); $purchases = $sum('Purchase');
            $payroll = $sum('Payroll'); $expenses = $sum('Expense');
            $netIncome = $sales - $refunds - $purchases - $payroll - $expenses;
            echo json_encode(['ok' => true, 'type' => $type, 'from' => $from, 'to' => $to, 'rows' => [
                ['label' => 'Revenue (Sales)', 'amount' => round($sales, 2)],
                ['label' => 'Refunds', 'amount' => round(-$refunds, 2)],
                ['label' => 'Inventory Purchases', 'amount' => round(-$purchases, 2)],
                ['label' => 'Payroll', 'amount' => round(-$payroll, 2)],
                ['label' => 'Other Expenses', 'amount' => round(-$expenses, 2)],
                ['label' => 'Net Income', 'amount' => round($netIncome, 2)],
            ]]);
            break;

        case 'finance_cash_flow':
            requirePermission('finance.manage');
            $stmt = $pdo->prepare(
                "SELECT date(created_at) AS day,
                        SUM(CASE WHEN direction='In' THEN amount ELSE 0 END) AS cash_in,
                        SUM(CASE WHEN direction='Out' THEN amount ELSE 0 END) AS cash_out
                 FROM financial_transactions WHERE date(created_at) BETWEEN :from AND :to
                 GROUP BY date(created_at) ORDER BY day"
            );
            $stmt->execute([':from' => $from, ':to' => $to]);
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $stmt->fetchAll()]);
            break;

        case 'finance_payable':
            requirePermission('finance.manage');
            $rows = $pdo->query(
                "SELECT ap.id, s.name AS supplier_name, ap.invoice_number, ap.total_amount, ap.amount_paid,
                        (ap.total_amount - ap.amount_paid) AS balance, ap.due_date, ap.status
                 FROM accounts_payable ap LEFT JOIN suppliers s ON s.id = ap.supplier_id
                 ORDER BY ap.due_date"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        case 'finance_purchases':
            requirePermission('finance.manage');
            $rows = $pdo->query(
                "SELECT ft.id, ft.reference_id, ft.amount, ft.description, ft.created_at, po.po_number, s.name AS supplier_name
                 FROM financial_transactions ft
                 LEFT JOIN purchase_orders po ON po.id = ft.reference_id
                 LEFT JOIN suppliers s ON s.id = po.supplier_id
                 WHERE ft.transaction_type = 'Purchase' ORDER BY ft.id DESC"
            )->fetchAll();
            echo json_encode(['ok' => true, 'type' => $type, 'rows' => $rows]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown or missing report type.']);
    }
} catch (Throwable $e) {
    error_log('reports.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to generate report.']);
}
