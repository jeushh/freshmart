<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryRoleWorkflowTest extends TestCase
{
    public function test_cashier_can_use_pos_but_only_sees_own_sales_and_refunds(): void
    {
        DB::table('sales_ledger')->delete();
        DB::table('refunds')->delete();
        $this->insertSale('CASHIER-ORDER', 'cashier');
        $this->insertSale('OTHER-ORDER', 'admin');
        $this->insertRefund('CASHIER-ORDER', 'cashier', 12.50);
        $this->insertRefund('OTHER-ORDER', 'admin', 40);

        $this->actingAs(User::where('username', 'cashier')->firstOrFail());

        $pos = $this->getJson('/api/workspace/pos')
            ->assertOk()
            ->assertJsonMissingPath('products.0.cost_price')
            ->assertJsonMissingPath('products.0.supplier_id')
            ->assertJsonMissingPath('products.0.reorder_level');
        $sales = $pos->json('sales');
        $this->assertSame(['CASHIER-ORDER'], collect($sales)->pluck('order_id')->unique()->values()->all());

        $dashboard = $this->getJson('/api/dashboard')->assertOk();
        $recentCashiers = collect($dashboard->json('sections.recent_sales'))
            ->pluck('cashier_username')
            ->unique()
            ->values()
            ->all();
        $this->assertSame(['cashier'], $recentCashiers);
        $refundMetric = collect($dashboard->json('metrics'))->firstWhere('key', 'month_refunds');
        $this->assertSame(12.5, (float) $refundMetric['value']);
    }

    public function test_cashier_is_forbidden_from_inventory_restock_procurement_and_reports(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $product = DB::table('products')->first();
        $this->actingAs($cashier);

        $this->getJson('/api/workspace/pos')->assertOk();
        $forbidden = $this->withHeader('X-Request-ID', 'cashier-forbidden-test')
            ->getJson('/api/restock-requests')
            ->assertForbidden()
            ->assertHeader('X-Request-ID', 'cashier-forbidden-test')
            ->assertJsonPath('request_id', 'cashier-forbidden-test');
        $this->assertSame(403, $forbidden->status());

        $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'priority' => 'Normal',
            'reason' => 'Cashier must not create this request.',
        ])->assertForbidden();
        $this->getJson('/api/workspace/inventory')->assertForbidden();
        $this->postJson('/api/workspace/products', [])->assertForbidden();
        $this->putJson("/api/workspace/products/{$product->id}", [])->assertForbidden();
        $this->postJson("/api/workspace/products/{$product->id}/adjust", [
            'quantity' => 1,
        ])->assertForbidden();
        $this->getJson('/api/purchase-orders')->assertForbidden();
        $this->postJson('/api/purchase-orders/999999/receive', [])->assertForbidden();
        $this->getJson('/api/reports/inventory')->assertForbidden();
        $this->getJson('/api/reports/procurement')->assertForbidden();
    }

    public function test_inventory_staff_can_monitor_stock_and_create_and_view_restock_requests(): void
    {
        $product = DB::table('products')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('restock_requests')
                ->whereColumn('restock_requests.product_id', 'products.id'))
            ->first();
        $this->assertNotNull($product);
        DB::table('products')->where('id', $product->id)->update([
            'stock_quantity' => $product->reorder_level,
        ]);
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $inventory = $this->getJson('/api/workspace/inventory')
            ->assertOk()
            ->assertJsonStructure([
                'products' => ['data'],
                'low_stock',
                'low_stock_products',
                'inventory_movements',
            ]);
        $this->assertNotEmpty($inventory->json('low_stock_products'));
        $this->assertContains(
            $product->id,
            collect($inventory->json('low_stock_products'))->pluck('id')->all(),
        );
        foreach ($inventory->json('low_stock_products') as $lowStockProduct) {
            $this->assertLessThanOrEqual(
                $lowStockProduct['reorder_level'],
                $lowStockProduct['stock_quantity'],
            );
        }
        $this->assertNotEmpty($inventory->json('inventory_movements'));

        $created = $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 25,
            'priority' => 'High',
            'reason' => 'Inventory staff identified replenishment demand.',
        ])->assertCreated()
            ->assertJsonPath('requested_by', 'inventory')
            ->assertJsonPath('status', 'Pending Approval');

        $this->getJson('/api/restock-requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $created->json('id')]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restock_request.created',
            'entity_id' => (string) $created->json('id'),
        ]);
    }

    public function test_inventory_staff_can_receive_an_approved_purchase_order(): void
    {
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $product = DB::table('products')->whereNotNull('supplier_id')->first();
        $this->actingAs($inventory);

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $product->supplier_id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => $product->cost_price,
            ]],
        ])->assertCreated();
        $purchaseOrderId = $order->json('order.id');
        $purchaseOrderItemId = $order->json('items.0.id');
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/submit", [])->assertOk();

        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/review", [
            'decision' => 'Approved',
        ])->assertOk();

        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $purchaseOrderItemId,
                'delivered_quantity' => 3,
                'damaged_quantity' => 0,
                'rejected_quantity' => 0,
            ]],
        ])->assertCreated()
            ->assertJsonPath('purchase_order_status', 'Fully Received');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'stock_receiving.created',
            'entity_type' => 'stock_receiving',
        ]);
    }

    public function test_operations_manager_retains_approval_without_request_or_inventory_write_access(): void
    {
        $product = DB::table('products')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('restock_requests')
                ->whereColumn('restock_requests.product_id', 'products.id'))
            ->first();
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();

        $this->actingAs($inventory);
        $requestId = $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 12,
            'priority' => 'Normal',
            'reason' => 'Approval boundary regression test.',
        ])->assertCreated()->json('id');
        $this->postJson("/api/restock-requests/{$requestId}/review", [
            'decision' => 'Approved',
        ])->assertForbidden();

        $this->actingAs($operations);
        $this->getJson('/api/restock-requests')->assertOk();
        $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 5,
            'priority' => 'Normal',
            'reason' => 'Operations must not originate restock requests.',
        ])->assertForbidden();
        $this->postJson("/api/restock-requests/{$requestId}/review", [
            'decision' => 'Approved',
            'notes' => 'Managerial approval retained.',
        ])->assertOk()
            ->assertJsonPath('status', 'Approved');
        $this->postJson("/api/workspace/products/{$product->id}/adjust", [
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_role_seeding_applies_least_privilege_without_altering_products(): void
    {
        $productsBefore = DB::table('products')
            ->orderBy('id')
            ->get()
            ->map(fn ($product) => (array) $product)
            ->all();

        app(RoleSeeder::class)->run();

        $productsAfter = DB::table('products')
            ->orderBy('id')
            ->get()
            ->map(fn ($product) => (array) $product)
            ->all();
        $this->assertSame($productsBefore, $productsAfter);
        $this->assertSame(
            ['pos.access', 'pos.refund'],
            $this->permissionsFor('Cashier'),
        );
        $this->assertSame([
            'inventory.manage',
            'restock.request',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.manage',
            'procurement.stock.receive',
            'reports.inventory.view',
            'reports.procurement.view',
            'reports.export',
        ], $this->permissionsFor('Inventory Staff'));
        $this->assertSame([
            'restock.approve',
            'sales.view',
            'procurement.purchase_orders.view',
            'procurement.purchase_orders.approve',
            'reports.inventory.view',
            'reports.procurement.view',
            'reports.export',
        ], $this->permissionsFor('Operations Manager'));
    }

    private function permissionsFor(string $role): array
    {
        return json_decode(
            DB::table('roles')->where('name', $role)->value('permissions'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function insertSale(string $orderId, string $cashier): void
    {
        DB::table('sales_ledger')->insert([
            'order_id' => $orderId,
            'item_sku' => 'SCOPE-TEST',
            'quantity_sold' => 1,
            'total_price' => 25,
            'payment_method' => 'Cash',
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'cashier_username' => $cashier,
        ]);
    }

    private function insertRefund(string $orderId, string $cashier, float $amount): void
    {
        DB::table('refunds')->insert([
            'order_id' => $orderId,
            'item_sku' => 'SCOPE-TEST',
            'quantity_refunded' => 1,
            'refund_amount' => $amount,
            'reason' => 'Permission scope test',
            'processed_by' => $cashier,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
