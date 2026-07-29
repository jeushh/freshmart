<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): array
    {
        $user = $request->user()->loadMissing('role');
        $metrics = [];

        if ($user->hasAnyPermission('hr.employees.view', 'hr.employees.edit')) {
            $metrics['employees'] = DB::table('employees')->where('employment_status', 'Active')->count();
        }
        if ($user->hasAnyPermission('inventory.manage', 'restock.approve')) {
            $metrics['low_stock'] = DB::table('products')
                ->where('status', 'Active')
                ->whereColumn('stock_quantity', '<=', 'reorder_level')
                ->count();
        }
        if ($user->hasAnyPermission('payroll.manage')) {
            $metrics['pending_payroll'] = DB::table('payroll')
                ->whereIn('status', ['Draft', 'Pending Approval'])
                ->count();
        }
        if ($user->hasAnyPermission('finance.requests.view', 'finance.requests.approve', 'finance.manage')) {
            $metrics['pending_finance_requests'] = DB::table('finance_requests')
                ->where('status', 'Pending')
                ->count();
        }

        return $metrics;
    }
}
