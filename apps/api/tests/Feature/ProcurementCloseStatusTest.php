<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProcurementCloseStatusService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementCloseStatusTest extends TestCase
{
    public function test_delivery_always_precedes_payment_for_tracked_and_legacy_orders(): void
    {
        $tracked = $this->createPurchaseOrder(true, 'Partially Received');
        $trackedInvoice = $this->createInvoice($tracked, 'Approved', [5], true, 50);
        $legacy = $this->createPurchaseOrder(false, 'Partially Received');
        $this->createLegacyPayable($legacy, 50, 50);

        $this->assertSame('Paid', DB::table('accounts_payable')
            ->where('supplier_invoice_id', $trackedInvoice['invoice_id'])->value('status'));
        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_DELIVERY,
            $this->derivedStatus($tracked['po_id']),
        );
        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_DELIVERY,
            $this->derivedStatus($legacy['po_id']),
        );
    }

    public function test_unapproved_invoice_states_and_no_invoice_await_invoice(): void
    {
        $withoutInvoice = $this->createPurchaseOrder();
        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_INVOICE,
            $this->derivedStatus($withoutInvoice['po_id']),
        );

        foreach (['Draft', 'Registered', 'Disputed', 'Void'] as $invoiceStatus) {
            $order = $this->createPurchaseOrder();
            $this->createInvoice($order, $invoiceStatus, [10]);
            $this->assertSame(
                ProcurementCloseStatusService::AWAITING_INVOICE,
                $this->derivedStatus($order['po_id']),
                "{$invoiceStatus} invoice must not count as Approved coverage.",
            );
        }
    }

    public function test_paid_approved_invoice_with_only_partial_quantity_coverage_awaits_invoice(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createInvoice($order, 'Approved', [5], true, 50);

        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_INVOICE,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_multi_invoice_full_coverage_with_one_unsettled_ap_awaits_payment(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createInvoice($order, 'Approved', [5], true, 50);
        $this->createInvoice($order, 'Approved', [5], true, 25);

        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_PAYMENT,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_multi_invoice_full_coverage_and_full_settlement_is_complete(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createInvoice($order, 'Approved', [5], true, 50);
        $this->createInvoice($order, 'Approved', [5], true, 50);

        $this->assertSame(
            ProcurementCloseStatusService::COMPLETE,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_multi_line_order_requires_approved_coverage_for_every_line(): void
    {
        $order = $this->createPurchaseOrder(items: [
            ['quantity' => 6, 'unit_cost' => 10],
            ['quantity' => 4, 'unit_cost' => 20],
        ]);
        $this->createInvoice($order, 'Approved', [6, 2], true, 100);

        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_INVOICE,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_fully_covered_approved_invoice_without_ap_awaits_payment(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createInvoice($order, 'Approved', [10], false);

        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_PAYMENT,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_legacy_payables_use_centavo_outstanding_balance(): void
    {
        foreach ([
            [0, ProcurementCloseStatusService::AWAITING_PAYMENT],
            [33.33, ProcurementCloseStatusService::AWAITING_PAYMENT],
            [100, ProcurementCloseStatusService::COMPLETE],
        ] as [$amountPaid, $expected]) {
            $order = $this->createPurchaseOrder(false);
            $this->createLegacyPayable($order, 100, $amountPaid);
            $this->assertSame($expected, $this->derivedStatus($order['po_id']));
        }
    }

    public function test_legacy_no_ap_uses_expected_purchase_cost(): void
    {
        $zeroCost = $this->createPurchaseOrder(false, items: [
            ['quantity' => 10, 'unit_cost' => 0],
        ]);
        $nonZeroCost = $this->createPurchaseOrder(false);

        $this->assertSame(
            ProcurementCloseStatusService::COMPLETE,
            $this->derivedStatus($zeroCost['po_id']),
        );
        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_PAYMENT,
            $this->derivedStatus($nonZeroCost['po_id']),
        );
    }

    public function test_void_invoice_is_ignored_when_approved_invoices_coexist(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createInvoice($order, 'Void', [5]);
        $this->createInvoice($order, 'Approved', [5], true, 50);
        $this->assertSame(
            ProcurementCloseStatusService::AWAITING_INVOICE,
            $this->derivedStatus($order['po_id']),
        );

        $this->createInvoice($order, 'Approved', [5], true, 50);
        $this->assertSame(
            ProcurementCloseStatusService::COMPLETE,
            $this->derivedStatus($order['po_id']),
        );
    }

    public function test_ap_detail_field_is_finance_only_and_derivation_is_read_only(): void
    {
        $order = $this->createPurchaseOrder();
        $invoice = $this->createInvoice($order, 'Approved', [10], true, 100);
        $before = $this->workflowSnapshot();

        $this->actingAs(User::where('username', 'finance')->firstOrFail());
        $this->getJson("/api/accounts-payable/{$invoice['ap_id']}")
            ->assertOk()
            ->assertJsonPath(
                'payable.procurement_close_status',
                ProcurementCloseStatusService::COMPLETE,
            );
        $this->getJson('/api/accounts-payable')
            ->assertOk()
            ->assertJsonMissingPath('payables.data.0.procurement_close_status');

        $this->assertSame($before, $this->workflowSnapshot());

        foreach (['cashier', 'inventory', 'operations'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail());
            $this->getJson("/api/accounts-payable/{$invoice['ap_id']}")
                ->assertForbidden();
        }
    }

    public function test_ap_detail_refresh_changes_from_awaiting_payment_to_complete_after_final_payment(): void
    {
        $order = $this->createPurchaseOrder();
        $invoice = $this->createInvoice($order, 'Approved', [10], true, 25);
        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        $this->getJson("/api/accounts-payable/{$invoice['ap_id']}")
            ->assertOk()
            ->assertJsonPath(
                'payable.procurement_close_status',
                ProcurementCloseStatusService::AWAITING_PAYMENT,
            );
        $this->postJson("/api/accounts-payable/{$invoice['ap_id']}/payments", [
            'amount' => 75,
            'payment_method' => 'Bank Transfer',
            'payment_date' => '2026-08-13',
            'idempotency_key' => 'procurement-close-final-payment',
        ])->assertCreated();
        $this->getJson("/api/accounts-payable/{$invoice['ap_id']}")
            ->assertOk()
            ->assertJsonPath(
                'payable.procurement_close_status',
                ProcurementCloseStatusService::COMPLETE,
            );
    }

    private function derivedStatus(int $purchaseOrderId): string
    {
        return app(ProcurementCloseStatusService::class)->forPurchaseOrder($purchaseOrderId);
    }

    private function createPurchaseOrder(
        bool $tracked = true,
        string $status = 'Fully Received',
        array $items = [['quantity' => 10, 'unit_cost' => 10]],
    ): array {
        $products = DB::table('products')->orderBy('id')->limit(count($items))->get();
        $supplierId = (int) $products->first()->supplier_id;
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-CLOSE-'.uniqid(),
            'supplier_id' => $supplierId,
            'status' => $status,
            'approval_status' => 'Approved',
            'supplier_status' => $tracked ? 'Accepted' : null,
            'quantity_ordered' => array_sum(array_column($items, 'quantity')),
            'created_by' => 'test',
        ]);

        $poItems = [];
        foreach ($items as $index => $definition) {
            $product = $products[$index];
            $received = $status === 'Fully Received'
                ? $definition['quantity']
                : intdiv($definition['quantity'], 2);
            $itemId = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $poId,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity_ordered' => $definition['quantity'],
                'quantity_received' => $received,
                'unit_cost' => $definition['unit_cost'],
            ]);
            $poItems[] = [
                'id' => $itemId,
                'product_id' => (int) $product->id,
                'sku' => $product->sku,
                'quantity' => $definition['quantity'],
                'unit_cost' => $definition['unit_cost'],
                'received' => $received,
            ];
        }

        if (collect($poItems)->sum('received') > 0) {
            $receivingId = DB::table('stock_receivings')->insertGetId([
                'purchase_order_id' => $poId,
                'received_by' => 'inventory',
                'receiving_date' => '2026-08-12 09:00:00',
            ]);
            foreach ($poItems as $item) {
                if ($item['received'] === 0) {
                    continue;
                }
                DB::table('stock_receiving_items')->insert([
                    'stock_receiving_id' => $receivingId,
                    'purchase_order_item_id' => $item['id'],
                    'product_id' => $item['product_id'],
                    'sku' => $item['sku'],
                    'received_quantity' => $item['received'],
                    'damaged_quantity' => 0,
                    'rejected_quantity' => 0,
                    'unit_cost' => $item['unit_cost'],
                ]);
            }
        }

        return [
            'po_id' => $poId,
            'supplier_id' => $supplierId,
            'items' => $poItems,
        ];
    }

    private function createInvoice(
        array $order,
        string $status,
        array $quantities,
        bool $createPayable = false,
        float $amountPaid = 0,
    ): array {
        $invoiceId = DB::table('supplier_invoices')->insertGetId([
            'purchase_order_id' => $order['po_id'],
            'supplier_id' => $order['supplier_id'],
            'invoice_number' => 'INV-CLOSE-'.uniqid(),
            'invoice_date' => '2026-08-12',
            'due_date' => '2026-09-12',
            'status' => $status,
            'registered_by' => $status === 'Draft' ? null : 'finance',
            'registered_at' => $status === 'Draft' ? null : '2026-08-12 10:00:00',
            'approved_by' => $status === 'Approved' ? 'finance' : null,
            'approved_at' => $status === 'Approved' ? '2026-08-12 11:00:00' : null,
            'created_by' => 'finance',
        ]);
        $totalCents = 0;
        foreach ($quantities as $index => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            $item = $order['items'][$index];
            $lineCents = $quantity * (int) round($item['unit_cost'] * 100);
            $totalCents += $lineCents;
            DB::table('supplier_invoice_items')->insert([
                'supplier_invoice_id' => $invoiceId,
                'purchase_order_item_id' => $item['id'],
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'invoiced_quantity' => $quantity,
                'unit_cost' => $item['unit_cost'],
                'line_total' => $lineCents / 100,
            ]);
        }

        $apId = null;
        if ($createPayable) {
            $total = $totalCents / 100;
            $apId = DB::table('accounts_payable')->insertGetId([
                'supplier_id' => $order['supplier_id'],
                'purchase_order_id' => $order['po_id'],
                'supplier_invoice_id' => $invoiceId,
                'invoice_number' => DB::table('supplier_invoices')
                    ->where('id', $invoiceId)->value('invoice_number'),
                'total_amount' => $total,
                'amount_paid' => $amountPaid,
                'due_date' => '2026-09-12',
                'status' => $amountPaid >= $total
                    ? 'Paid'
                    : ($amountPaid > 0 ? 'Partially Paid' : 'Unpaid'),
            ]);
        }

        return ['invoice_id' => $invoiceId, 'ap_id' => $apId];
    }

    private function createLegacyPayable(array $order, float $total, float $amountPaid): int
    {
        return DB::table('accounts_payable')->insertGetId([
            'supplier_id' => $order['supplier_id'],
            'purchase_order_id' => $order['po_id'],
            'supplier_invoice_id' => null,
            'invoice_number' => 'LEGACY-CLOSE-'.uniqid(),
            'total_amount' => $total,
            'amount_paid' => $amountPaid,
            'due_date' => '2026-09-12',
            'status' => $amountPaid >= $total
                ? 'Paid'
                : ($amountPaid > 0 ? 'Partially Paid' : 'Unpaid'),
        ]);
    }

    private function workflowSnapshot(): array
    {
        return collect([
            'products',
            'inventory_movements',
            'stock_receivings',
            'stock_receiving_items',
            'purchase_orders',
            'purchase_order_items',
            'supplier_invoices',
            'supplier_invoice_items',
            'accounts_payable',
            'supplier_payments',
        ])->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all(),
        ])->all();
    }
}
