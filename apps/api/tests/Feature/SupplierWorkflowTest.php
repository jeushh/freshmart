<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierWorkflowTest extends TestCase
{
    public function test_po_creation_initializes_supplier_status_as_not_sent_and_ignores_client_injection(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        $this->actingAs($inventory);

        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'supplier_status' => 'Accepted',
            'sent_by' => 'hacker',
            'sent_to_supplier_at' => '2020-01-01 00:00:00',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated()
            ->assertJsonPath('order.approval_status', 'Draft')
            ->assertJsonPath('order.status', 'Pending')
            ->assertJsonPath('order.supplier_status', 'Not Sent')
            ->assertJsonPath('order.sent_by', null);

        $orderId = $response->json('order.id');

        $this->putJson("/api/purchase-orders/{$orderId}", [
            'supplier_id' => $product->supplier_id,
            'supplier_status' => 'Accepted',
            'sent_by' => 'hacker',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 6,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertOk()
            ->assertJsonPath('order.supplier_status', 'Not Sent')
            ->assertJsonPath('order.sent_by', null);
    }

    public function test_rbac_enforcement_for_supplier_actions(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();

        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        // 1. Inventory Staff creates and submits PO
        $this->actingAs($inventory);
        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $orderId = $order->json('order.id');
        $this->postJson("/api/purchase-orders/{$orderId}/submit")->assertOk();

        // 2. Inventory Staff cannot internally approve PO
        $this->postJson("/api/purchase-orders/{$orderId}/review", ['decision' => 'Approved'])->assertForbidden();

        // 3. Operations Manager internally approves PO
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$orderId}/review", ['decision' => 'Approved'])->assertOk();

        // 4. Operations Manager cannot mark sent or record supplier response
        $this->postJson("/api/purchase-orders/{$orderId}/send")->assertForbidden();
        $this->postJson("/api/purchase-orders/{$orderId}/supplier-response", ['response' => 'Accepted'])->assertForbidden();

        // 5. Operations Manager cannot adjust stock
        $this->postJson("/api/workspace/products/{$product->id}/adjust", ['quantity' => 1])->assertForbidden();

        // 6. Cashier cannot perform send or supplier-response
        $this->actingAs($cashier);
        $this->postJson("/api/purchase-orders/{$orderId}/send")->assertForbidden();
        $this->postJson("/api/purchase-orders/{$orderId}/supplier-response", ['response' => 'Accepted'])->assertForbidden();

        // 7. Inventory Staff CAN mark sent and record supplier response
        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$orderId}/send")->assertOk();
        $this->postJson("/api/purchase-orders/{$orderId}/supplier-response", ['response' => 'Accepted'])->assertOk();
    }

    public function test_restock_request_status_timing_and_supplier_rejection_release(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();

        // Find product without active restock request
        $product = DB::table('products')
            ->whereNotNull('supplier_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('restock_requests')
                ->whereColumn('restock_requests.product_id', 'products.id'))
            ->first();

        // Create and approve restock request
        $this->actingAs($inventory);
        $rr = $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'priority' => 'High',
            'reason' => 'Stock timing verification.',
        ])->assertCreated();
        $rrId = $rr->json('id');

        $this->actingAs($operations);
        $this->postJson("/api/restock-requests/{$rrId}/review", ['decision' => 'Approved'])->assertOk();

        // Inventory Staff creates PO linked to restock request
        $this->actingAs($inventory);
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'restock_request_id' => $rrId,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $poId = $po->json('order.id');
        $poItemId = $po->json('items.0.id');

        $this->assertDatabaseHas('restock_requests', [
            'id' => $rrId,
            'status' => 'Purchase Order Created',
            'purchase_order_id' => $poId,
        ]);

        // Submit PO
        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();

        // Operations approves PO internally -> Restock request must STILL be 'Purchase Order Created'
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();

        $this->assertDatabaseHas('restock_requests', [
            'id' => $rrId,
            'status' => 'Purchase Order Created',
            'purchase_order_id' => $poId,
        ]);

        // Inventory Staff marks PO Sent to supplier -> Restock request becomes 'Ordered'
        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();

        $this->assertDatabaseHas('restock_requests', [
            'id' => $rrId,
            'status' => 'Ordered',
            'purchase_order_id' => $poId,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'Ordered',
            'supplier_status' => 'Sent',
        ]);

        // Supplier rejects PO -> PO becomes Cancelled (approval_status stays Approved), Restock request released to Approved (purchase_order_id NULL)
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", [
            'response' => 'Rejected',
            'notes' => 'Out of stock at supplier warehouse.',
        ])->assertOk()
            ->assertJsonPath('order.approval_status', 'Approved')
            ->assertJsonPath('order.status', 'Cancelled')
            ->assertJsonPath('order.supplier_status', 'Rejected');

        $this->assertDatabaseHas('restock_requests', [
            'id' => $rrId,
            'status' => 'Approved',
            'purchase_order_id' => null,
        ]);
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => 1,
            ]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase_order.supplier_rejected',
            'entity_id' => (string) $poId,
        ]);
    }

    public function test_state_machine_invalid_transitions_return_409_conflict(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        $this->actingAs($inventory);
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $poId = $po->json('order.id');

        // 1. Send Draft PO -> 409
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertStatus(409);

        // 2. Submit PO & Send Submitted PO -> 409
        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertStatus(409);

        // 3. Record supplier response on un-sent PO -> 409
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();

        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", ['response' => 'Accepted'])->assertStatus(409);

        // 4. Mark Sent -> Now Sent
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();

        // 5. Receiving on 'Sent' PO (before Accepted) -> 409
        $poItemId = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->value('id');
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => 4,
            ]],
        ])->assertStatus(409);

        // 6. Record Supplier Acceptance
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", [
            'response' => 'Accepted',
            'supplier_reference' => 'SO-TEST-123',
        ])->assertOk();

        // 7. Second supplier response on Accepted PO -> 409
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", ['response' => 'Accepted'])->assertStatus(409);

        // 8. Send Accepted PO again -> 409
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertStatus(409);
    }

    public function test_supplier_rejection_blocked_if_receiving_history_exists(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        $this->actingAs($inventory);
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $poId = $po->json('order.id');
        $poItemId = $po->json('items.0.id');

        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();

        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", ['response' => 'Accepted'])->assertOk();

        // Perform partial receiving
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => 5,
            ]],
        ])->assertCreated();

        // Attempt supplier rejection when receiving history exists -> 409
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", ['response' => 'Rejected'])->assertStatus(409);
    }

    public function test_stock_mutation_isolation_and_receiving_quantities(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();
        $initialStock = (int) $product->stock_quantity;

        // 1. Create PO -> Stock unchanged
        $this->actingAs($inventory);
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 10,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $poId = $po->json('order.id');
        $poItemId = $po->json('items.0.id');
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // 2. Submit PO -> Stock unchanged
        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // 3. Internal Approval -> Stock unchanged
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // 4. Mark Sent -> Stock unchanged
        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // 5. Supplier Accepted -> Stock unchanged
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", ['response' => 'Accepted'])->assertOk();
        $this->assertSame($initialStock, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));

        // 6. Receive with 5 delivered, 1 damaged, 1 rejected -> Accepted = 3
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => 5,
                'damaged_quantity' => 1,
                'rejected_quantity' => 1,
            ]],
        ])->assertCreated();

        // Stock increases strictly by 3 (accepted quantity)
        $this->assertSame($initialStock + 3, (int) DB::table('products')->where('id', $product->id)->value('stock_quantity'));
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'Receiving',
            'quantity' => 3,
            'previous_stock' => $initialStock,
            'new_stock' => $initialStock + 3,
        ]);
    }

    public function test_legacy_null_supplier_status_po_remains_receivable(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        // Insert historical PO directly with supplier_status = NULL and status = 'Approved' / approval_status = 'Approved'
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-HISTORICAL-001',
            'supplier_id' => $product->supplier_id,
            'status' => 'Approved',
            'approval_status' => 'Approved',
            'supplier_status' => null,
            'quantity_ordered' => 5,
            'created_by' => 'legacy',
        ]);
        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'quantity_ordered' => 5,
            'quantity_received' => 0,
            'unit_cost' => $product->cost_price,
        ]);

        $this->actingAs($inventory);

        // Receiving should succeed for historical NULL supplier_status PO
        $this->postJson("/api/purchase-orders/{$poId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $poItemId,
                'delivered_quantity' => 5,
            ]],
        ])->assertCreated()
            ->assertJsonPath('purchase_order_status', 'Fully Received');
    }

    public function test_audit_logs_and_supplier_status_filtering(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();

        $this->actingAs($inventory);
        $po = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $poId = $po->json('order.id');

        $this->postJson("/api/purchase-orders/{$poId}/submit")->assertOk();
        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$poId}/review", ['decision' => 'Approved'])->assertOk();

        // 1. Mark Sent -> Audit log 'purchase_order.sent_to_supplier'
        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$poId}/send")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase_order.sent_to_supplier',
            'entity_id' => (string) $poId,
        ]);

        // Filter by supplier_status=Sent
        $listSent = $this->getJson('/api/purchase-orders?supplier_status=Sent')->assertOk();
        $this->assertContains($poId, collect($listSent->json('orders.data'))->pluck('id')->all());

        // 2. Record Acceptance -> Audit log 'purchase_order.supplier_accepted'
        $this->postJson("/api/purchase-orders/{$poId}/supplier-response", [
            'response' => 'Accepted',
            'supplier_reference' => 'REF-999',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase_order.supplier_accepted',
            'entity_id' => (string) $poId,
        ]);

        // Filter by supplier_status=Accepted
        $listAccepted = $this->getJson('/api/purchase-orders?supplier_status=Accepted')->assertOk();
        $this->assertContains($poId, collect($listAccepted->json('orders.data'))->pluck('id')->all());
    }
}
