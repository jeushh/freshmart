<?php

use App\Http\Controllers\Api\AccountsPayableController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FinancePurchaseOrderLookupController;
use App\Http\Controllers\Api\HrRequestController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RestockRequestController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StockReceivingController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SystemSettingController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', DashboardController::class);

    Route::get('/employees', [EmployeeController::class, 'index'])->middleware('permission:hr.employees.view');
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->middleware('permission:hr.employees.view');
    Route::post('/employees', [EmployeeController::class, 'store'])->middleware('permission:hr.employees.edit');
    Route::match(['put', 'patch'], '/employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.edit');
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.employees.edit');

    Route::get('/attendance', [AttendanceController::class, 'index'])->middleware('permission:hr.attendance.view');
    Route::post('/attendance', [AttendanceController::class, 'store'])->middleware('permission:hr.attendance.edit');

    Route::get('/hr/requests', [HrRequestController::class, 'index'])->middleware('permission:hr.requests.view');
    Route::post('/hr/requests/{hrRequest}/review', [HrRequestController::class, 'review'])->middleware('permission:hr.requests.approve');

    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('permission:payroll.manage');
    Route::post('/payroll', [PayrollController::class, 'store'])->middleware('permission:payroll.manage');
    Route::post('/payroll/{payroll}/review', [PayrollController::class, 'review'])->middleware('permission:payroll.manage');

    Route::get('/workspace/inventory', [BusinessController::class, 'inventory'])->middleware('permission:inventory.manage|restock.approve');
    Route::post('/workspace/products', [BusinessController::class, 'saveProduct'])->middleware('permission:inventory.manage');
    Route::put('/workspace/products/{id}', [BusinessController::class, 'saveProduct'])->middleware('permission:inventory.manage');
    Route::post('/workspace/products/{id}/adjust', [BusinessController::class, 'adjustStock'])->middleware('permission:inventory.manage');

    Route::get('/restock-requests', [RestockRequestController::class, 'index'])->middleware('permission:restock.request|restock.approve');
    Route::post('/restock-requests', [RestockRequestController::class, 'store'])->middleware('permission:restock.request');
    Route::post('/restock-requests/{restockRequest}/review', [RestockRequestController::class, 'review'])->middleware('permission:restock.approve');

    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:procurement.purchase_orders.view');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:procurement.purchase_orders.view');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:procurement.purchase_orders.manage');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:procurement.purchase_orders.manage');
    Route::post('/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:procurement.purchase_orders.manage');
    Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send'])->middleware('permission:procurement.purchase_orders.manage');
    Route::post('/purchase-orders/{purchaseOrder}/supplier-response', [PurchaseOrderController::class, 'supplierResponse'])->middleware('permission:procurement.purchase_orders.manage');
    Route::post('/purchase-orders/{purchaseOrder}/review', [PurchaseOrderController::class, 'review'])->middleware('permission:procurement.purchase_orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:procurement.purchase_orders.manage|procurement.purchase_orders.approve');
    Route::post('/purchase-orders/{purchaseOrder}/receive', [StockReceivingController::class, 'store'])
        ->middleware('permission:procurement.stock.receive');

    Route::get('/workspace/finance/requests', [BusinessController::class, 'financeRequests'])->middleware('permission:finance.requests.view');
    Route::post('/workspace/finance/requests/{id}/review', [BusinessController::class, 'reviewFinance'])->middleware('permission:finance.requests.approve');
    Route::get('/workspace/finance/overview', [BusinessController::class, 'financeOverview'])->middleware('permission:finance.manage');

    Route::get('/workspace/pos', [BusinessController::class, 'pos'])->middleware('permission:pos.access');
    Route::post('/workspace/pos/checkout', [BusinessController::class, 'checkout'])->middleware('permission:pos.access');
    Route::post('/workspace/pos/refunds', [RefundController::class, 'store'])
        ->middleware('permission:pos.refund');

    Route::get('/workspace/admin', [BusinessController::class, 'admin'])
        ->middleware('permission:system.users.manage|system.roles.manage|system.audit.view|system.settings.manage');
    Route::post('/workspace/users', [BusinessController::class, 'saveUser'])->middleware('permission:system.users.manage');
    Route::put('/workspace/users/{id}', [BusinessController::class, 'saveUser'])->middleware('permission:system.users.manage');

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:system.roles.manage');
    Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:system.roles.manage');
    Route::put('/roles/{role}', [RoleController::class, 'update'])->middleware('permission:system.roles.manage');

    Route::get('/settings', [SystemSettingController::class, 'index'])->middleware('permission:system.settings.manage');
    Route::get('/settings/public', [SystemSettingController::class, 'publicSettings']);
    Route::put('/settings', [SystemSettingController::class, 'update'])->middleware('permission:system.settings.manage');

    foreach ([
        'sales' => 'reports.sales.view',
        'inventory' => 'reports.inventory.view',
        'procurement' => 'reports.procurement.view',
        'hr' => 'reports.hr.view',
        'payroll' => 'reports.payroll.view',
        'finance' => 'reports.finance.view',
    ] as $report => $permission) {
        Route::get("/reports/{$report}", [ReportController::class, 'show'])
            ->defaults('report', $report)
            ->middleware("permission:{$permission}");
        Route::get("/reports/{$report}/export", [ReportController::class, 'export'])
            ->defaults('report', $report)
            ->middleware("permission:{$permission}");
    }

    Route::get('/workspace/self', [BusinessController::class, 'self'])->middleware('permission:employee.self');
    Route::post('/workspace/self/request', [BusinessController::class, 'submitSelf'])->middleware('permission:employee.self');

    // Finance AP / Supplier Invoice routes (finance.manage only)
    Route::middleware('permission:finance.manage')->group(function () {
        Route::get('/finance/purchase-orders', [FinancePurchaseOrderLookupController::class, 'index']);
        Route::get('/finance/purchase-orders/{purchaseOrder}', [FinancePurchaseOrderLookupController::class, 'show']);

        Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index']);
        Route::get('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'show']);
        Route::post('/purchase-orders/{purchaseOrder}/invoices', [SupplierInvoiceController::class, 'store']);
        Route::put('/supplier-invoices/{id}', [SupplierInvoiceController::class, 'update']);
        Route::post('/supplier-invoices/{id}/register', [SupplierInvoiceController::class, 'register']);
        Route::post('/supplier-invoices/{id}/approve', [SupplierInvoiceController::class, 'approve']);
        Route::post('/supplier-invoices/{id}/dispute', [SupplierInvoiceController::class, 'dispute']);
        Route::post('/supplier-invoices/{id}/resolve-dispute', [SupplierInvoiceController::class, 'resolveDispute']);
        Route::post('/supplier-invoices/{id}/void', [SupplierInvoiceController::class, 'void']);

        Route::get('/accounts-payable', [AccountsPayableController::class, 'index']);
        Route::get('/accounts-payable/{id}', [AccountsPayableController::class, 'show']);
    });
});
