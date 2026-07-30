<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly SystemSettingsService $settings,
    ) {}

    public function for(User $user): array
    {
        $user->loadMissing('role');
        $settings = $this->settings->all();
        $now = CarbonImmutable::now($settings['timezone']);
        $today = $now->startOfDay();
        $month = $now->startOfMonth();
        $payload = [
            'generated_at' => $now->toIso8601String(),
            'timezone' => $settings['timezone'],
            'settings' => $this->settings->public(),
            'metrics' => [],
            'sections' => [],
            'charts' => [],
        ];

        if ($user->hasAnyPermission('system.users.manage', 'system.roles.manage')) {
            $payload['metrics'] = array_merge($payload['metrics'], [
                $this->metric(
                    'active_users',
                    'Active users',
                    DB::table('admin_users')->where('status', 'Active')->count(),
                ),
                $this->metric('roles', 'Roles', DB::table('roles')->count()),
            ]);
        }
        if ($user->hasAnyPermission('system.audit.view')) {
            $payload['sections']['recent_audit'] = DB::table('audit_logs')
                ->select('id', 'created_at', 'username', 'action', 'entity_type')
                ->orderByDesc('id')
                ->limit(8)
                ->get();
        }
        if ($user->hasAnyPermission('system.settings.manage', 'system.backups.manage')) {
            $payload['sections']['system_health'] = $this->systemHealth();
        }

        if ($user->hasAnyPermission('pos.access', 'reports.sales.view')) {
            $sales = $this->salesSummary(
                $today->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
                $user->hasAnyPermission('reports.sales.view')
                    ? null
                    : $user->username,
            );
            $payload['metrics'] = array_merge($payload['metrics'], [
                $this->metric('today_sales', "Today's sales", $sales['total'], 'money'),
                $this->metric('today_transactions', 'Transactions', $sales['transactions']),
                $this->metric('average_transaction', 'Average transaction', $sales['average'], 'money'),
            ]);
            $payload['sections']['recent_sales'] = DB::table('sales_ledger')
                ->select(
                    'order_id',
                    DB::raw('MAX(timestamp) as timestamp'),
                    DB::raw('ROUND(SUM(total_price), 2) as total'),
                    DB::raw('SUM(quantity_sold) as items'),
                    DB::raw('MAX(payment_method) as payment_method'),
                    DB::raw('MAX(cashier_username) as cashier_username'),
                )
                ->when(
                    ! $user->hasAnyPermission('reports.sales.view'),
                    fn ($query) => $query->where('cashier_username', $user->username),
                )
                ->groupBy('order_id')
                ->orderByDesc('timestamp')
                ->limit(8)
                ->get();
            $payload['charts']['sales_last_7_days'] = $this->salesChart($now, $user);
            if ($user->hasAnyPermission('reports.sales.view')) {
                $monthSales = $this->salesSummary(
                    $month->format('Y-m-d H:i:s'),
                    $now->format('Y-m-d H:i:s'),
                    null,
                );
                $payload['metrics'][] = $this->metric(
                    'month_sales',
                    'Current month sales',
                    $monthSales['total'],
                    'money',
                );
            }
        }
        if ($user->hasAnyPermission('pos.refund')) {
            $payload['metrics'][] = $this->metric(
                'month_refunds',
                'Month refunds',
                round((float) DB::table('refunds')
                    ->where('created_at', '>=', $month->format('Y-m-d H:i:s'))
                    ->sum('refund_amount'), 2),
                'money',
            );
        }

        if ($user->hasAnyPermission('hr.employees.view', 'reports.hr.view')) {
            $activeEmployees = DB::table('employees')
                ->where('employment_status', 'Active')
                ->count();
            $payload['metrics'][] = $this->metric(
                'active_employees',
                'Active employees',
                $activeEmployees,
            );
            $payload['metrics'][] = $this->metric(
                'employees_on_leave',
                'Employees on leave',
                DB::table('employees')->where('employment_status', 'On Leave')->count(),
            );
            $payload['sections']['low_leave_balances'] = DB::table('employees')
                ->where('employment_status', '!=', 'Terminated')
                ->where('leave_balance', '<=', 5)
                ->orderBy('leave_balance')
                ->limit(8)
                ->get(['id', 'employee_no', 'full_name', 'leave_balance']);
        }
        if ($user->hasAnyPermission('hr.requests.view', 'reports.hr.view')) {
            $payload['metrics'][] = $this->metric(
                'pending_hr_requests',
                'Pending HR requests',
                DB::table('hr_requests')->where('status', 'Pending')->count(),
            );
        }
        if ($user->hasAnyPermission('hr.attendance.view', 'reports.hr.view')) {
            $attendance = DB::table('attendance_logs')
                ->where('log_date', $today->toDateString())
                ->selectRaw(
                    'COUNT(*) as total, '
                    ."SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present, "
                    ."SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late, "
                    ."SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent",
                )
                ->first();
            $payload['sections']['attendance_today'] = [
                'records' => (int) $attendance->total,
                'present' => (int) $attendance->present,
                'late' => (int) $attendance->late,
                'absent' => (int) $attendance->absent,
            ];
        }

        if ($user->hasAnyPermission('payroll.manage', 'reports.payroll.view')) {
            $payroll = DB::table('payroll')
                ->whereIn('status', ['Draft', 'Pending Approval', 'Approved'])
                ->selectRaw('COUNT(*) as records, ROUND(COALESCE(SUM(net_pay), 0), 2) as obligation')
                ->first();
            $payload['metrics'][] = $this->metric(
                'outstanding_payroll',
                'Outstanding payroll',
                (float) $payroll->obligation,
                'money',
            );
            $latestPeriod = DB::table('payroll')
                ->orderByDesc('pay_period_end')
                ->orderByDesc('pay_period_start')
                ->first(['pay_period_start', 'pay_period_end']);
            $currentPeriod = $latestPeriod
                ? DB::table('payroll')
                    ->where('pay_period_start', $latestPeriod->pay_period_start)
                    ->where('pay_period_end', $latestPeriod->pay_period_end)
                    ->selectRaw(
                        'COUNT(*) as records, '
                        .'ROUND(COALESCE(SUM(net_pay), 0), 2) as net_pay',
                    )
                    ->first()
                : null;
            $payload['sections']['payroll_summary'] = [
                'period_start' => $latestPeriod?->pay_period_start,
                'period_end' => $latestPeriod?->pay_period_end,
                'period_records' => (int) ($currentPeriod?->records ?? 0),
                'period_net_pay' => (float) ($currentPeriod?->net_pay ?? 0),
                'outstanding_records' => (int) $payroll->records,
                'outstanding_obligation' => (float) $payroll->obligation,
            ];
        }

        if ($user->hasAnyPermission('finance.manage', 'reports.finance.view')) {
            $todayRevenue = $this->financialSum(
                'In',
                $today->format('Y-m-d H:i:s'),
            );
            $monthRevenue = $this->financialSum(
                'In',
                $month->format('Y-m-d H:i:s'),
            );
            $monthExpenses = $this->financialSum(
                'Out',
                $month->format('Y-m-d H:i:s'),
            );
            $payables = DB::table('accounts_payable')
                ->whereIn('status', ['Unpaid', 'Partially Paid', 'Overdue'])
                ->selectRaw(
                    'ROUND(COALESCE(SUM(total_amount - amount_paid), 0), 2) as outstanding',
                )
                ->first();
            $payload['metrics'] = array_merge($payload['metrics'], [
                $this->metric('today_revenue', 'Today revenue', $todayRevenue, 'money'),
                $this->metric('month_revenue', 'Month revenue', $monthRevenue, 'money'),
                $this->metric('month_expenses', 'Month expenses', $monthExpenses, 'money'),
                $this->metric('net_movement', 'Net movement', round($monthRevenue - $monthExpenses, 2), 'money'),
                $this->metric(
                    'accounts_payable',
                    'Accounts payable',
                    (float) $payables->outstanding,
                    'money',
                ),
            ]);
            $payload['sections']['accounts_receivable'] = [
                'supported' => false,
                'total' => 0,
                'message' => 'Accounts receivable is not represented in the current schema.',
            ];
            $payload['sections']['recent_financial_transactions'] = DB::table('financial_transactions')
                ->orderByDesc('id')
                ->limit(8)
                ->get([
                    'id',
                    'created_at',
                    'transaction_type',
                    'direction',
                    'amount',
                    'description',
                ]);
        }

        if ($user->hasAnyPermission(
            'restock.approve',
            'procurement.purchase_orders.approve',
            'reports.procurement.view',
        )) {
            foreach ([
                ['submitted_purchase_orders', 'Submitted purchase orders', 'Submitted', null],
                ['partially_received_orders', 'Partially received orders', null, 'Partially Received'],
                ['fully_received_orders', 'Fully received orders', null, 'Fully Received'],
            ] as [$key, $label, $approval, $status]) {
                $query = DB::table('purchase_orders');
                if ($approval) {
                    $query->where('approval_status', $approval);
                }
                if ($status) {
                    $query->where('status', $status);
                }
                $payload['metrics'][] = $this->metric($key, $label, $query->count());
            }
            $payload['metrics'][] = $this->metric(
                'approved_restock_requests',
                'Approved restock requests',
                DB::table('restock_requests')->where('status', 'Approved')->count(),
            );
            $payload['sections']['supplier_activity'] = DB::table('purchase_orders')
                ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
                ->select(
                    'suppliers.id',
                    'suppliers.name',
                    DB::raw('COUNT(purchase_orders.id) as purchase_orders'),
                    DB::raw('MAX(purchase_orders.order_date) as latest_order'),
                )
                ->groupBy('suppliers.id', 'suppliers.name')
                ->orderByDesc('purchase_orders')
                ->limit(8)
                ->get();
        }

        if ($user->hasAnyPermission('inventory.manage', 'reports.inventory.view')) {
            $payload['metrics'] = array_merge($payload['metrics'], [
                $this->metric('total_products', 'Total products', DB::table('products')->count()),
                $this->metric(
                    'low_stock',
                    'Low-stock products',
                    DB::table('products')
                        ->where('status', 'Active')
                        ->whereColumn('stock_quantity', '<=', 'reorder_level')
                        ->count(),
                ),
                $this->metric(
                    'out_of_stock',
                    'Out-of-stock products',
                    DB::table('products')->where('status', 'Active')->where('stock_quantity', 0)->count(),
                ),
                $this->metric(
                    'pending_restock',
                    'Pending restock requests',
                    DB::table('restock_requests')->where('status', 'Pending Approval')->count(),
                ),
                $this->metric(
                    'ready_for_receiving',
                    'POs ready for receiving',
                    DB::table('purchase_orders')
                        ->where('approval_status', 'Approved')
                        ->whereIn('status', ['Approved', 'Ordered', 'Partially Received'])
                        ->count(),
                ),
            ]);
            $payload['sections']['recent_inventory_movements'] = DB::table('inventory_movements')
                ->orderByDesc('id')
                ->limit(8)
                ->get([
                    'id',
                    'created_at',
                    'sku',
                    'movement_type',
                    'quantity',
                    'previous_stock',
                    'new_stock',
                ]);
        }

        if ($user->hasAnyPermission('employee.self')) {
            $payload['sections']['employee'] = $this->employeeSection($user);
        }

        return $payload;
    }

    private function employeeSection(User $user): array
    {
        if (! $user->employee_id) {
            return ['linked' => false];
        }
        $employee = DB::table('employees')->find($user->employee_id);
        if (! $employee) {
            return ['linked' => false];
        }

        $attendance = DB::table('attendance_logs')
            ->where('employee_id', $employee->id)
            ->selectRaw(
                'COUNT(*) as records, '
                ."SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present, "
                ."SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late, "
                ."SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent",
            )
            ->first();

        return [
            'linked' => true,
            'leave_balance' => (float) $employee->leave_balance,
            'attendance_summary' => [
                'records' => (int) $attendance->records,
                'present' => (int) $attendance->present,
                'late' => (int) $attendance->late,
                'absent' => (int) $attendance->absent,
            ],
            'recent_hr_requests' => DB::table('hr_requests')
                ->where('employee_id', $employee->id)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'created_at', 'request_type', 'status', 'reason']),
            'current_payroll' => DB::table('payroll')
                ->where('employee_id', $employee->id)
                ->orderByDesc('pay_period_end')
                ->first([
                    'pay_period_start',
                    'pay_period_end',
                    'net_pay',
                    'status',
                ]),
            'schedule_supported' => false,
        ];
    }

    private function salesSummary(
        string $from,
        string $to,
        ?string $cashier,
    ): array {
        $query = DB::table('sales_ledger')
            ->whereBetween('timestamp', [$from, $to])
            ->when($cashier, fn ($item) => $item->where('cashier_username', $cashier));
        $row = $query->selectRaw(
            'ROUND(COALESCE(SUM(total_price), 0), 2) as total, '
            .'COUNT(DISTINCT order_id) as transactions',
        )->first();
        $transactions = (int) $row->transactions;

        return [
            'total' => (float) $row->total,
            'transactions' => $transactions,
            'average' => $transactions > 0
                ? round((float) $row->total / $transactions, 2)
                : 0,
        ];
    }

    private function salesChart(CarbonImmutable $now, User $user): array
    {
        $from = $now->subDays(6)->startOfDay();
        $rows = DB::table('sales_ledger')
            ->where('timestamp', '>=', $from->format('Y-m-d H:i:s'))
            ->when(
                ! $user->hasAnyPermission('reports.sales.view'),
                fn ($query) => $query->where('cashier_username', $user->username),
            )
            ->selectRaw('date(timestamp) as day, ROUND(SUM(total_price), 2) as total')
            ->groupByRaw('date(timestamp)')
            ->pluck('total', 'day');
        $points = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $date = $from->addDays($offset);
            $points[] = [
                'label' => $date->format('M j'),
                'value' => (float) ($rows[$date->toDateString()] ?? 0),
            ];
        }

        return [
            'label' => 'Sales during the last seven days',
            'format' => 'money',
            'points' => $points,
            'summary' => 'Daily finalized sales totals for the last seven calendar days.',
        ];
    }

    private function financialSum(string $direction, string $from): float
    {
        return round((float) DB::table('financial_transactions')
            ->where('direction', $direction)
            ->where('created_at', '>=', $from)
            ->sum('amount'), 2);
    }

    private function metric(
        string $key,
        string $label,
        int|float $value,
        string $format = 'number',
    ): array {
        return compact('key', 'label', 'value', 'format');
    }

    private function systemHealth(): array
    {
        try {
            $database = DB::selectOne('PRAGMA integrity_check')->integrity_check;
            $foreignKeys = count(DB::select('PRAGMA foreign_key_check'));
        } catch (\Throwable) {
            $database = 'unavailable';
            $foreignKeys = -1;
        }

        return [
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'database_integrity' => $database,
            'foreign_key_violations' => $foreignKeys,
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
        ];
    }
}
