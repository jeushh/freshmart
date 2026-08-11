<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountsPayableReadModelTest extends TestCase
{
    /**
     * Create a historical/legacy AP row:
     * supplier_status=NULL and supplier_invoice_id=NULL.
     */
    private function createLegacyPayable(): array
    {
        $product = DB::table('products')
            ->where('sku', 'FRU-001')
            ->first();

        $this->assertNotNull($product);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-AP-LEGACY-'.uniqid(),
            'supplier_id' => $product->supplier_id,
            'status' => 'Approved',
            'approval_status' => 'Approved',
            'supplier_status' => null,
            'quantity_ordered' => 10,
            'created_by' => 'legacy',
        ]);

        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 10.00,
        ]);

        $dueDate = now()->subDays(3)->format('Y-m-d');

        $apId = DB::table('accounts_payable')->insertGetId([
            'supplier_id' => $product->supplier_id,
            'purchase_order_id' => $poId,
            'invoice_number' => 'LEGACY-READ-001',
            'supplier_invoice_id' => null,
            'total_amount' => 100.00,
            'amount_paid' => 25.00,
            'due_date' => $dueDate,
            'status' => 'Partially Paid',
        ]);

        return [
            'ap_id' => $apId,
            'po_id' => $poId,
            'po_number' => DB::table('purchase_orders')
                ->where('id', $poId)
                ->value('po_number'),
            'supplier_id' => (int) $product->supplier_id,
            'supplier_name' => DB::table('suppliers')
                ->where('id', $product->supplier_id)
                ->value('name'),
            'due_date' => $dueDate,
        ];
    }

    /**
     * Create an actual structured AP using the tracked PO +
     * supplier invoice approval workflow.
     */
    private function createStructuredPayable(): array
    {
        $product = DB::table('products')
            ->where('sku', 'FRU-001')
            ->first();

        $this->assertNotNull($product);

        // Inventory creates/submits PO.
        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_cost' => 12.50,
            ]],
        ])->assertCreated();

        $poId = (int) $response->json('order.id');

        $this->postJson(
            "/api/purchase-orders/{$poId}/submit"
        )->assertOk();

        // Operations approves PO.
        $this->actingAs(
            User::where('username', 'operations')->firstOrFail()
        );

        $this->postJson(
            "/api/purchase-orders/{$poId}/review",
            ['decision' => 'Approved']
        )->assertOk();

        // Inventory sends to supplier and records acceptance.
        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        $this->postJson(
            "/api/purchase-orders/{$poId}/send"
        )->assertOk();

        $this->postJson(
            "/api/purchase-orders/{$poId}/supplier-response",
            ['response' => 'Accepted']
        )->assertOk();

        $poItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $poId)
            ->first();

        $this->assertNotNull($poItem);

        // Receive all 4 accepted units so invoice can be approved.
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 4,
            ]],
        ])->assertCreated();

        // Tracked receiving itself must not create AP.
        $this->assertSame(
            0,
            DB::table('accounts_payable')
                ->where('purchase_order_id', $poId)
                ->count()
        );

        // Finance creates/registers/approves structured invoice.
        $this->actingAs(
            User::where('username', 'finance')->firstOrFail()
        );

        $dueDate = now()->addDays(30)->format('Y-m-d');

        $invoiceResponse = $this->postJson(
            "/api/purchase-orders/{$poId}/invoices",
            [
                'invoice_number' => 'STRUCT-READ-001',
                'due_date' => $dueDate,
                'items' => [[
                    'purchase_order_item_id' => $poItem->id,
                    'invoiced_quantity' => 4,
                    'unit_cost' => 12.50,
                ]],
            ]
        )->assertCreated();

        $invoiceId = (int) $invoiceResponse->json('invoice.id');

        $this->postJson(
            "/api/supplier-invoices/{$invoiceId}/register"
        )->assertOk();

        $this->postJson(
            "/api/supplier-invoices/{$invoiceId}/approve"
        )->assertOk();

        $ap = DB::table('accounts_payable')
            ->where('supplier_invoice_id', $invoiceId)
            ->first();

        $this->assertNotNull($ap);

        return [
            'ap_id' => (int) $ap->id,
            'invoice_id' => $invoiceId,
            'po_id' => $poId,
            'po_number' => DB::table('purchase_orders')
                ->where('id', $poId)
                ->value('po_number'),
            'supplier_id' => (int) $product->supplier_id,
            'supplier_name' => DB::table('suppliers')
                ->where('id', $product->supplier_id)
                ->value('name'),
            'due_date' => $dueDate,
        ];
    }

    public function test_finance_manager_can_read_legacy_and_structured_payables(): void
    {
        $legacy = $this->createLegacyPayable();
        $structured = $this->createStructuredPayable();

        $this->actingAs(
            User::where('username', 'finance')->firstOrFail()
        );

        $response = $this->getJson('/api/accounts-payable')
            ->assertOk()
            ->assertJsonStructure([
                'payables' => [
                    'data' => [[
                        'id',
                        'supplier_id',
                        'supplier_name',
                        'purchase_order_id',
                        'po_number',
                        'invoice_number',
                        'supplier_invoice_id',
                        'total_amount',
                        'amount_paid',
                        'due_date',
                        'status',
                        'created_at',
                        'outstanding_balance',
                        'source',
                        'overdue',
                    ]],
                ],
            ]);

        $rows = collect($response->json('payables.data'));

        $legacyRow = $rows->first(
            fn (array $row) => (int) $row['id'] === $legacy['ap_id']
        );

        $structuredRow = $rows->first(
            fn (array $row) => (int) $row['id'] === $structured['ap_id']
        );

        $this->assertNotNull($legacyRow);
        $this->assertNotNull($structuredRow);

        // Legacy row
        $this->assertSame(
            $legacy['supplier_id'],
            (int) $legacyRow['supplier_id']
        );
        $this->assertSame(
            $legacy['supplier_name'],
            $legacyRow['supplier_name']
        );
        $this->assertSame(
            $legacy['po_id'],
            (int) $legacyRow['purchase_order_id']
        );
        $this->assertSame(
            $legacy['po_number'],
            $legacyRow['po_number']
        );
        $this->assertSame(
            'LEGACY-READ-001',
            $legacyRow['invoice_number']
        );
        $this->assertNull($legacyRow['supplier_invoice_id']);
        $this->assertSame(100.0, (float) $legacyRow['total_amount']);
        $this->assertSame(25.0, (float) $legacyRow['amount_paid']);
        $this->assertSame(
            75.0,
            (float) $legacyRow['outstanding_balance']
        );
        $this->assertSame(
            $legacy['due_date'],
            $legacyRow['due_date']
        );
        $this->assertSame(
            'Partially Paid',
            $legacyRow['status']
        );
        $this->assertSame('legacy', $legacyRow['source']);
        $this->assertTrue((bool) $legacyRow['overdue']);

        // Structured row
        $this->assertSame(
            $structured['supplier_id'],
            (int) $structuredRow['supplier_id']
        );
        $this->assertSame(
            $structured['supplier_name'],
            $structuredRow['supplier_name']
        );
        $this->assertSame(
            $structured['po_id'],
            (int) $structuredRow['purchase_order_id']
        );
        $this->assertSame(
            $structured['po_number'],
            $structuredRow['po_number']
        );
        $this->assertSame(
            'STRUCT-READ-001',
            $structuredRow['invoice_number']
        );
        $this->assertSame(
            $structured['invoice_id'],
            (int) $structuredRow['supplier_invoice_id']
        );
        $this->assertSame(
            50.0,
            (float) $structuredRow['total_amount']
        );
        $this->assertSame(
            0.0,
            (float) $structuredRow['amount_paid']
        );
        $this->assertSame(
            50.0,
            (float) $structuredRow['outstanding_balance']
        );
        $this->assertSame(
            $structured['due_date'],
            $structuredRow['due_date']
        );
        $this->assertSame('Unpaid', $structuredRow['status']);
        $this->assertSame('structured', $structuredRow['source']);
        $this->assertFalse((bool) $structuredRow['overdue']);

        // Detail endpoint: legacy.
        $legacyDetail = $this->getJson(
            "/api/accounts-payable/{$legacy['ap_id']}"
        )->assertOk()->json('payable');

        $this->assertSame(
            $legacy['ap_id'],
            (int) $legacyDetail['id']
        );
        $this->assertNull($legacyDetail['supplier_invoice_id']);
        $this->assertSame('legacy', $legacyDetail['source']);
        $this->assertSame(
            75.0,
            (float) $legacyDetail['outstanding_balance']
        );
        $this->assertTrue((bool) $legacyDetail['overdue']);

        // Detail endpoint: structured.
        $structuredDetail = $this->getJson(
            "/api/accounts-payable/{$structured['ap_id']}"
        )->assertOk()->json('payable');

        $this->assertSame(
            $structured['ap_id'],
            (int) $structuredDetail['id']
        );
        $this->assertSame(
            $structured['invoice_id'],
            (int) $structuredDetail['supplier_invoice_id']
        );
        $this->assertSame(
            'structured',
            $structuredDetail['source']
        );
        $this->assertSame(
            50.0,
            (float) $structuredDetail['outstanding_balance']
        );
        $this->assertFalse((bool) $structuredDetail['overdue']);
    }

    public function test_accounts_payable_read_model_requires_finance_manage(): void
    {
        $legacy = $this->createLegacyPayable();

        foreach (['cashier', 'inventory', 'operations'] as $username) {
            $this->actingAs(
                User::where('username', $username)->firstOrFail()
            );

            $this->getJson('/api/accounts-payable')
                ->assertForbidden();

            $this->getJson(
                "/api/accounts-payable/{$legacy['ap_id']}"
            )->assertForbidden();
        }

        // Finance Manager has finance.manage and may access both.
        $this->actingAs(
            User::where('username', 'finance')->firstOrFail()
        );

        $this->getJson('/api/accounts-payable')
            ->assertOk();

        $this->getJson(
            "/api/accounts-payable/{$legacy['ap_id']}"
        )->assertOk();
    }

    public function test_accounts_payable_routes_only_add_append_only_payment_operations(): void
    {
        $routes = collect(
            app('router')->getRoutes()->getRoutes()
        )->filter(
            fn ($route) => str_contains(
                $route->uri(),
                'accounts-payable'
            )
        );

        $this->assertCount(4, $routes);

        $payableRoutes = $routes->filter(fn ($route) => in_array($route->uri(), [
            'api/accounts-payable',
            'api/accounts-payable/{id}',
        ], true));
        $this->assertCount(2, $payableRoutes);
        foreach ($payableRoutes as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }

        $paymentRoutes = $routes->filter(
            fn ($route) => $route->uri() === 'api/accounts-payable/{accountsPayable}/payments'
        );
        $this->assertCount(2, $paymentRoutes);
        $this->assertEqualsCanonicalizing(
            ['GET', 'HEAD', 'POST'],
            $paymentRoutes->flatMap(fn ($route) => $route->methods())->unique()->values()->all(),
        );

        foreach ($routes as $route) {
            $methods = $route->methods();

            $this->assertFalse(
                in_array('PUT', $methods, true)
            );
            $this->assertFalse(
                in_array('PATCH', $methods, true)
            );
            $this->assertFalse(
                in_array('DELETE', $methods, true)
            );
        }
    }
}
