<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\PayrollController;
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

    Route::get('/payroll', [PayrollController::class, 'index'])->middleware('permission:payroll.manage');
    Route::post('/payroll', [PayrollController::class, 'store'])->middleware('permission:payroll.manage');
    Route::post('/payroll/{payroll}/review', [PayrollController::class, 'review'])->middleware('permission:payroll.manage');

    Route::get('/workspace/inventory', [BusinessController::class, 'inventory'])->middleware('permission:inventory.manage|restock.approve');
    Route::post('/workspace/products', [BusinessController::class, 'saveProduct'])->middleware('permission:inventory.manage');
    Route::put('/workspace/products/{id}', [BusinessController::class, 'saveProduct'])->middleware('permission:inventory.manage');
    Route::post('/workspace/products/{id}/adjust', [BusinessController::class, 'adjustStock'])->middleware('permission:inventory.manage');

    Route::get('/workspace/finance/requests', [BusinessController::class, 'financeRequests'])->middleware('permission:finance.requests.view');
    Route::post('/workspace/finance/requests/{id}/review', [BusinessController::class, 'reviewFinance'])->middleware('permission:finance.requests.approve');
    Route::get('/workspace/finance/overview', [BusinessController::class, 'financeOverview'])->middleware('permission:finance.manage');

    Route::get('/workspace/pos', [BusinessController::class, 'pos'])->middleware('permission:pos.access');
    Route::post('/workspace/pos/checkout', [BusinessController::class, 'checkout'])->middleware('permission:pos.access');

    Route::get('/workspace/admin', [BusinessController::class, 'admin'])
        ->middleware('permission:system.users.manage|system.roles.manage|system.audit.view|system.settings.manage');
    Route::post('/workspace/users', [BusinessController::class, 'saveUser'])->middleware('permission:system.users.manage');
    Route::put('/workspace/users/{id}', [BusinessController::class, 'saveUser'])->middleware('permission:system.users.manage');

    Route::get('/workspace/self', [BusinessController::class, 'self'])->middleware('permission:employee.self');
    Route::post('/workspace/self/request', [BusinessController::class, 'submitSelf'])->middleware('permission:employee.self');
});
