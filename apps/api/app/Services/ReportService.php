<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class ReportService
{
    public const TYPES = [
        'sales',
        'inventory',
        'procurement',
        'hr',
        'payroll',
        'finance',
    ];

    public function __construct(
        private readonly SystemSettingsService $settings,
        private readonly ReportFilterService $filterService,
    ) {}

    public function report(string $type, array $filters): array
    {
        $query = $this->query($type, $filters);
        $records = $query->paginate($filters['per_page'] ?? 25);

        return [
            'type' => $type,
            'generated_at' => now($this->settings->all()['timezone'])->toIso8601String(),
            'filters' => $filters,
            'filter_descriptions' => $this->filterService->descriptions($filters),
            'summary' => $this->summary($type, $filters),
            'columns' => $this->columns($type),
            'records' => $records,
            'settings' => $this->settings->public(),
            'notes' => $this->notes($type),
        ];
    }

    public function exportRows(string $type, array $filters): LazyCollection
    {
        return $this->query($type, $filters)
            ->limit(10000)
            ->cursor();
    }

    public function columns(string $type): array
    {
        return match ($type) {
            'sales' => [
                ['key' => 'timestamp', 'label' => 'Date'],
                ['key' => 'order_id', 'label' => 'Order'],
                ['key' => 'cashier_username', 'label' => 'Cashier'],
                ['key' => 'payment_method', 'label' => 'Payment method'],
                ['key' => 'transaction_status', 'label' => 'Status'],
                ['key' => 'item_sku', 'label' => 'SKU'],
                ['key' => 'product_name', 'label' => 'Product'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'quantity_sold', 'label' => 'Quantity'],
                ['key' => 'subtotal_amount', 'label' => 'Subtotal', 'format' => 'money'],
                ['key' => 'tax_amount', 'label' => 'Tax', 'format' => 'money'],
                ['key' => 'discount_amount', 'label' => 'Discount', 'format' => 'money'],
                ['key' => 'total_price', 'label' => 'Gross total', 'format' => 'money'],
            ],
            'inventory' => [
                ['key' => 'sku', 'label' => 'SKU'],
                ['key' => 'name', 'label' => 'Product'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'supplier_name', 'label' => 'Supplier'],
                ['key' => 'stock_quantity', 'label' => 'Current stock'],
                ['key' => 'reorder_level', 'label' => 'Reorder level'],
                ['key' => 'max_stock', 'label' => 'Maximum stock'],
                ['key' => 'stock_state', 'label' => 'Stock state'],
                ['key' => 'movement_count', 'label' => 'Movements'],
                ['key' => 'stock_in', 'label' => 'Stock in'],
                ['key' => 'stock_out', 'label' => 'Stock out'],
                ['key' => 'last_movement_at', 'label' => 'Last movement'],
                ['key' => 'cost_valuation', 'label' => 'Cost valuation', 'format' => 'money'],
                ['key' => 'retail_valuation', 'label' => 'Retail valuation', 'format' => 'money'],
            ],
            'procurement' => [
                ['key' => 'order_date', 'label' => 'Order date'],
                ['key' => 'po_number', 'label' => 'PO number'],
                ['key' => 'restock_ref_number', 'label' => 'Restock request'],
                ['key' => 'restock_status', 'label' => 'Restock status'],
                ['key' => 'supplier_name', 'label' => 'Supplier'],
                ['key' => 'approval_status', 'label' => 'Approval'],
                ['key' => 'operational_status', 'label' => 'Operational status'],
                ['key' => 'sku', 'label' => 'SKU'],
                ['key' => 'quantity_ordered', 'label' => 'Ordered'],
                ['key' => 'fulfilled_quantity', 'label' => 'Fulfilled'],
                ['key' => 'outstanding_quantity', 'label' => 'Outstanding'],
                ['key' => 'delivered_quantity', 'label' => 'Delivered'],
                ['key' => 'accepted_quantity', 'label' => 'Accepted'],
                ['key' => 'damaged_quantity', 'label' => 'Damaged'],
                ['key' => 'rejected_quantity', 'label' => 'Rejected'],
                ['key' => 'purchase_cost', 'label' => 'Accepted cost', 'format' => 'money'],
                ['key' => 'receiving_events', 'label' => 'Receiving events'],
                ['key' => 'last_receiving_at', 'label' => 'Last receiving'],
                ['key' => 'age_days', 'label' => 'Age (days)'],
            ],
            'hr' => [
                ['key' => 'employee_no', 'label' => 'Employee no.'],
                ['key' => 'full_name', 'label' => 'Employee'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'employment_status', 'label' => 'Status'],
                ['key' => 'attendance_records', 'label' => 'Attendance records'],
                ['key' => 'late_count', 'label' => 'Late'],
                ['key' => 'absent_count', 'label' => 'Absent'],
                ['key' => 'leave_days_used', 'label' => 'Leave used'],
                ['key' => 'leave_balance', 'label' => 'Leave balance'],
                ['key' => 'pending_requests', 'label' => 'Pending requests'],
            ],
            'payroll' => [
                ['key' => 'pay_period_start', 'label' => 'Period start'],
                ['key' => 'pay_period_end', 'label' => 'Period end'],
                ['key' => 'employee_no', 'label' => 'Employee no.'],
                ['key' => 'full_name', 'label' => 'Employee'],
                ['key' => 'department', 'label' => 'Department'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'gross_pay', 'label' => 'Gross pay', 'format' => 'money'],
                ['key' => 'deductions', 'label' => 'Deductions', 'format' => 'money'],
                ['key' => 'net_pay', 'label' => 'Net pay', 'format' => 'money'],
            ],
            'finance' => [
                ['key' => 'record_date', 'label' => 'Date'],
                ['key' => 'record_type', 'label' => 'Record type'],
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'direction', 'label' => 'Direction'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
                ['key' => 'due_date', 'label' => 'Due date'],
                ['key' => 'age_days', 'label' => 'Age (days)'],
                ['key' => 'description', 'label' => 'Description'],
            ],
        };
    }

    private function query(string $type, array $filters): Builder
    {
        return match ($type) {
            'sales' => $this->salesQuery($filters),
            'inventory' => $this->inventoryQuery($filters),
            'procurement' => $this->procurementQuery($filters),
            'hr' => $this->hrQuery($filters),
            'payroll' => $this->payrollQuery($filters),
            'finance' => $this->financeQuery($filters),
        };
    }

    private function salesBase(array $filters): Builder
    {
        $query = DB::table('sales_ledger as sales')
            ->leftJoin('products', 'sales.item_sku', '=', 'products.sku')
            ->whereDate('sales.timestamp', '>=', $filters['from'])
            ->whereDate('sales.timestamp', '<=', $filters['to']);
        foreach ([
            'cashier' => 'sales.cashier_username',
            'payment_method' => 'sales.payment_method',
            'category' => 'products.category',
            'product_id' => 'products.id',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (isset($filters['transaction_status'])) {
            $method = $filters['transaction_status'] === 'Refunded'
                ? 'whereExists'
                : 'whereNotExists';
            $query->{$method}(fn ($refund) => $refund
                ->selectRaw('1')
                ->from('refunds')
                ->whereColumn('refunds.order_id', 'sales.order_id'));
        }

        return $query;
    }

    private function salesQuery(array $filters): Builder
    {
        return $this->salesBase($filters)
            ->select(
                'sales.id',
                'sales.timestamp',
                'sales.order_id',
                'sales.cashier_username',
                'sales.payment_method',
                'sales.item_sku',
                'sales.quantity_sold',
                'sales.total_price',
                'sales.subtotal_amount',
                'sales.tax_amount',
                'sales.discount_amount',
                'products.name as product_name',
                'products.category',
            )
            ->selectRaw(
                'CASE WHEN EXISTS (SELECT 1 FROM refunds '
                .'WHERE refunds.order_id = sales.order_id) '
                ."THEN 'Refunded' ELSE 'Finalized' END as transaction_status",
            )
            ->orderByDesc('sales.timestamp')
            ->orderByDesc('sales.id');
    }

    private function inventoryBase(array $filters): Builder
    {
        $movements = DB::table('inventory_movements')
            ->whereDate('created_at', '>=', $filters['from'])
            ->whereDate('created_at', '<=', $filters['to'])
            ->when(
                isset($filters['movement_type']),
                fn ($query) => $query->where(
                    'movement_type',
                    $filters['movement_type'],
                ),
            )
            ->select('product_id')
            ->selectRaw('COUNT(*) as movement_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END), 0) '
                .'as stock_in',
            )
            ->selectRaw(
                'ABS(COALESCE(SUM(CASE WHEN quantity < 0 THEN quantity ELSE 0 END), 0)) '
                .'as stock_out',
            )
            ->selectRaw('MAX(created_at) as last_movement_at')
            ->groupBy('product_id');
        $query = DB::table('products')
            ->leftJoin('suppliers', 'products.supplier_id', '=', 'suppliers.id');
        if (isset($filters['movement_type'])) {
            $query->joinSub($movements, 'movement_summary', function ($join) {
                $join->on('products.id', '=', 'movement_summary.product_id');
            });
        } else {
            $query->leftJoinSub($movements, 'movement_summary', function ($join) {
                $join->on('products.id', '=', 'movement_summary.product_id');
            });
        }
        if (isset($filters['category'])) {
            $query->where('products.category', $filters['category']);
        }
        if (isset($filters['supplier_id'])) {
            $query->where('products.supplier_id', $filters['supplier_id']);
        }
        match ($filters['stock_state'] ?? 'all') {
            'low' => $query->where('products.stock_quantity', '>', 0)
                ->whereColumn('products.stock_quantity', '<=', 'products.reorder_level'),
            'out' => $query->where('products.stock_quantity', 0),
            'above_max' => $query->whereColumn(
                'products.stock_quantity',
                '>',
                'products.max_stock',
            ),
            default => null,
        };

        return $query;
    }

    private function inventoryQuery(array $filters): Builder
    {
        return $this->inventoryBase($filters)
            ->select(
                'products.id',
                'products.sku',
                'products.name',
                'products.category',
                'products.stock_quantity',
                'products.reorder_level',
                'products.max_stock',
                'suppliers.name as supplier_name',
                'movement_summary.last_movement_at',
            )
            ->selectRaw('COALESCE(movement_summary.movement_count, 0) as movement_count')
            ->selectRaw('COALESCE(movement_summary.stock_in, 0) as stock_in')
            ->selectRaw('COALESCE(movement_summary.stock_out, 0) as stock_out')
            ->selectRaw(
                'ROUND(products.stock_quantity * products.cost_price, 2) as cost_valuation',
            )
            ->selectRaw(
                'ROUND(products.stock_quantity * products.price, 2) as retail_valuation',
            )
            ->selectRaw(
                "CASE WHEN products.stock_quantity = 0 THEN 'Out of stock' "
                ."WHEN products.stock_quantity > products.max_stock THEN 'Above maximum' "
                ."WHEN products.stock_quantity <= products.reorder_level THEN 'Low stock' "
                ."ELSE 'Normal' END as stock_state",
            )
            ->orderBy('products.name');
    }

    private function procurementQuery(array $filters): Builder
    {
        $receipts = DB::table('stock_receiving_items')
            ->join(
                'stock_receivings',
                'stock_receiving_items.stock_receiving_id',
                '=',
                'stock_receivings.id',
            )
            ->select('purchase_order_item_id')
            ->selectRaw('SUM(received_quantity) as delivered_quantity')
            ->selectRaw('SUM(damaged_quantity) as damaged_quantity')
            ->selectRaw('SUM(rejected_quantity) as rejected_quantity')
            ->selectRaw('COUNT(DISTINCT stock_receiving_id) as receiving_events')
            ->selectRaw('MAX(stock_receivings.receiving_date) as last_receiving_at')
            ->groupBy('purchase_order_item_id');
        $query = DB::table('purchase_order_items as items')
            ->join('purchase_orders as orders', 'items.purchase_order_id', '=', 'orders.id')
            ->leftJoin('suppliers', 'orders.supplier_id', '=', 'suppliers.id')
            ->leftJoin(
                'restock_requests',
                'orders.restock_request_id',
                '=',
                'restock_requests.id',
            )
            ->leftJoinSub($receipts, 'receipts', function ($join) {
                $join->on('items.id', '=', 'receipts.purchase_order_item_id');
            })
            ->whereDate('orders.order_date', '>=', $filters['from'])
            ->whereDate('orders.order_date', '<=', $filters['to']);
        foreach ([
            'supplier_id' => 'orders.supplier_id',
            'approval_status' => 'orders.approval_status',
            'operational_status' => 'orders.status',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query->select(
            'items.id',
            'orders.order_date',
            'orders.po_number',
            'orders.approval_status',
            'orders.status as operational_status',
            'suppliers.name as supplier_name',
            'restock_requests.ref_number as restock_ref_number',
            'restock_requests.status as restock_status',
            'items.sku',
            'items.quantity_ordered',
            'items.quantity_received as fulfilled_quantity',
        )
            ->selectRaw(
                'MAX(items.quantity_ordered - items.quantity_received, 0) '
                .'as outstanding_quantity',
            )
            ->selectRaw('COALESCE(receipts.delivered_quantity, 0) as delivered_quantity')
            ->selectRaw('COALESCE(receipts.damaged_quantity, 0) as damaged_quantity')
            ->selectRaw('COALESCE(receipts.rejected_quantity, 0) as rejected_quantity')
            ->selectRaw('COALESCE(receipts.receiving_events, 0) as receiving_events')
            ->selectRaw('receipts.last_receiving_at')
            ->selectRaw(
                'COALESCE(receipts.delivered_quantity, 0) '
                .'- COALESCE(receipts.damaged_quantity, 0) '
                .'- COALESCE(receipts.rejected_quantity, 0) as accepted_quantity',
            )
            ->selectRaw(
                'ROUND(items.quantity_received * items.unit_cost, 2) as purchase_cost',
            )
            ->selectRaw(
                'MAX(CAST(julianday(date(\'now\')) - '
                .'julianday(date(orders.order_date)) AS INTEGER), 0) as age_days',
            )
            ->orderByDesc('orders.order_date')
            ->orderByDesc('items.id');
    }

    private function hrQuery(array $filters): Builder
    {
        $from = $filters['from'];
        $to = $filters['to'];
        $query = DB::table('employees')
            ->select(
                'employees.id',
                'employees.employee_no',
                'employees.full_name',
                'employees.department',
                'employees.employment_status',
                'employees.leave_balance',
            )
            ->selectSub(
                DB::table('attendance_logs')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('attendance_logs.employee_id', 'employees.id')
                    ->whereBetween('attendance_logs.log_date', [$from, $to])
                    ->when(
                        isset($filters['attendance_status']),
                        fn ($item) => $item->where(
                            'attendance_logs.status',
                            $filters['attendance_status'],
                        ),
                    ),
                'attendance_records',
            )
            ->selectSub(
                DB::table('attendance_logs')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('attendance_logs.employee_id', 'employees.id')
                    ->whereBetween('attendance_logs.log_date', [$from, $to])
                    ->where('attendance_logs.status', 'Late'),
                'late_count',
            )
            ->selectSub(
                DB::table('attendance_logs')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('attendance_logs.employee_id', 'employees.id')
                    ->whereBetween('attendance_logs.log_date', [$from, $to])
                    ->where('attendance_logs.status', 'Absent'),
                'absent_count',
            )
            ->selectSub(
                DB::table('hr_requests')
                    ->selectRaw(
                        "COALESCE(SUM(CASE WHEN request_type = 'Leave' "
                        ."AND status = 'Approved' THEN "
                        .'julianday(end_date) - julianday(start_date) + 1 '
                        .'ELSE 0 END), 0)',
                    )
                    ->whereColumn('hr_requests.employee_id', 'employees.id')
                    ->whereBetween(DB::raw('date(hr_requests.created_at)'), [$from, $to]),
                'leave_days_used',
            )
            ->selectSub(
                DB::table('hr_requests')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('hr_requests.employee_id', 'employees.id')
                    ->whereBetween(DB::raw('date(hr_requests.created_at)'), [$from, $to])
                    ->where('hr_requests.status', 'Pending'),
                'pending_requests',
            );
        if (isset($filters['employee_status'])) {
            $query->where('employees.employment_status', $filters['employee_status']);
        }
        if (isset($filters['department'])) {
            $query->where('employees.department', $filters['department']);
        }
        if (isset($filters['hr_request_status'])) {
            $query->whereExists(fn ($request) => $request
                ->selectRaw('1')
                ->from('hr_requests')
                ->whereColumn('hr_requests.employee_id', 'employees.id')
                ->where('hr_requests.status', $filters['hr_request_status'])
                ->whereBetween(DB::raw('date(hr_requests.created_at)'), [$from, $to]));
        }

        return $query->orderBy('employees.full_name');
    }

    private function payrollQuery(array $filters): Builder
    {
        $query = DB::table('payroll')
            ->join('employees', 'payroll.employee_id', '=', 'employees.id')
            ->whereDate('payroll.pay_period_end', '>=', $filters['from'])
            ->whereDate('payroll.pay_period_end', '<=', $filters['to']);
        foreach ([
            'department' => 'employees.department',
            'employee_id' => 'employees.id',
            'payroll_status' => 'payroll.status',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query->select(
            'payroll.id',
            'payroll.pay_period_start',
            'payroll.pay_period_end',
            'payroll.status',
            'payroll.deductions',
            'payroll.net_pay',
            'employees.employee_no',
            'employees.full_name',
            'employees.department',
        )
            ->selectRaw(
                'ROUND(payroll.basic_salary + payroll.overtime_pay '
                .'+ payroll.allowances + payroll.bonuses, 2) as gross_pay',
            )
            ->orderByDesc('payroll.pay_period_end')
            ->orderBy('employees.full_name');
    }

    private function financeQuery(array $filters): Builder
    {
        $transactions = DB::table('financial_transactions')
            ->selectRaw(
                'id as source_id, created_at as record_date, '
                ."'Transaction' as record_type, "
                .'COALESCE(category, transaction_type) as category, transaction_type, direction, '
                ."'Posted' as status, amount, NULL as due_date, 0 as age_days, "
                .'COALESCE(description, transaction_type) as description',
            );
        $payables = DB::table('accounts_payable')
            ->leftJoin('suppliers', 'accounts_payable.supplier_id', '=', 'suppliers.id')
            ->selectRaw(
                'accounts_payable.id as source_id, accounts_payable.created_at as record_date, '
                ."'Accounts Payable' as record_type, 'Accounts Payable' as category, NULL as transaction_type, "
                ."'Out' as direction, accounts_payable.status, "
                .'ROUND(accounts_payable.total_amount - accounts_payable.amount_paid, 2) as amount, '
                .'accounts_payable.due_date, '
                .'MAX(CASE WHEN accounts_payable.due_date IS NULL THEN 0 '
                ."ELSE CAST(julianday(date('now')) - julianday(accounts_payable.due_date) AS INTEGER) "
                .'END, 0) as age_days, '
                ."COALESCE(suppliers.name || ' / ' || accounts_payable.invoice_number, suppliers.name, accounts_payable.invoice_number, 'Payable') "
                .'as description',
            );
        $query = DB::query()->fromSub($transactions->unionAll($payables), 'finance_records')
            ->whereDate('record_date', '>=', $filters['from'])
            ->whereDate('record_date', '<=', $filters['to']);
        foreach ([
            'category' => 'category',
            'direction' => 'direction',
            'finance_status' => 'status',
        ] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query->orderByDesc('record_date')->orderByDesc('source_id');
    }

    private function summary(string $type, array $filters): array
    {
        return match ($type) {
            'sales' => $this->salesSummary($filters),
            'inventory' => $this->inventorySummary($filters),
            'procurement' => $this->procurementSummary($filters),
            'hr' => $this->aggregateSummary(
                $this->hrQuery($filters),
                [
                    'employees' => 'COUNT(*)',
                    'attendance_records' => 'SUM(attendance_records)',
                    'late' => 'SUM(late_count)',
                    'absent' => 'SUM(absent_count)',
                    'leave_days_used' => 'ROUND(SUM(leave_days_used), 2)',
                    'remaining_leave' => 'ROUND(SUM(leave_balance), 2)',
                ],
            ),
            'payroll' => $this->aggregateSummary(
                $this->payrollQuery($filters),
                [
                    'records' => 'COUNT(*)',
                    'gross_pay' => 'ROUND(SUM(gross_pay), 2)',
                    'deductions' => 'ROUND(SUM(deductions), 2)',
                    'net_pay' => 'ROUND(SUM(net_pay), 2)',
                ],
            ),
            'finance' => $this->financeSummary($filters),
        };
    }

    private function salesSummary(array $filters): array
    {
        $base = $this->salesBase($filters);
        $row = (clone $base)->selectRaw(
            'ROUND(COALESCE(SUM(sales.total_price), 0), 2) as gross_sales, '
            .'COUNT(DISTINCT sales.order_id) as transaction_count, '
            .'ROUND(COALESCE(SUM(sales.tax_amount), 0), 2) as tax_total, '
            .'ROUND(COALESCE(SUM(sales.discount_amount), 0), 2) as discount_total, '
            .'SUM(CASE WHEN sales.tax_amount IS NULL THEN 1 ELSE 0 END) '
            .'as tax_unknown_records, '
            .'SUM(CASE WHEN sales.discount_amount IS NULL THEN 1 ELSE 0 END) '
            .'as discount_unknown_records, '
            .'COALESCE(SUM(sales.quantity_sold), 0) as quantity_sold',
        )->first();
        $orderIds = (clone $base)->select('sales.order_id');
        $refunds = round((float) DB::table('refunds')
            ->whereIn('order_id', $orderIds)
            ->sum('refund_amount'), 2);
        $transactions = (int) $row->transaction_count;

        return [
            'gross_sales' => (float) $row->gross_sales,
            'refunds' => $refunds,
            'net_sales' => round((float) $row->gross_sales - $refunds, 2),
            'transaction_count' => $transactions,
            'average_transaction' => $transactions > 0
                ? round((float) $row->gross_sales / $transactions, 2)
                : 0,
            'tax_total' => (float) $row->tax_total,
            'discount_total' => (float) $row->discount_total,
            'tax_unknown_records' => (int) $row->tax_unknown_records,
            'discount_unknown_records' => (int) $row->discount_unknown_records,
            'quantity_sold' => (int) $row->quantity_sold,
        ];
    }

    private function inventorySummary(array $filters): array
    {
        $row = $this->inventoryBase($filters)->selectRaw(
            'COUNT(*) as products, '
            .'COALESCE(SUM(products.stock_quantity), 0) as units, '
            .'ROUND(COALESCE(SUM(products.stock_quantity * products.cost_price), 0), 2) '
            .'as cost_valuation, '
            .'ROUND(COALESCE(SUM(products.stock_quantity * products.price), 0), 2) '
            .'as retail_valuation, '
            .'SUM(CASE WHEN products.stock_quantity = 0 THEN 1 ELSE 0 END) as out_of_stock, '
            .'SUM(CASE WHEN products.stock_quantity <= products.reorder_level THEN 1 ELSE 0 END) '
            .'as low_stock, '
            .'SUM(CASE WHEN products.stock_quantity > products.max_stock THEN 1 ELSE 0 END) '
            .'as above_maximum',
        )->first();
        $movements = DB::table('inventory_movements')
            ->join('products', 'inventory_movements.product_id', '=', 'products.id')
            ->whereDate('inventory_movements.created_at', '>=', $filters['from'])
            ->whereDate('inventory_movements.created_at', '<=', $filters['to'])
            ->when(
                isset($filters['category']),
                fn ($query) => $query->where('products.category', $filters['category']),
            )
            ->when(
                isset($filters['supplier_id']),
                fn ($query) => $query->where('products.supplier_id', $filters['supplier_id']),
            )
            ->when(
                isset($filters['movement_type']),
                fn ($query) => $query->where(
                    'inventory_movements.movement_type',
                    $filters['movement_type'],
                ),
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN inventory_movements.quantity > 0 '
                .'THEN inventory_movements.quantity ELSE 0 END), 0) as stock_in, '
                .'ABS(COALESCE(SUM(CASE WHEN inventory_movements.quantity < 0 '
                .'THEN inventory_movements.quantity ELSE 0 END), 0)) as stock_out',
            )
            ->first();

        return [
            'products' => (int) $row->products,
            'units' => (int) $row->units,
            'cost_valuation' => (float) $row->cost_valuation,
            'retail_valuation' => (float) $row->retail_valuation,
            'low_stock' => (int) $row->low_stock,
            'out_of_stock' => (int) $row->out_of_stock,
            'above_maximum' => (int) $row->above_maximum,
            'stock_in' => (int) $movements->stock_in,
            'stock_out' => (int) $movements->stock_out,
        ];
    }

    private function financeSummary(array $filters): array
    {
        $row = DB::query()
            ->fromSub($this->financeQuery($filters)->reorder(), 'finance_summary')
            ->selectRaw(
                "ROUND(COALESCE(SUM(CASE WHEN record_type = 'Transaction' "
                ."AND direction = 'In' THEN amount ELSE 0 END), 0), 2) as revenue, "
                ."ROUND(COALESCE(SUM(CASE WHEN record_type = 'Transaction' "
                ."AND direction = 'Out' AND transaction_type != 'Supplier Payment' "
                .'THEN amount ELSE 0 END), 0), 2) as expenses, '
                ."ROUND(COALESCE(SUM(CASE WHEN transaction_type = 'Supplier Payment' "
                ."AND direction = 'Out' THEN amount ELSE 0 END), 0), 2) as supplier_payments, "
                ."ROUND(COALESCE(SUM(CASE WHEN record_type = 'Accounts Payable' "
                .'THEN amount ELSE 0 END), 0), 2) as accounts_payable',
            )
            ->first();
        $revenue = (float) $row->revenue;
        $expenses = (float) $row->expenses;

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_movement' => round($revenue - $expenses, 2),
            'supplier_payments' => (float) $row->supplier_payments,
            'accounts_payable' => (float) $row->accounts_payable,
            'accounts_receivable' => 0,
            'accounts_receivable_supported' => false,
        ];
    }

    private function procurementSummary(array $filters): array
    {
        $summary = $this->aggregateSummary(
            $this->procurementQuery($filters),
            [
                'ordered' => 'SUM(quantity_ordered)',
                'fulfilled' => 'SUM(fulfilled_quantity)',
                'outstanding' => 'SUM(outstanding_quantity)',
                'delivered' => 'SUM(delivered_quantity)',
                'accepted' => 'SUM(accepted_quantity)',
                'damaged' => 'SUM(damaged_quantity)',
                'rejected' => 'SUM(rejected_quantity)',
                'purchase_cost' => 'ROUND(SUM(purchase_cost), 2)',
                'receiving_events' => 'SUM(receiving_events)',
            ],
        );
        $summary['restock_requests'] = DB::table('restock_requests')
            ->whereDate('created_at', '>=', $filters['from'])
            ->whereDate('created_at', '<=', $filters['to'])
            ->when(
                isset($filters['supplier_id']),
                fn ($query) => $query->where('supplier_id', $filters['supplier_id']),
            )
            ->count();

        return $summary;
    }

    private function aggregateSummary(Builder $query, array $expressions): array
    {
        $selects = collect($expressions)
            ->map(fn ($expression, $alias) => "{$expression} as {$alias}")
            ->implode(', ');
        $row = DB::query()
            ->fromSub($query->reorder(), 'report_summary')
            ->selectRaw($selects)
            ->first();

        return collect($expressions)
            ->keys()
            ->mapWithKeys(fn ($key) => [
                $key => is_numeric($row->{$key} ?? null)
                    ? (float) $row->{$key}
                    : 0,
            ])
            ->all();
    }

    private function notes(string $type): array
    {
        $tax = $this->settings->all();

        return match ($type) {
            'sales' => [
                'Historical rows use stored totals and stored tax snapshots.',
                $tax['tax_inclusive']
                    ? 'Current configured prices are tax-inclusive.'
                    : 'Current configured prices are tax-exclusive.',
                'Rows finalized before tax snapshots were introduced may have null tax fields.',
                'Historical discount amounts remain null when the legacy ledger did not store the amount.',
            ],
            'inventory' => [
                'Movement totals use the selected date range and movement-type filter.',
                'Valuations use current on-hand quantities and current product costs and prices.',
            ],
            'procurement' => [
                'Fulfilled quantity equals accepted quantity under current policy.',
                'Delivered history retains damaged and rejected quantities.',
            ],
            'finance' => [
                'Accounts receivable is not modeled in the current schema and is reported as unavailable.',
                'Supplier Payment entries settle Accounts Payable liabilities. They are displayed separately from expense recognition to prevent double-counting procurement costs already recorded through Purchase / Out.',
            ],
            default => [],
        };
    }
}
