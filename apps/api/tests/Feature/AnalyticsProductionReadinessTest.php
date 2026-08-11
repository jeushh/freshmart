<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AnalyticsProductionReadinessTest extends TestCase
{
    public function test_each_report_requires_its_narrow_permission(): void
    {
        foreach (['sales', 'inventory', 'procurement', 'hr', 'payroll', 'finance'] as $type) {
            $user = $this->userWithPermissions(
                "report-{$type}",
                ["reports.{$type}.view"],
            );
            $this->actingAs($user);
            $this->getJson("/api/reports/{$type}")->assertOk()
                ->assertJsonPath('type', $type)
                ->assertJsonStructure([
                    'summary',
                    'columns',
                    'records' => ['data'],
                    'settings',
                    'notes',
                ]);

            $other = $type === 'sales' ? 'inventory' : 'sales';
            $this->getJson("/api/reports/{$other}")->assertForbidden()
                ->assertJsonPath('code', 'FORBIDDEN');
        }
    }

    public function test_report_export_requires_both_view_and_export_permissions(): void
    {
        $viewer = $this->userWithPermissions('sales-viewer', ['reports.sales.view']);
        $this->actingAs($viewer);
        $this->getJson('/api/reports/sales')->assertOk();
        $this->get('/api/reports/sales/export')->assertForbidden();

        $exporter = $this->userWithPermissions('export-only', ['reports.export']);
        $this->actingAs($exporter);
        $this->get('/api/reports/sales/export')->assertForbidden();

        $combined = $this->userWithPermissions(
            'sales-exporter',
            ['reports.sales.view', 'reports.export'],
        );
        $this->actingAs($combined);
        $this->get('/api/reports/sales/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-export-row-limit', '10000');
    }

    public function test_csv_export_neutralizes_spreadsheet_formulas(): void
    {
        $product = DB::table('products')->first();
        DB::table('sales_ledger')->insert([
            'order_id' => 'CSV-SAFETY',
            'item_sku' => $product->sku,
            'quantity_sold' => 1,
            'total_price' => 10,
            'payment_method' => 'Cash',
            'cashier_username' => '=HYPERLINK("https://example.test")',
            'timestamp' => now()->format('Y-m-d H:i:s'),
        ]);
        $this->actingAs($this->userWithPermissions(
            'safe-exporter',
            ['reports.sales.view', 'reports.export'],
        ));

        $content = $this->get('/api/reports/sales/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(
            '\'=HYPERLINK(""https://example.test"")',
            $content,
        );
    }

    public function test_each_authorized_report_streams_csv_without_sensitive_columns(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        foreach (['sales', 'inventory', 'procurement', 'hr', 'payroll', 'finance'] as $type) {
            $content = $this->get("/api/reports/{$type}/export")
                ->assertOk()
                ->assertHeader('x-export-row-limit', '10000')
                ->streamedContent();
            $this->assertNotSame('', trim($content));
            $this->assertStringNotContainsString('password_hash', $content);
            $this->assertStringNotContainsString('token', strtolower($content));
        }
    }

    public function test_checkout_records_tax_snapshot_and_reports_do_not_rewrite_history(): void
    {
        $this->setSetting('tax_rate', '12');
        $this->setSetting('tax_inclusive', '0');
        app(SystemSettingsService::class)->forget();
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        $this->actingAs($cashier);

        $response = $this->postJson('/api/workspace/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'Cash',
        ])->assertOk()
            ->assertJsonPath('total', 145.6)
            ->assertJsonPath('tax_total', 15.6)
            ->assertJsonPath('tax_rate', 12);

        $sale = DB::table('sales_ledger')
            ->where('order_id', $response->json('order_id'))
            ->first();
        $this->assertSame(12.0, (float) $sale->tax_rate);
        $this->assertSame(15.6, (float) $sale->tax_amount);
        $this->assertSame(130.0, (float) $sale->subtotal_amount);
        $this->assertSame(145.6, (float) $sale->total_price);
        $this->assertSame(0, (int) $sale->tax_inclusive);

        $this->setSetting('tax_rate', '5');
        app(SystemSettingsService::class)->forget();
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $report = $this->getJson('/api/reports/sales')
            ->assertOk()
            ->json('records.data');
        $historical = collect($report)->firstWhere('order_id', $response->json('order_id'));
        $this->assertSame(15.6, (float) $historical['tax_amount']);
        $this->assertSame(12.0, (float) $sale->tax_rate);
    }

    public function test_sales_summary_is_inclusive_of_date_boundaries_and_uses_stored_amounts(): void
    {
        $product = DB::table('products')->first();
        foreach ([
            ['BOUNDARY-ORDER', '2026-07-01 00:00:00', 100, 8, 2],
            ['BOUNDARY-ORDER', '2026-07-31 23:59:59', 50, 4, 1],
            ['OUTSIDE-ORDER', '2026-08-01 00:00:00', 900, 90, 1],
        ] as [$order, $timestamp, $total, $tax, $quantity]) {
            DB::table('sales_ledger')->insert([
                'order_id' => $order,
                'item_sku' => $product->sku,
                'quantity_sold' => $quantity,
                'unit_price' => $total / $quantity,
                'subtotal_amount' => $total - $tax,
                'tax_rate' => 8,
                'tax_amount' => $tax,
                'tax_inclusive' => 1,
                'discount_amount' => 0,
                'total_price' => $total,
                'payment_method' => 'Card',
                'cashier_username' => 'boundary-cashier',
                'timestamp' => $timestamp,
            ]);
        }
        DB::table('refunds')->insert([
            'order_id' => 'BOUNDARY-ORDER',
            'item_sku' => $product->sku,
            'quantity_refunded' => 1,
            'refund_amount' => 20,
            'created_at' => '2026-07-31 23:59:59',
        ]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson(
            '/api/reports/sales?from=2026-07-01&to=2026-07-31'
            .'&cashier=boundary-cashier&payment_method=Card',
        )->assertOk()
            ->assertJsonPath('summary.gross_sales', 150)
            ->assertJsonPath('summary.refunds', 20)
            ->assertJsonPath('summary.net_sales', 130)
            ->assertJsonPath('summary.transaction_count', 1)
            ->assertJsonPath('summary.average_transaction', 150)
            ->assertJsonPath('summary.tax_total', 12)
            ->assertJsonPath('summary.quantity_sold', 3)
            ->assertJsonCount(2, 'records.data');
    }

    public function test_inventory_report_filters_and_exports_date_ranged_movements(): void
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        DB::table('inventory_movements')->insert([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'movement_type' => 'Adjustment',
            'quantity' => 6,
            'previous_stock' => $product->stock_quantity,
            'new_stock' => $product->stock_quantity + 6,
            'created_at' => '2026-07-15 12:00:00',
        ]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson(
            '/api/reports/inventory?from=2026-07-01&to=2026-07-31'
            .'&movement_type=Adjustment&category=Fruits',
        )->assertOk()
            ->assertJsonPath('summary.stock_in', 6)
            ->assertJsonPath('summary.stock_out', 0)
            ->assertJsonPath('records.data.0.movement_count', 1)
            ->assertJsonPath('records.data.0.stock_in', 6);
    }

    public function test_procurement_report_preserves_delivery_acceptance_and_fulfillment_semantics(): void
    {
        $product = DB::table('products')->first();
        $supplier = DB::table('suppliers')->first();
        $orderId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-REPORT-SEMANTICS',
            'supplier_id' => $supplier->id,
            'order_date' => '2026-07-10 09:00:00',
            'status' => 'Partially Received',
            'approval_status' => 'Approved',
        ]);
        $itemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $orderId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 7,
            'unit_cost' => 10,
        ]);
        $receivingId = DB::table('stock_receivings')->insertGetId([
            'purchase_order_id' => $orderId,
            'received_by' => 'inventory',
            'receiving_date' => '2026-07-12 10:00:00',
        ]);
        DB::table('stock_receiving_items')->insert([
            'stock_receiving_id' => $receivingId,
            'purchase_order_item_id' => $itemId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'received_quantity' => 10,
            'damaged_quantity' => 0,
            'rejected_quantity' => 3,
            'unit_cost' => 10,
        ]);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson(
            '/api/reports/procurement?from=2026-07-01&to=2026-07-31'
            .'&supplier_id='.$supplier->id,
        )->assertOk()
            ->assertJsonPath('summary.ordered', 10)
            ->assertJsonPath('summary.delivered', 10)
            ->assertJsonPath('summary.accepted', 7)
            ->assertJsonPath('summary.fulfilled', 7)
            ->assertJsonPath('summary.rejected', 3)
            ->assertJsonPath('summary.outstanding', 3)
            ->assertJsonPath('summary.purchase_cost', 70)
            ->assertJsonPath('records.data.0.receiving_events', 1)
            ->assertJsonPath('records.data.0.last_receiving_at', '2026-07-12 10:00:00');
    }

    public function test_report_records_are_paginated_and_empty_exports_keep_safe_headers(): void
    {
        $product = DB::table('products')->first();
        foreach (range(1, 26) as $number) {
            DB::table('sales_ledger')->insert([
                'order_id' => "PAGED-{$number}",
                'item_sku' => $product->sku,
                'quantity_sold' => 1,
                'total_price' => 1,
                'payment_method' => 'Cash',
                'cashier_username' => 'pagination-test',
                'timestamp' => '2026-07-15 12:00:00',
            ]);
        }
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson(
            '/api/reports/sales?from=2026-07-01&to=2026-07-31'
            .'&cashier=pagination-test&per_page=10&page=2',
        )->assertOk()
            ->assertJsonPath('records.current_page', 2)
            ->assertJsonPath('records.last_page', 3)
            ->assertJsonPath('records.total', 26)
            ->assertJsonCount(10, 'records.data');

        $content = $this->get(
            '/api/reports/sales/export?from=2099-01-01&to=2099-01-01',
        )->assertOk()->streamedContent();
        $this->assertStringContainsString('Date,Order,Cashier', $content);
        $this->assertStringNotContainsString('password', strtolower($content));
        $this->assertStringNotContainsString('token', strtolower($content));
    }

    public function test_dashboards_are_role_aware_and_employee_data_is_self_scoped(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.0.key', 'today_sales')
            ->assertJsonMissingPath('sections.attendance_today')
            ->assertJsonMissingPath('sections.system_health');

        $this->actingAs(User::where('username', 'hr')->firstOrFail());
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'sections' => [
                    'attendance_today' => ['records', 'present', 'late', 'absent'],
                    'low_leave_balances',
                ],
            ])
            ->assertJsonMissingPath('sections.recent_financial_transactions');

        $this->actingAs(User::where('username', 'finance')->firstOrFail());
        $financeDashboard = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonMissingPath('sections.attendance_today')
            ->json();
        $this->assertNotContains(
            'pending_hr_requests',
            array_column($financeDashboard['metrics'], 'key'),
        );

        $this->actingAs(User::where('username', 'employee')->firstOrFail());
        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('sections.employee.linked', true)
            ->assertJsonMissingPath('sections.low_leave_balances')
            ->assertJsonMissingPath('sections.recent_sales');
    }

    public function test_dashboard_handles_empty_authorized_data_without_widening_access(): void
    {
        DB::table('refunds')->delete();
        DB::table('sales_ledger')->delete();
        $this->actingAs($this->userWithPermissions(
            'empty-sales-dashboard',
            ['reports.sales.view'],
        ));

        $response = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('metrics.0.value', 0)
            ->assertJsonCount(0, 'sections.recent_sales')
            ->assertJsonMissingPath('sections.attendance_today')
            ->json();
        $this->assertSame(
            array_fill(0, 7, 0),
            array_column($response['charts']['sales_last_7_days']['points'], 'value'),
        );
    }

    public function test_supplier_settlement_is_separate_from_expenses_in_reports_and_dashboard(): void
    {
        $supplier = DB::table('suppliers')->first();
        $payableId = DB::table('accounts_payable')->insertGetId([
            'supplier_id' => $supplier->id,
            'purchase_order_id' => null,
            'supplier_invoice_id' => null,
            'invoice_number' => 'REPORT-PAYMENT-001',
            'total_amount' => 200,
            'amount_paid' => 0,
            'due_date' => now()->addWeek()->toDateString(),
            'status' => 'Unpaid',
        ]);
        DB::table('financial_transactions')->insert([
            'transaction_type' => 'Purchase',
            'amount' => 200,
            'direction' => 'Out',
            'reference_type' => 'test_purchase',
            'reference_id' => (string) $payableId,
            'description' => 'Recognized procurement cost',
            'category' => 'Inventory Purchase',
            'created_by' => 'test',
        ]);

        $this->actingAs(User::where('username', 'finance')->firstOrFail());
        $beforeReport = $this->getJson('/api/reports/finance')->assertOk()->json();
        $beforeDashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->postJson("/api/accounts-payable/{$payableId}/payments", [
            'amount' => 75,
            'payment_method' => 'Bank Transfer',
            'reference_number' => 'REPORT-REF-001',
            'payment_date' => now()->toDateString(),
            'notes' => 'Reporting separation test',
            'idempotency_key' => 'report-separation-payment',
        ])->assertCreated();

        $afterReport = $this->getJson('/api/reports/finance')->assertOk()->json();
        $afterDashboard = $this->getJson('/api/dashboard')->assertOk()->json();

        $this->assertSame(
            (float) $beforeReport['summary']['expenses'],
            (float) $afterReport['summary']['expenses'],
        );
        $this->assertSame(
            (float) $beforeReport['summary']['net_movement'],
            (float) $afterReport['summary']['net_movement'],
        );
        $this->assertSame(75.0, (float) $afterReport['summary']['supplier_payments']);
        $this->assertSame(
            (float) $beforeReport['summary']['accounts_payable'] - 75,
            (float) $afterReport['summary']['accounts_payable'],
        );
        $paymentRecord = collect($afterReport['records']['data'])
            ->firstWhere('transaction_type', 'Supplier Payment');
        $this->assertNotNull($paymentRecord);
        $this->assertSame(75.0, (float) $paymentRecord['amount']);
        $this->assertStringContainsString(
            'prevent double-counting',
            implode(' ', $afterReport['notes']),
        );

        $beforeMetrics = collect($beforeDashboard['metrics'])->keyBy('key');
        $afterMetrics = collect($afterDashboard['metrics'])->keyBy('key');
        $this->assertSame(
            (float) $beforeMetrics['month_expenses']['value'],
            (float) $afterMetrics['month_expenses']['value'],
        );
        $this->assertSame(
            (float) $beforeMetrics['net_movement']['value'],
            (float) $afterMetrics['net_movement']['value'],
        );
        $this->assertSame(
            (float) $beforeMetrics['month_supplier_payments']['value'] + 75,
            (float) $afterMetrics['month_supplier_payments']['value'],
        );
        $this->assertSame(
            (float) $beforeMetrics['accounts_payable']['value'] - 75,
            (float) $afterMetrics['accounts_payable']['value'],
        );
        $this->assertNotNull(collect($afterDashboard['sections']['recent_financial_transactions'])
            ->firstWhere('transaction_type', 'Supplier Payment'));

        $viewer = $this->userWithPermissions('finance-report-viewer', ['reports.finance.view']);
        $this->actingAs($viewer);
        $this->getJson('/api/reports/finance')->assertOk();
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('metrics.0.key', 'today_revenue');
        $this->postJson("/api/accounts-payable/{$payableId}/payments", [
            'amount' => 1,
            'payment_method' => 'Cash',
            'payment_date' => now()->toDateString(),
            'idempotency_key' => 'report-viewer-forbidden',
        ])->assertForbidden();
    }

    public function test_invalid_filters_return_standard_errors_with_correlation_id(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->withHeader('X-Request-ID', 'test-request-1234')
            ->getJson('/api/reports/sales?from=2026-07-30&to=2026-07-01')
            ->assertUnprocessable()
            ->assertHeader('X-Request-ID', 'test-request-1234')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('request_id', 'test-request-1234')
            ->assertJsonStructure(['message', 'code', 'errors', 'request_id']);
    }

    public function test_date_range_limits_and_api_error_shapes_are_enforced(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $this->getJson('/api/reports/sales?from=2024-01-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('to')
            ->assertJsonMissing(['trace', 'exception', 'file', 'line']);

        $this->getJson('/api/records-that-do-not-exist')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertJsonStructure(['message', 'code', 'errors', 'request_id'])
            ->assertJsonMissing(['trace', 'exception', 'file', 'line']);
    }

    public function test_employee_cannot_access_organization_payroll_report(): void
    {
        $this->actingAs(User::where('username', 'employee')->firstOrFail());
        $this->getJson('/api/reports/payroll')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_public_settings_are_safe_and_available_to_authenticated_users(): void
    {
        $this->actingAs(User::where('username', 'employee')->firstOrFail());
        $this->getJson('/api/settings/public')
            ->assertOk()
            ->assertJsonPath('settings.currency_code', 'PHP')
            ->assertJsonPath('settings.timezone', 'Asia/Manila')
            ->assertJsonMissing(['password', 'secret', 'key']);
    }

    public function test_settings_updates_invalidate_the_safe_settings_cache(): void
    {
        $service = app(SystemSettingsService::class);
        $this->assertSame('PHP', $service->all()['currency_code']);
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->putJson('/api/settings', [
            'settings' => [
                'currency_code' => 'USD',
                'currency_symbol' => '$',
                'currency_locale' => 'en-US',
            ],
        ])->assertOk();

        $this->assertSame('USD', $service->all()['currency_code']);
        $this->assertSame('$', $service->all()['currency_symbol']);
        $this->assertSame(
            'USD',
            DB::table('system_settings')->where('setting_key', 'currency')->value('setting_value'),
        );
    }

    private function setSetting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()->format('Y-m-d H:i:s')],
        );
    }

    private function userWithPermissions(string $username, array $permissions): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => "Role {$username}",
            'description' => 'Test role',
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'landing_page' => 'dashboard',
        ]);
        $userId = DB::table('admin_users')->insertGetId([
            'username' => $username,
            'password_hash' => Hash::make('test-password'),
            'full_name' => "User {$username}",
            'role_id' => $roleId,
            'status' => 'Active',
        ]);

        return User::findOrFail($userId);
    }
}
