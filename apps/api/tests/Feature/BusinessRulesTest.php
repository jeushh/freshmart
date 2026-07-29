<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessRulesTest extends TestCase
{
    public function test_sanctum_session_login_and_logout_flow(): void
    {
        $userId = DB::table('admin_users')->insertGetId([
            'username' => 'login-flow-test',
            'password_hash' => Hash::make('testing-password'),
            'full_name' => 'Login Flow Test',
            'role_id' => DB::table('roles')->where('name', 'Cashier')->value('id'),
            'status' => 'Active',
        ]);

        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Referer' => 'http://127.0.0.1:5173/',
        ]);
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->postJson('/api/login', [
            'username' => 'login-flow-test',
            'password' => 'testing-password',
        ])->assertOk()
            ->assertJsonPath('user.id', $userId)
            ->assertJsonMissingPath('user.password_hash');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('user.username', 'login-flow-test');
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public function test_employee_view_permission_does_not_grant_write_access(): void
    {
        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        $this->postJson('/api/employees', [
            'employee_code' => 'EMP-TEST',
            'name' => 'Unauthorized Write',
            'status' => 'active',
            'pay_type' => 'monthly',
        ])->assertForbidden();
    }

    public function test_restock_approval_permission_does_not_grant_product_edit_access(): void
    {
        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $product = DB::table('products')->first();

        $this->putJson("/api/workspace/products/{$product->id}", [
            'sku' => $product->sku,
            'name' => 'Unauthorized Price Change',
            'category' => $product->category,
            'price' => $product->price,
            'cost_price' => $product->cost_price,
            'stock_quantity' => $product->stock_quantity,
            'reorder_level' => $product->reorder_level,
            'unit' => $product->unit,
            'supplier_id' => $product->supplier_id,
            'status' => $product->status,
        ])->assertForbidden();
    }

    public function test_dashboard_uses_real_schema_and_hides_unauthorized_metrics(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['employees', 'low_stock', 'pending_payroll', 'pending_finance_requests']);

        $this->actingAs(User::where('username', 'employee')->firstOrFail());
        $this->getJson('/api/dashboard')->assertOk()->assertExactJson([]);
    }

    public function test_employee_update_preserves_hire_date_when_it_is_not_submitted(): void
    {
        $this->actingAs(User::where('username', 'hr')->firstOrFail());
        $employee = DB::table('employees')->where('employee_no', 'EMP-0001')->first();

        $this->putJson("/api/employees/{$employee->id}", [
            'employee_code' => $employee->employee_no,
            'name' => $employee->full_name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'position' => $employee->position,
            'department' => $employee->department,
            'status' => 'active',
            'pay_type' => 'monthly',
            'basic_salary' => $employee->basic_salary,
            'hourly_rate' => $employee->hourly_rate,
            'leave_balance' => $employee->leave_balance,
        ])->assertOk();

        $this->assertSame(
            $employee->hire_date,
            DB::table('employees')->where('id', $employee->id)->value('hire_date'),
        );
    }

    public function test_payroll_requires_valid_transitions_and_posts_one_ledger_entry(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $payrollId = DB::table('payroll')->insertGetId([
            'employee_id' => 1,
            'pay_period_start' => '2030-01-01',
            'pay_period_end' => '2030-01-15',
            'net_pay' => 1000,
            'status' => 'Draft',
        ]);

        $this->postJson("/api/payroll/{$payrollId}/review", ['decision' => 'Paid'])->assertStatus(409);
        $this->postJson("/api/payroll/{$payrollId}/review", ['decision' => 'Approved'])->assertOk();
        $this->postJson("/api/payroll/{$payrollId}/review", ['decision' => 'Paid'])->assertOk();
        $this->postJson("/api/payroll/{$payrollId}/review", ['decision' => 'Paid'])->assertStatus(409);

        $this->assertSame(1, DB::table('financial_transactions')
            ->where('reference_type', 'payroll')
            ->where('reference_id', (string) $payrollId)
            ->count());
    }

    public function test_finance_payment_requires_approval_and_posts_one_ledger_entry(): void
    {
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
        $requestId = DB::table('finance_requests')->insertGetId([
            'employee_id' => 1,
            'request_type' => 'Reimbursement',
            'amount' => 125.50,
            'category' => 'Reimbursement',
            'description' => 'Test reimbursement',
            'status' => 'Pending',
        ]);

        $this->postJson("/api/workspace/finance/{$requestId}/review", ['decision' => 'Paid'])->assertStatus(409);
        $this->postJson("/api/workspace/finance/{$requestId}/review", ['decision' => 'Approved'])->assertOk();
        $this->postJson("/api/workspace/finance/{$requestId}/review", ['decision' => 'Paid'])->assertOk();
        $this->postJson("/api/workspace/finance/{$requestId}/review", ['decision' => 'Paid'])->assertStatus(409);

        $this->assertSame(1, DB::table('financial_transactions')
            ->where('reference_type', 'finance_request')
            ->where('reference_id', (string) $requestId)
            ->count());
    }

    public function test_self_service_rejects_cross_kind_request_types_and_zero_finance_amounts(): void
    {
        $this->actingAs(User::where('username', 'employee')->firstOrFail());

        $this->postJson('/api/workspace/self/request', [
            'kind' => 'finance',
            'request_type' => 'Leave',
            'reason' => 'Invalid combination',
            'amount' => 100,
        ])->assertUnprocessable();

        $this->postJson('/api/workspace/self/request', [
            'kind' => 'finance',
            'request_type' => 'Reimbursement',
            'reason' => 'Invalid amount',
            'amount' => 0,
        ])->assertUnprocessable();
    }

    public function test_pos_rejects_inactive_and_duplicate_products_without_changing_stock(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $product = DB::table('products')->where('stock_quantity', '>', 1)->first();
        DB::table('products')->where('id', $product->id)->update(['status' => 'Inactive']);

        $this->postJson('/api/workspace/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'Cash',
        ])->assertUnprocessable();
        $this->assertSame($product->stock_quantity, DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        DB::table('products')->where('id', $product->id)->update(['status' => 'Active']);
        $this->postJson('/api/workspace/pos/checkout', [
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'payment_method' => 'Cash',
        ])->assertUnprocessable();
    }

    public function test_user_write_response_never_exposes_password_hash(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $roleId = DB::table('roles')->where('name', 'Cashier')->value('id');

        $response = $this->postJson('/api/workspace/users', [
            'username' => 'safe-response-test',
            'full_name' => 'Safe Response',
            'role_id' => $roleId,
            'status' => 'Active',
            'password' => 'correct-horse-battery-staple',
        ])->assertCreated();

        $response->assertJsonMissingPath('password_hash');
    }

    public function test_employee_self_service_account_requires_a_unique_employee_link(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $roleId = DB::table('roles')->where('name', 'Employee')->value('id');

        $this->postJson('/api/workspace/users', [
            'username' => 'unlinked-employee-test',
            'full_name' => 'Unlinked Employee',
            'role_id' => $roleId,
            'status' => 'Active',
            'password' => 'correct-horse-battery-staple',
        ])->assertUnprocessable();

        $this->postJson('/api/workspace/users', [
            'username' => 'duplicate-link-test',
            'full_name' => 'Duplicate Link',
            'role_id' => $roleId,
            'employee_id' => 1,
            'status' => 'Active',
            'password' => 'correct-horse-battery-staple',
        ])->assertUnprocessable();
    }
}
