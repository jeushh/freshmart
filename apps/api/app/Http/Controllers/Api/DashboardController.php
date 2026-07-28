<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Models\{Employee,Product,Payroll,FinanceRequest};
class DashboardController extends Controller {public function __invoke(){return ['employees'=>Employee::where('status','active')->count(),'low_stock'=>Product::whereColumn('stock','<=','reorder_level')->count(),'pending_payroll'=>Payroll::whereIn('status',['draft','submitted'])->count(),'pending_finance_requests'=>FinanceRequest::where('status','pending')->count()];}}
