<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierPaymentWorkflowTest extends TestCase
{
    public function test_only_finance_manager_can_use_supplier_payment_endpoints(): void
    {
        $payable = $this->createPayable();

        foreach (['cashier', 'inventory', 'operations'] as $username) {
            $this->actingAs(User::where('username', $username)->firstOrFail());
            $this->postJson(
                "/api/accounts-payable/{$payable['ap_id']}/payments",
                $this->paymentPayload("forbidden-{$username}"),
            )->assertForbidden();
            $this->getJson('/api/supplier-payments')->assertForbidden();
            $this->getJson('/api/supplier-payments/1')->assertForbidden();
            $this->getJson("/api/accounts-payable/{$payable['ap_id']}/payments")
                ->assertForbidden();
        }

        $this->actingAs($this->financeUser());
        $response = $this->pay($payable['ap_id'], 10, 'finance-allowed')->assertCreated();
        $paymentId = $response->json('payment.id');
        $this->getJson('/api/supplier-payments')->assertOk();
        $this->getJson("/api/supplier-payments/{$paymentId}")->assertOk();
        $this->getJson("/api/accounts-payable/{$payable['ap_id']}/payments")->assertOk();
    }

    public function test_structured_payable_supports_partial_multiple_and_exact_final_payments(): void
    {
        $payable = $this->createPayable(total: 100, structured: true);
        $this->actingAs($this->financeUser());

        $this->pay($payable['ap_id'], 33.33, 'cents-1')
            ->assertCreated()
            ->assertJsonPath('payable.amount_paid', 33.33)
            ->assertJsonPath('payable.status', 'Partially Paid')
            ->assertJsonPath('outstanding_balance', 66.67)
            ->assertJsonPath('idempotent_replay', false);
        $this->pay($payable['ap_id'], 33.33, 'cents-2')
            ->assertCreated()
            ->assertJsonPath('payable.amount_paid', 66.66)
            ->assertJsonPath('outstanding_balance', 33.34);
        $final = $this->pay($payable['ap_id'], 33.34, 'cents-3')
            ->assertCreated()
            ->assertJsonPath('payable.amount_paid', 100)
            ->assertJsonPath('payable.status', 'Paid')
            ->assertJsonPath('outstanding_balance', 0);

        $this->assertSame(100.0, (float) DB::table('accounts_payable')
            ->where('id', $payable['ap_id'])->value('amount_paid'));
        $this->assertSame(3, DB::table('supplier_payments')
            ->where('accounts_payable_id', $payable['ap_id'])->count());
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $payable['invoice_id'],
            'status' => 'Approved',
        ]);

        $this->pay($payable['ap_id'], 0.01, 'already-paid')
            ->assertConflict();
        $this->assertSame($final->json('payment.id'), DB::table('supplier_payments')
            ->where('idempotency_key', 'cents-3')->value('id'));
    }

    public function test_legacy_payable_can_be_settled_without_fabricating_an_invoice(): void
    {
        $payable = $this->createPayable(total: 50, structured: false);
        $invoiceCount = DB::table('supplier_invoices')->count();
        $this->actingAs($this->financeUser());

        $this->pay($payable['ap_id'], 50, 'legacy-full')
            ->assertCreated()
            ->assertJsonPath('payment.supplier_invoice_id', null)
            ->assertJsonPath('payable.source', 'legacy')
            ->assertJsonPath('payable.status', 'Paid');

        $this->assertSame($invoiceCount, DB::table('supplier_invoices')->count());
        $this->assertNull(DB::table('accounts_payable')
            ->where('id', $payable['ap_id'])->value('supplier_invoice_id'));
    }

    public function test_validation_and_overpayment_have_zero_side_effects(): void
    {
        $payable = $this->createPayable(total: 100);
        $this->actingAs($this->financeUser());

        $this->pay($payable['ap_id'], 0, 'zero')->assertUnprocessable();
        $this->pay($payable['ap_id'], -1, 'negative')->assertUnprocessable();

        $beforeAp = (array) DB::table('accounts_payable')->find($payable['ap_id']);
        $beforePayments = DB::table('supplier_payments')->count();
        $beforeTransactions = DB::table('financial_transactions')->count();
        $beforeAudits = DB::table('audit_logs')->count();

        $this->pay($payable['ap_id'], 100.01, 'overpay')->assertConflict();

        $this->assertSame($beforeAp, (array) DB::table('accounts_payable')->find($payable['ap_id']));
        $this->assertSame($beforePayments, DB::table('supplier_payments')->count());
        $this->assertSame($beforeTransactions, DB::table('financial_transactions')->count());
        $this->assertSame($beforeAudits, DB::table('audit_logs')->count());

        $this->pay($payable['ap_id'], 100, 'exact')->assertCreated()
            ->assertJsonPath('payable.status', 'Paid');
    }

    public function test_ap_relationships_and_ledger_fields_are_server_authoritative(): void
    {
        $payable = $this->createPayable(total: 100, structured: true);
        $other = $this->createPayable(total: 25, structured: true);
        $this->actingAs($this->financeUser());

        $response = $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            array_merge($this->paymentPayload('authoritative', 25), [
                'supplier_id' => $other['supplier_id'],
                'purchase_order_id' => $other['po_id'],
                'supplier_invoice_id' => $other['invoice_id'],
                'total_amount' => 0.01,
                'amount_paid' => 99999,
                'status' => 'Paid',
                'transaction_type' => 'Sale',
                'direction' => 'In',
            ]),
        )->assertCreated();

        $paymentId = $response->json('payment.id');
        $this->assertDatabaseHas('supplier_payments', [
            'id' => $paymentId,
            'accounts_payable_id' => $payable['ap_id'],
            'supplier_id' => $payable['supplier_id'],
            'purchase_order_id' => $payable['po_id'],
            'supplier_invoice_id' => $payable['invoice_id'],
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'transaction_type' => 'Supplier Payment',
            'direction' => 'Out',
            'amount' => 25,
            'reference_type' => 'supplier_payment',
            'reference_id' => (string) $paymentId,
            'payment_method' => 'Bank Transfer',
        ]);
    }

    public function test_exact_idempotent_replay_has_no_duplicate_side_effects(): void
    {
        $payable = $this->createPayable(total: 100);
        $payload = $this->paymentPayload('same-request', 40);
        $this->actingAs($this->financeUser());

        $created = $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            $payload,
        )->assertCreated();
        $paymentId = $created->json('payment.id');

        $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            $payload,
        )->assertOk()
            ->assertJsonPath('payment.id', $paymentId)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('payable.amount_paid', 40);

        $this->assertSame(1, DB::table('supplier_payments')
            ->where('idempotency_key', 'same-request')->count());
        $this->assertSame(1, DB::table('financial_transactions')
            ->where('reference_type', 'supplier_payment')
            ->where('reference_id', (string) $paymentId)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'supplier_payment.created')
            ->where('entity_id', (string) $paymentId)->count());
        $this->assertSame(40.0, (float) DB::table('accounts_payable')
            ->where('id', $payable['ap_id'])->value('amount_paid'));
    }

    public function test_idempotency_key_conflicts_when_any_logical_field_changes(): void
    {
        $payable = $this->createPayable(total: 100);
        $other = $this->createPayable(total: 100);
        $payload = $this->paymentPayload('conflict-key', 10);
        $this->actingAs($this->financeUser());
        $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            $payload,
        )->assertCreated();

        foreach ([
            ['amount' => 10.01],
            ['payment_method' => 'Check'],
            ['reference_number' => 'CHANGED'],
            ['payment_date' => '2026-08-10'],
            ['notes' => 'Changed notes'],
        ] as $changes) {
            $this->postJson(
                "/api/accounts-payable/{$payable['ap_id']}/payments",
                array_merge($payload, $changes),
            )->assertConflict();
        }

        $this->postJson(
            "/api/accounts-payable/{$other['ap_id']}/payments",
            $payload,
        )->assertConflict();
        $this->assertSame(1, DB::table('supplier_payments')
            ->where('idempotency_key', 'conflict-key')->count());
        $this->assertSame(10.0, (float) DB::table('accounts_payable')
            ->where('id', $payable['ap_id'])->value('amount_paid'));
        $this->assertSame(0.0, (float) DB::table('accounts_payable')
            ->where('id', $other['ap_id'])->value('amount_paid'));
    }

    public function test_replay_still_requires_finance_authorization(): void
    {
        $payable = $this->createPayable();
        $payload = $this->paymentPayload('protected-replay', 10);
        $this->actingAs($this->financeUser());
        $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            $payload,
        )->assertCreated();

        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->postJson(
            "/api/accounts-payable/{$payable['ap_id']}/payments",
            $payload,
        )->assertForbidden();
    }

    public function test_payment_read_models_and_append_only_routes(): void
    {
        $payable = $this->createPayable(total: 100, structured: true);
        $this->actingAs($this->financeUser());
        $firstId = $this->pay($payable['ap_id'], 20, 'history-1')
            ->assertCreated()->json('payment.id');
        $secondId = $this->pay($payable['ap_id'], 30, 'history-2')
            ->assertCreated()->json('payment.id');

        $this->getJson("/api/supplier-payments?accounts_payable_id={$payable['ap_id']}")
            ->assertOk()
            ->assertJsonPath('payments.total', 2)
            ->assertJsonPath('payments.data.0.supplier_name', $payable['supplier_name'])
            ->assertJsonPath('payments.data.0.po_number', $payable['po_number'])
            ->assertJsonPath('payments.data.0.invoice_number', $payable['invoice_number']);
        $this->getJson("/api/supplier-payments/{$firstId}")
            ->assertOk()
            ->assertJsonPath('payment.accounts_payable_id', $payable['ap_id']);
        $this->getJson("/api/accounts-payable/{$payable['ap_id']}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'payments')
            ->assertJsonPath('payments.0.id', $firstId)
            ->assertJsonPath('payments.1.id', $secondId);
        $this->getJson("/api/accounts-payable/{$payable['ap_id']}")
            ->assertOk()
            ->assertJsonPath('payable.amount_paid', 50)
            ->assertJsonPath('payable.outstanding_balance', 50)
            ->assertJsonPath('payable.status', 'Partially Paid');

        $this->putJson("/api/supplier-payments/{$firstId}", [])->assertStatus(405);
        $this->patchJson("/api/supplier-payments/{$firstId}", [])->assertStatus(405);
        $this->deleteJson("/api/supplier-payments/{$firstId}")->assertStatus(405);
    }

    public function test_payment_creates_one_audited_transaction_and_changes_no_inventory_or_procurement_data(): void
    {
        $payable = $this->createPayable(total: 100, structured: true, withReceiving: true);
        $before = $this->isolationSnapshot($payable);
        $this->actingAs($this->financeUser());

        $paymentId = $this->pay($payable['ap_id'], 25, 'isolated')
            ->assertCreated()->json('payment.id');

        $this->assertSame($before, $this->isolationSnapshot($payable));
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $payable['invoice_id'],
            'status' => 'Approved',
        ]);
        $this->assertSame(1, DB::table('financial_transactions')
            ->where('transaction_type', 'Supplier Payment')
            ->where('reference_id', (string) $paymentId)->count());
        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'supplier_payment.created')
            ->where('entity_type', 'supplier_payment')
            ->where('entity_id', (string) $paymentId)->count());
    }

    private function pay(int $payableId, float|int $amount, string $key)
    {
        return $this->postJson(
            "/api/accounts-payable/{$payableId}/payments",
            $this->paymentPayload($key, $amount),
        );
    }

    private function paymentPayload(string $key, float|int $amount = 10): array
    {
        return [
            'amount' => $amount,
            'payment_method' => 'Bank Transfer',
            'reference_number' => 'REF-001',
            'payment_date' => '2026-08-11',
            'notes' => 'Supplier settlement',
            'idempotency_key' => $key,
        ];
    }

    private function financeUser(): User
    {
        return User::where('username', 'finance')->firstOrFail();
    }

    private function createPayable(
        float $total = 100,
        bool $structured = true,
        bool $withReceiving = false,
    ): array {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        $suffix = uniqid();
        $poNumber = "PO-PAY-{$suffix}";
        $invoiceNumber = "INV-PAY-{$suffix}";
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => $poNumber,
            'supplier_id' => $product->supplier_id,
            'status' => $withReceiving ? 'Partially Received' : 'Ordered',
            'approval_status' => 'Approved',
            'supplier_status' => $structured ? 'Accepted' : null,
            'quantity_ordered' => 10,
            'created_by' => 'test',
        ]);
        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 10,
            'quantity_received' => $withReceiving ? 5 : 0,
            'unit_cost' => $total / 10,
        ]);

        $invoiceId = null;
        if ($structured) {
            $invoiceId = DB::table('supplier_invoices')->insertGetId([
                'purchase_order_id' => $poId,
                'supplier_id' => $product->supplier_id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => '2026-08-01',
                'due_date' => '2026-08-31',
                'status' => 'Approved',
                'registered_by' => 'finance',
                'registered_at' => '2026-08-01 09:00:00',
                'approved_by' => 'finance',
                'approved_at' => '2026-08-01 10:00:00',
                'created_by' => 'finance',
            ]);
            DB::table('supplier_invoice_items')->insert([
                'supplier_invoice_id' => $invoiceId,
                'purchase_order_item_id' => $poItemId,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'invoiced_quantity' => 10,
                'unit_cost' => $total / 10,
                'line_total' => $total,
            ]);
        }

        if ($withReceiving) {
            $receivingId = DB::table('stock_receivings')->insertGetId([
                'purchase_order_id' => $poId,
                'received_by' => 'inventory',
                'receiving_date' => '2026-08-02 09:00:00',
            ]);
            DB::table('stock_receiving_items')->insert([
                'stock_receiving_id' => $receivingId,
                'purchase_order_item_id' => $poItemId,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'received_quantity' => 5,
                'unit_cost' => $total / 10,
            ]);
        }

        $apId = DB::table('accounts_payable')->insertGetId([
            'supplier_id' => $product->supplier_id,
            'purchase_order_id' => $poId,
            'supplier_invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
            'total_amount' => $total,
            'amount_paid' => 0,
            'due_date' => '2026-08-31',
            'status' => 'Unpaid',
        ]);

        return [
            'ap_id' => $apId,
            'supplier_id' => (int) $product->supplier_id,
            'supplier_name' => DB::table('suppliers')->where('id', $product->supplier_id)->value('name'),
            'product_id' => (int) $product->id,
            'po_id' => $poId,
            'po_item_id' => $poItemId,
            'po_number' => $poNumber,
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoiceNumber,
        ];
    }

    private function isolationSnapshot(array $payable): array
    {
        return [
            'product' => (array) DB::table('products')->find($payable['product_id']),
            'inventory_movements' => DB::table('inventory_movements')
                ->where('product_id', $payable['product_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'stock_receivings' => DB::table('stock_receivings')
                ->where('purchase_order_id', $payable['po_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'stock_receiving_items' => DB::table('stock_receiving_items')
                ->where('purchase_order_item_id', $payable['po_item_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'po' => (array) DB::table('purchase_orders')->find($payable['po_id']),
            'po_item' => (array) DB::table('purchase_order_items')->find($payable['po_item_id']),
            'supplier_invoice' => (array) DB::table('supplier_invoices')->find($payable['invoice_id']),
            'supplier_invoice_items' => DB::table('supplier_invoice_items')
                ->where('supplier_invoice_id', $payable['invoice_id'])->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
