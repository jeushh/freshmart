<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierInvoiceLegacyCompatibilityTest extends TestCase
{
    private function createLegacyPurchaseOrder(
        int $quantity = 5,
        float $unitCost = 10.00
    ): array {
        $product = DB::table('products')
            ->where('sku', 'FRU-001')
            ->first();

        $this->assertNotNull($product);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-LEGACY-'.uniqid(),
            'supplier_id' => $product->supplier_id,
            'status' => 'Approved',
            'approval_status' => 'Approved',
            'supplier_status' => null,
            'quantity_ordered' => $quantity,
            'created_by' => 'legacy',
        ]);

        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
        ]);

        return [
            $poId,
            DB::table('purchase_order_items')->where('id', $poItemId)->first(),
            $product,
        ];
    }

    private function createTrackedPurchaseOrder(
        int $quantity = 5,
        float $unitCost = 10.00
    ): array {
        $product = DB::table('products')
            ->where('sku', 'FRU-001')
            ->first();

        $this->assertNotNull($product);

        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ])->assertCreated();

        $poId = (int) $response->json('order.id');

        $this->postJson(
            "/api/purchase-orders/{$poId}/submit"
        )->assertOk();

        $this->actingAs(
            User::where('username', 'operations')->firstOrFail()
        );

        $this->postJson(
            "/api/purchase-orders/{$poId}/review",
            ['decision' => 'Approved']
        )->assertOk();

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

        return [$poId, $poItem, $product];
    }

    public function test_legacy_receiving_creates_purchase_transaction_and_accumulates_single_ap(): void
    {
        [$poId, $poItem] = $this->createLegacyPurchaseOrder(5, 10.00);

        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        // First partial receipt: 2 x 10 = 20
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'invoice_number' => 'LEGACY-INV-001',
            'due_date' => '2026-09-01',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame(
            1,
            DB::table('financial_transactions')
                ->where('transaction_type', 'Purchase')
                ->where('direction', 'Out')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $poId)
                ->count()
        );

        $payable = DB::table('accounts_payable')
            ->where('purchase_order_id', $poId)
            ->whereNull('supplier_invoice_id')
            ->first();

        $this->assertNotNull($payable);
        $this->assertNull($payable->supplier_invoice_id);
        $this->assertSame(20.0, (float) $payable->total_amount);
        $this->assertSame(0.0, (float) $payable->amount_paid);
        $this->assertSame('Unpaid', $payable->status);
        $this->assertSame('LEGACY-INV-001', $payable->invoice_number);
        $this->assertSame('2026-09-01', $payable->due_date);

        // Second partial receipt: remaining 3 x 10 = 30
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 3,
            ]],
        ])->assertCreated();

        // One Purchase/Out transaction per receiving.
        $this->assertSame(
            2,
            DB::table('financial_transactions')
                ->where('transaction_type', 'Purchase')
                ->where('direction', 'Out')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $poId)
                ->count()
        );

        $purchaseTotal = (float) DB::table('financial_transactions')
            ->where('transaction_type', 'Purchase')
            ->where('direction', 'Out')
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', (string) $poId)
            ->sum('amount');

        $this->assertSame(50.0, $purchaseTotal);

        // Legacy receiving must accumulate into ONE legacy AP.
        $this->assertSame(
            1,
            DB::table('accounts_payable')
                ->where('purchase_order_id', $poId)
                ->whereNull('supplier_invoice_id')
                ->count()
        );

        $payable = DB::table('accounts_payable')
            ->where('purchase_order_id', $poId)
            ->whereNull('supplier_invoice_id')
            ->first();

        $this->assertSame(50.0, (float) $payable->total_amount);
        $this->assertSame(0.0, (float) $payable->amount_paid);
        $this->assertSame('Unpaid', $payable->status);
        $this->assertSame('LEGACY-INV-001', $payable->invoice_number);
        $this->assertSame('2026-09-01', $payable->due_date);

        $this->assertSame(
            5,
            (int) DB::table('purchase_order_items')
                ->where('id', $poItem->id)
                ->value('quantity_received')
        );
    }

    public function test_tracked_receiving_creates_purchase_transaction_but_no_ap(): void
    {
        [$poId, $poItem] = $this->createTrackedPurchaseOrder(5, 10.00);

        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame(
            1,
            DB::table('financial_transactions')
                ->where('transaction_type', 'Purchase')
                ->where('direction', 'Out')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $poId)
                ->count()
        );

        $transaction = DB::table('financial_transactions')
            ->where('transaction_type', 'Purchase')
            ->where('direction', 'Out')
            ->where('reference_type', 'purchase_order')
            ->where('reference_id', (string) $poId)
            ->first();

        $this->assertNotNull($transaction);
        $this->assertSame(20.0, (float) $transaction->amount);

        $this->assertSame(
            0,
            DB::table('accounts_payable')
                ->where('purchase_order_id', $poId)
                ->count()
        );
    }

    public function test_tracked_receiving_rejects_invoice_number_and_due_date(): void
    {
        [$poId, $poItem] = $this->createTrackedPurchaseOrder(5, 10.00);

        $this->actingAs(
            User::where('username', 'inventory')->firstOrFail()
        );

        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'invoice_number' => 'SHOULD-NOT-BE-ACCEPTED',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 1,
            ]],
        ])->assertStatus(422);

        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'due_date' => '2026-09-01',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'delivered_quantity' => 1,
            ]],
        ])->assertStatus(422);

        // Failed validation/guards must not create receiving side effects.
        $this->assertSame(
            0,
            DB::table('stock_receivings')
                ->where('purchase_order_id', $poId)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('financial_transactions')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $poId)
                ->count()
        );

        $this->assertSame(
            0,
            DB::table('accounts_payable')
                ->where('purchase_order_id', $poId)
                ->count()
        );
    }

    public function test_legacy_po_cannot_enter_structured_invoice_workflow(): void
    {
        [$poId, $poItem] = $this->createLegacyPurchaseOrder(5, 10.00);

        $this->actingAs(
            User::where('username', 'finance')->firstOrFail()
        );

        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'LEGACY-STRUCTURED-BLOCK',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 1,
                'unit_cost' => 10.00,
            ]],
        ])->assertStatus(409);

        $this->assertSame(
            0,
            DB::table('supplier_invoices')
                ->where('purchase_order_id', $poId)
                ->count()
        );
    }
}
