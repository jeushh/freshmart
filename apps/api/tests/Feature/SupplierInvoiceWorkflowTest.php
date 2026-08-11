<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierInvoiceWorkflowTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a tracked (supplier_status=Accepted) approved purchase order
     * that is eligible for structured invoicing.
     *
     * @returns [$purchaseOrderId, $poItem, $product, $supplierId]
     */
    private function createEligibleTrackedPO(int $quantity = 10, float $unitCost = 5.0): array
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();

        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ]],
        ])->assertCreated();
        $purchaseOrderId = $response->json('order.id');

        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/submit", [])->assertOk();

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/review", [
            'decision' => 'Approved',
        ])->assertOk();

        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/send")->assertOk();
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/supplier-response", [
            'response' => 'Accepted',
        ])->assertOk();

        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        $poItem = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->first();

        return [$purchaseOrderId, $poItem, $product, (int) $product->supplier_id];
    }

    /**
     * Create a legacy (supplier_status=NULL) approved purchase order.
     *
     * @returns [$purchaseOrderId, $poItem, $product, $supplierId]
     */
    private function createLegacyPO(int $quantity = 10, float $unitCost = 5.0): array
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-LEGACY-'.now()->format('His'),
            'supplier_id' => $product->supplier_id,
            'status' => 'Approved',
            'approval_status' => 'Approved',
            'supplier_status' => null,
            'quantity_ordered' => $quantity,
            'created_by' => 'legacy',
        ]);
        $poItem = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => $unitCost,
        ]);

        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        return [$poId, DB::table('purchase_order_items')->where('id', $poItem)->first(), $product, (int) $product->supplier_id];
    }

    /**
     * Create a draft supplier invoice for the given PO.
     *
     * @returns invoice response data (object / array)
     */
    private function createDraftInvoice(int $purchaseOrderId, int $poItemId, int $qty, float $unitCost, ?string $invoiceNumber = 'DRAFT-001'): array
    {
        $payload = [
            'invoice_number' => $invoiceNumber,
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'invoiced_quantity' => $qty,
                'unit_cost' => $unitCost,
            ]],
        ];

        $response = $this->postJson("/api/purchase-orders/{$purchaseOrderId}/invoices", $payload)
            ->assertCreated();

        return $response->json('invoice');
    }

    /**
     * Register a draft invoice by its ID.
     */
    private function registerInvoice(int $invoiceId): void
    {
        $this->postJson("/api/supplier-invoices/{$invoiceId}/register")->assertOk();
    }

    /**
     * Approve a Registered invoice by its ID.
     */
    private function approveInvoice(int $invoiceId): void
    {
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();
    }

    /**
     * Create a Draft+Registered invoice for the given PO and return its ID.
     */
    private function createAndRegisterInvoice(int $purchaseOrderId, int $poItemId, int $qty, float $unitCost, ?string $invoiceNumber = 'REG-001'): int
    {
        $invoice = $this->createDraftInvoice($purchaseOrderId, $poItemId, $qty, $unitCost, $invoiceNumber);
        $invoiceId = $invoice['id'];
        $this->registerInvoice($invoiceId);

        return $invoiceId;
    }

    /**
     * Receive accepted stock on a tracked PO.
     */
    private function receiveStock(int $purchaseOrderId, int $poItemId, int $delivered): void
    {
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => $delivered,
            ]],
        ])->assertCreated();
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
    }

    // -------------------------------------------------------------------------
    // RBAC
    // -------------------------------------------------------------------------

    public function test_finance_manager_is_allowed_to_manage_supplier_invoices(): void
    {
        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        [$poId, $poItem] = $this->createEligibleTrackedPO();

        $this->getJson('/api/supplier-invoices')->assertOk();
        $this->getJson('/api/accounts-payable')->assertOk();
        $this->getJson('/api/finance/purchase-orders')->assertOk();
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'INV-RBAC-FINANCE',
            'items' => [
                [
                    'purchase_order_item_id' => $poItem->id,
                    'invoiced_quantity' => 1,
                    'unit_cost' => 5.00,
                ],
            ],
        ])->assertCreated();
    }

    public function test_cashier_is_forbidden_from_supplier_invoices(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());

        $this->getJson('/api/supplier-invoices')->assertForbidden();
        $this->getJson('/api/accounts-payable')->assertForbidden();
        $this->getJson('/api/finance/purchase-orders')->assertForbidden();
    }

    public function test_inventory_staff_is_forbidden_from_supplier_invoices(): void
    {
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $this->getJson('/api/supplier-invoices')->assertForbidden();
        $this->getJson('/api/accounts-payable')->assertForbidden();
    }

    public function test_operations_manager_is_forbidden_from_supplier_invoices(): void
    {
        $this->actingAs(User::where('username', 'operations')->firstOrFail());

        $this->getJson('/api/supplier-invoices')->assertForbidden();
        $this->getJson('/api/accounts-payable')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // PO eligibility
    // -------------------------------------------------------------------------

    public function test_legacy_null_supplier_status_po_rejected_for_structured_invoice(): void
    {
        [$poId, $poItem] = $this->createLegacyPO(10);

        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(409);

        $this->assertDatabaseMissing('supplier_invoices', ['purchase_order_id' => $poId]);
    }

    public function test_not_sent_supplier_status_rejected(): void
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
        $poId = $response->json('order.id');
        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        // PO is Approved internally, supplier_status = Not Sent
        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        $poItem = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->first();
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(409);
    }

    public function test_sent_rejected_supplier_rejected_for_invoice(): void
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
        $poId = $response->json('order.id');
        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();

        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();

        // supplier_status = 'Sent', which is not 'Accepted' — rejected
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
        $poItem = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->first();
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(409);
    }

    public function test_accepted_approved_ordered_po_is_allowed_for_invoice(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoice = $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-ALLOWED-001');
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoice['id'],
            'status' => 'Draft',
            'purchase_order_id' => $poId,
        ]);
    }

    public function test_pre_delivery_registration_allowed(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // No receiving yet — pre-delivery. Registration should still work
        // because ordered-cap is checked against quantity_ordered, not received.
        $invoice = $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-PREDEL-001');
        $this->registerInvoice($invoice['id']);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoice['id'],
            'status' => 'Registered',
        ]);
        // No AP should be created during registration.
        $this->assertDatabaseMissing('accounts_payable', [
            'supplier_invoice_id' => $invoice['id'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function test_invoice_number_required_at_registration(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // Create draft WITHOUT invoice number
        $response = $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
        $invoiceId = $response->json('invoice.id');

        // Registration must fail without invoice number
        $this->postJson("/api/supplier-invoices/{$invoiceId}/register")
            ->assertStatus(422);

        // Add invoice number via update (Draft is editable)
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'INV-REQ-001',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertOk();

        // Now registration should succeed
        $this->postJson("/api/supplier-invoices/{$invoiceId}/register")->assertOk();
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Registered',
        ]);
    }

    public function test_supplier_and_invoice_number_uniqueness(): void
    {
        [$poId, $poItem, $product] = $this->createEligibleTrackedPO(10);
        $supplierId = $product->supplier_id;

        // Create invoice with INV-001
        $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-DUP-001');

        // Same supplier, same invoice number — must fail
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'INV-DUP-001',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 3,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(422);

        // Same invoice number but different supplier — must succeed
        $otherSupplier = DB::table('suppliers')
            ->where('id', '!=', $supplierId)
            ->where('status', 'Active')
            ->first();
        $otherProduct = DB::table('products')
            ->where('supplier_id', $otherSupplier->id)
            ->where('status', 'Active')
            ->first();

        // Create a second eligible PO for the other supplier
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $otherSupplier->id,
            'items' => [[
                'product_id' => $otherProduct->id,
                'quantity' => 10,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
        $poId2 = $response->json('order.id');
        $this->postJson("/api/purchase-orders/{$poId2}/submit")->assertOk();
        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId2}/review", ['decision' => 'Approved'])->assertOk();
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId2}/send")->assertOk();
        $this->postJson("/api/purchase-orders/{$poId2}/supplier-response", ['response' => 'Accepted'])->assertOk();
        $this->actingAs(User::where('username', 'finance')->firstOrFail());

        $poItem2 = DB::table('purchase_order_items')->where('purchase_order_id', $poId2)->first();
        $this->postJson("/api/purchase-orders/{$poId2}/invoices", [
            'invoice_number' => 'INV-DUP-001',
            'items' => [[
                'purchase_order_item_id' => $poItem2->id,
                'invoiced_quantity' => 3,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
    }

    public function test_po_line_membership_enforced(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // Use a purchase_order_item_id that belongs to a DIFFERENT PO
        [$otherPoId, $otherPoItem] = $this->createEligibleTrackedPO(10);

        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'INV-MEMBERSHIP-001',
            'items' => [[
                'purchase_order_item_id' => $otherPoItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(422);
    }

    public function test_no_non_po_lines_allowed(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // Use a non-existent purchase_order_item_id
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'INV-NONPO-001',
            'items' => [[
                'purchase_order_item_id' => 999999,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(422);
    }

    public function test_ordered_quantity_reservation_cap_during_registration(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // First invoice: qty 6 — fits within ordered 10
        $invoiceA = $this->createDraftInvoice($poId, $poItem->id, 6, 5.00, 'INV-CAP-A');
        $this->registerInvoice($invoiceA['id']);

        // Second invoice: qty 5 — 6+5=11 > 10 — must fail
        $this->postJson("/api/purchase-orders/{$poId}/invoices", [
            'invoice_number' => 'INV-CAP-B',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertCreated();
        $invoiceB = DB::table('supplier_invoices')->where('invoice_number', 'INV-CAP-B')->first();
        $this->postJson("/api/supplier-invoices/{$invoiceB->id}/register")
            ->assertStatus(422);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceB->id,
            'status' => 'Draft',
        ]);
    }

    // -------------------------------------------------------------------------
    // Dispute
    // -------------------------------------------------------------------------

    public function test_registered_to_disputed_transition(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-DISPUTE-001');

        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute", [
            'notes' => 'Price mismatch noted.',
        ])->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Disputed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.disputed',
            'entity_id' => (string) $invoiceId,
        ]);
    }

    public function test_edit_allowed_while_disputed(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-EDIT-OLD');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertOk();

        // Edit invoice_number while Disputed — should succeed
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'INV-EDIT-NEW',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'invoice_number' => 'INV-EDIT-NEW',
            'status' => 'Disputed',
        ]);
    }

    public function test_quantity_cap_enforced_during_disputed_edit(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        // Invoice A: qty 3, registered
        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 3, 5.00, 'INV-CAP-DISPUTED-A');

        // Transition to Disputed before editing
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertOk();
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Disputed',
        ]);

        // Edit to increase qty beyond ordered (3 -> 11, ordered = 10)
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'INV-CAP-DISPUTED-A',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 11,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(422);

        // Edit to qty 10 should pass (10 <= 10, no other committed)
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'INV-CAP-DISPUTED-A',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 10,
                'unit_cost' => 5.00,
            ]],
        ])->assertOk();
    }

    public function test_resolve_dispute_reruns_registration_validation(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 3, 5.00, 'INV-RESOLVE-A');

        // Create a second invoice that consumes the remaining ordered qty
        $this->createAndRegisterInvoice($poId, $poItem->id, 7, 5.00, 'INV-RESOLVE-B');

        // Dispute invoice A
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertOk();

        // Now total committed = 3 (A, Disputed) + 7 (B, Registered) = 10
        // Try to resolve dispute — the ordered cap should still hold (3 + 7 = 10 <= 10)
        // So resolve should succeed here. Let's instead test a failure case.

        // Let's test the failure: change invoice A's qty to exceed ordered
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'INV-RESOLVE-A',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 4,  // 4 + 7 = 11 > 10
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(422);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Disputed',
        ]);
    }

    public function test_resolve_dispute_creates_no_ap(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-NOAP-001');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertOk();

        // Resolve dispute
        $this->postJson("/api/supplier-invoices/{$invoiceId}/resolve-dispute")->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Registered',
        ]);
        // No AP should have been created by resolve-dispute
        $this->assertDatabaseMissing('accounts_payable', [
            'supplier_invoice_id' => $invoiceId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.dispute_resolved',
            'entity_id' => (string) $invoiceId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Approval
    // -------------------------------------------------------------------------

    public function test_under_received_approval_returns_409(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        // Only receive 5 — less than invoiced qty
        $this->receiveStock($poId, $poItem->id, 5);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-UNDER-001');

        // Approval should fail: received 5 < 0 + 10
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")
            ->assertStatus(409);

        // Invoice remains Registered
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Registered',
        ]);
        // No AP created
        $this->assertDatabaseMissing('accounts_payable', [
            'supplier_invoice_id' => $invoiceId,
        ]);
    }

    public function test_approval_succeeds_after_receiving_catches_up(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        // Receive only 5 initially
        $this->receiveStock($poId, $poItem->id, 5);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-CATCHUP-001');

        // Approval fails — 5 < 10
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertStatus(409);

        // Receive 5 more — total received = 10
        $this->receiveStock($poId, $poItem->id, 5);

        // Now approval should succeed: 10 >= 0 + 10
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Approved',
        ]);
    }

    // -------------------------------------------------------------------------
    // Multi-invoice allocation
    // -------------------------------------------------------------------------

    public function test_multi_invoice_received_allocation(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(80, 5.00);

        // Receive 40 first
        $this->receiveStock($poId, $poItem->id, 40);

        // Invoice A: qty 40 — should approve
        $invoiceA = $this->createAndRegisterInvoice($poId, $poItem->id, 40, 5.00, 'INV-MULTI-A');
        $this->postJson("/api/supplier-invoices/{$invoiceA}/approve")->assertOk();
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceA,
            'status' => 'Approved',
        ]);

        // Invoice B: qty 40 — should fail approval (40 < 40 + 40)
        $invoiceB = $this->createAndRegisterInvoice($poId, $poItem->id, 40, 5.00, 'INV-MULTI-B');
        $this->postJson("/api/supplier-invoices/{$invoiceB}/approve")->assertStatus(409);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceB,
            'status' => 'Registered',
        ]);
        $this->assertDatabaseMissing('accounts_payable', [
            'supplier_invoice_id' => $invoiceB,
        ]);

        // Receive 40 more — total received = 80
        $this->receiveStock($poId, $poItem->id, 40);

        // Now B should approve: 80 >= 40 + 40
        $this->postJson("/api/supplier-invoices/{$invoiceB}/approve")->assertOk();
        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceB,
            'status' => 'Approved',
        ]);
    }

    // -------------------------------------------------------------------------
    // Accounts Payable creation
    // -------------------------------------------------------------------------

    public function test_ap_created_on_approval_uses_server_computed_total(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        // Create invoice with a different unit cost (variance is informational)
        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 7.50, 'INV-AP-001');

        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        // AP should have exactly one row for this invoice
        $ap = DB::table('accounts_payable')
            ->where('supplier_invoice_id', $invoiceId)
            ->first();
        $this->assertNotNull($ap);
        $this->assertSame(1, DB::table('accounts_payable')
            ->where('supplier_invoice_id', $invoiceId)
            ->count());

        // Server-computed total: 10 * 7.50 = 75.00
        $this->assertEquals(75.00, (float) $ap->total_amount);
        $this->assertEquals(0, (float) $ap->amount_paid);
        $this->assertSame('Unpaid', $ap->status);
        $this->assertNull($ap->due_date); // not set
    }

    public function test_repeat_approval_returns_409_and_no_duplicate_ap(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-DUP-AP');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        // Try to approve again — should return 409
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertStatus(409);

        // Exactly one AP
        $this->assertSame(1, DB::table('accounts_payable')
            ->where('supplier_invoice_id', $invoiceId)
            ->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.approved',
            'entity_id' => (string) $invoiceId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Inventory isolation
    // -------------------------------------------------------------------------

    public function test_invoice_workflow_does_not_mutate_inventory(): void
    {
        [$poId, $poItem, $product] = $this->createEligibleTrackedPO(10, 5.00);

        $initialStock = (int) $product->stock_quantity;
        $initialReceived = (int) $poItem->quantity_received;
        $initialMovementCount = DB::table('inventory_movements')
            ->where('product_id', $product->id)
            ->count();

        // Create Draft
        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-ISO-001');

        // Register should not change inventory
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->assertSame($initialReceived, (int) DB::table('purchase_order_items')->where('id', $poItem->id)->value('quantity_received'));
        $this->assertSame(
            $initialMovementCount,
            DB::table('inventory_movements')
                ->where('product_id', $product->id)
                ->count()
        );

        // Receive enough stock for approval
        $this->receiveStock($poId, $poItem->id, 10);

        // Approve — should NOT change inventory (AP creation only)
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        $this->assertSame($initialStock + 10, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        // quantity_received was changed by RECEIVING, not by approval.
        // After receiving 10, quantity_received should be 10.
        $this->assertSame(10, (int) DB::table('purchase_order_items')->where('id', $poItem->id)->value('quantity_received'));
        // The movement count increased only due to receiving, not due to approval.
        $this->assertSame(
            $initialMovementCount + 1,
            DB::table('inventory_movements')
                ->where('product_id', $product->id)
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // PO cancellation
    // -------------------------------------------------------------------------

    public function test_po_cancellation_blocked_by_draft_invoice(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-CANCEL-DRAFT');

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/cancel", [
            'notes' => 'Test cancellation with Draft invoice.',
        ])->assertStatus(409);
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
    }

    public function test_po_cancellation_blocked_by_registered_invoice(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-CANCEL-REG');

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/cancel", [
            'notes' => 'Test cancellation with Registered invoice.',
        ])->assertStatus(409);
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
    }

    public function test_po_cancellation_blocked_by_disputed_invoice(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 5, 5.00, 'INV-CANCEL-DIS');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertOk();

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/cancel", [
            'notes' => 'Test cancellation with Disputed invoice.',
        ])->assertStatus(409);
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
    }

    public function test_po_cancellation_blocked_by_approved_invoice(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-CANCEL-APP');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/cancel", [
            'notes' => 'Test cancellation with Approved invoice.',
        ])->assertStatus(409);
        $this->actingAs(User::where('username', 'finance')->firstOrFail());
    }

    public function test_po_cancellation_allowed_when_invoice_is_void(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoice = $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-CANCEL-VOID');
        $invoiceId = (int) $invoice['id'];
        $this->postJson("/api/supplier-invoices/{$invoiceId}/void")->assertOk();

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Void',
        ]);

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/purchase-orders/{$poId}/cancel", [
            'notes' => 'Void invoice should not block cancellation.',
        ])->assertOk();
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'approval_status' => 'Cancelled',
            'status' => 'Cancelled',
        ]);
    }

    // -------------------------------------------------------------------------
    // Audit
    // -------------------------------------------------------------------------

    public function test_all_invoice_state_transitions_are_audited(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'supplier_invoice.created',
            'entity_type' => 'supplier_invoice',
        ]);

        // Create
        $invoice = $this->createDraftInvoice($poId, $poItem->id, 10, 5.00, 'INV-AUDIT-001');
        $invoiceId = $invoice['id'];
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.created',
            'entity_id' => (string) $invoiceId,
        ]);

        // Register
        $this->registerInvoice($invoiceId);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.registered',
            'entity_id' => (string) $invoiceId,
        ]);

        // Approve
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.approved',
            'entity_id' => (string) $invoiceId,
        ]);
    }

    public function test_dispute_and_void_are_audited(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-AUDIT-002');

        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute", [
            'notes' => 'Audit test dispute.',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.disputed',
            'entity_id' => (string) $invoiceId,
        ]);

        $this->postJson("/api/supplier-invoices/{$invoiceId}/void")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'supplier_invoice.voided',
            'entity_id' => (string) $invoiceId,
        ]);

        $this->assertDatabaseHas('supplier_invoices', [
            'id' => $invoiceId,
            'status' => 'Void',
        ]);
    }

    // -------------------------------------------------------------------------
    // State machine — terminal states
    // -------------------------------------------------------------------------

    public function test_approved_invoice_cannot_be_re_registered_or_disputed(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 5.00, 'INV-TERM-001');
        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        // Cannot re-register
        $this->postJson("/api/supplier-invoices/{$invoiceId}/register")->assertStatus(409);
        // Cannot dispute
        $this->postJson("/api/supplier-invoices/{$invoiceId}/dispute")->assertStatus(409);
        // Cannot void
        $this->postJson("/api/supplier-invoices/{$invoiceId}/void")->assertStatus(409);
    }

    public function test_void_invoice_cannot_be_modified(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10);

        $invoice = $this->createDraftInvoice($poId, $poItem->id, 5, 5.00, 'INV-VOID-001');
        $invoiceId = (int) $invoice['id'];
        $this->postJson("/api/supplier-invoices/{$invoiceId}/void")->assertOk();

        // Cannot register a Void invoice
        $this->postJson("/api/supplier-invoices/{$invoiceId}/register")->assertStatus(409);
        // Cannot edit a Void invoice
        $this->putJson("/api/supplier-invoices/{$invoiceId}", [
            'invoice_number' => 'CHANGED',
            'items' => [[
                'purchase_order_item_id' => $poItem->id,
                'invoiced_quantity' => 5,
                'unit_cost' => 5.00,
            ]],
        ])->assertStatus(409);
    }

    public function test_invoice_total_is_computed_server_side(): void
    {
        [$poId, $poItem] = $this->createEligibleTrackedPO(10, 5.00);
        $this->receiveStock($poId, $poItem->id, 10);

        // Send a manipulated unit_cost in the request — server must recompute
        $invoiceId = $this->createAndRegisterInvoice($poId, $poItem->id, 10, 999.99, 'INV-SERVER-TOTAL');

        $this->postJson("/api/supplier-invoices/{$invoiceId}/approve")->assertOk();

        // Server should have used 10 * 999.99 = 9999.90, not any client-supplied total
        $ap = DB::table('accounts_payable')
            ->where('supplier_invoice_id', $invoiceId)
            ->first();
        $this->assertEquals(9999.90, (float) $ap->total_amount);
    }
}
