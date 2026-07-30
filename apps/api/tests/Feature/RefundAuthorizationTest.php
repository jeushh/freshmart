<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundAuthorizationTest extends TestCase
{
    public function test_cashier_can_refund_own_sale(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $product = DB::table('products')->where('stock_quantity', '>=', 2)->first();
        $initialStock = $product->stock_quantity;
        $this->actingAs($cashier);
        $orderId = $this->checkout($product->id, 2);

        $refund = $this->postJson('/api/workspace/pos/refunds', [
            'order_id' => $orderId,
            'item_sku' => $product->sku,
            'quantity' => 1,
            'reason' => 'Customer returned one item.',
        ])->assertCreated()
            ->assertJsonPath('sale_owner', 'cashier')
            ->assertJsonPath('processed_by', 'cashier')
            ->assertJsonPath('quantity_refunded', 1);

        $this->assertSame(
            $initialStock - 1,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
        $this->assertDatabaseHas('inventory_movements', [
            'movement_type' => 'Refund',
            'reference_id' => $orderId,
            'performed_by' => 'cashier',
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'transaction_type' => 'Refund',
            'direction' => 'Out',
            'reference_type' => 'refund',
            'reference_id' => (string) $refund->json('id'),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'refund.completed',
            'entity_id' => (string) $refund->json('id'),
        ]);
    }

    public function test_cashier_cannot_refund_another_cashiers_sale(): void
    {
        $cashierA = User::where('username', 'cashier')->firstOrFail();
        $cashierB = $this->createCashier('cashier-b');
        $product = DB::table('products')->where('stock_quantity', '>', 0)->first();

        $this->actingAs($cashierB);
        $orderId = $this->checkout($product->id, 1);
        $stockAfterSale = DB::table('products')->where('id', $product->id)->value('stock_quantity');

        $this->actingAs($cashierA);
        $this->postJson('/api/workspace/pos/refunds', [
            'order_id' => $orderId,
            'item_sku' => $product->sku,
            'quantity' => 1,
            'reason' => 'Cross-cashier refund attempt.',
        ])->assertForbidden();

        $this->assertDatabaseMissing('refunds', ['order_id' => $orderId]);
        $this->assertSame(
            $stockAfterSale,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
    }

    public function test_manager_with_refund_and_organization_sales_permissions_can_refund_any_sale(): void
    {
        $cashierB = $this->createCashier('cashier-manager-test');
        $product = DB::table('products')->where('stock_quantity', '>', 0)->first();
        $this->actingAs($cashierB);
        $orderId = $this->checkout($product->id, 1);

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson('/api/workspace/pos/refunds', [
            'order_id' => $orderId,
            'item_sku' => $product->sku,
            'quantity' => 1,
            'reason' => 'Read permission must not grant refund mutation.',
        ])->assertForbidden();

        $manager = $this->createUserWithPermissions(
            'refund-manager',
            ['pos.refund', 'sales.view'],
        );
        $this->actingAs($manager);
        $this->postJson('/api/workspace/pos/refunds', [
            'order_id' => $orderId,
            'item_sku' => $product->sku,
            'quantity' => 1,
            'reason' => 'Manager-approved customer refund.',
        ])->assertCreated()
            ->assertJsonPath('sale_owner', 'cashier-manager-test')
            ->assertJsonPath('processed_by', 'refund-manager');
    }

    public function test_refunds_cannot_exceed_the_unrefunded_sale_quantity(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $product = DB::table('products')->where('stock_quantity', '>', 0)->first();
        $this->actingAs($cashier);
        $orderId = $this->checkout($product->id, 1);

        $payload = [
            'order_id' => $orderId,
            'item_sku' => $product->sku,
            'quantity' => 1,
            'reason' => 'Full item refund.',
        ];
        $this->postJson('/api/workspace/pos/refunds', $payload)->assertCreated();
        $this->postJson('/api/workspace/pos/refunds', $payload)->assertStatus(409);
        $this->assertSame(
            1,
            DB::table('refunds')
                ->where('order_id', $orderId)
                ->where('item_sku', $product->sku)
                ->sum('quantity_refunded'),
        );
    }

    public function test_refund_uses_sale_product_id_when_sku_is_reused(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        $product = DB::table('products')->where('stock_quantity', '>', 0)->first();
        $originalSku = $product->sku;
        $originalStock = $product->stock_quantity;
        $replacementStock = 9;
        $this->actingAs($cashier);
        $orderId = $this->checkout($product->id, 1);

        $this->assertDatabaseHas('sales_ledger', [
            'order_id' => $orderId,
            'item_sku' => $originalSku,
            'product_id' => $product->id,
        ]);
        DB::table('products')->where('id', $product->id)->update([
            'sku' => "ARCHIVED-{$product->id}",
            'status' => 'Inactive',
        ]);
        $replacementId = DB::table('products')->insertGetId([
            'name' => "Replacement {$product->name}",
            'sku' => $originalSku,
            'price' => $product->price,
            'category' => $product->category,
            'stock_quantity' => $replacementStock,
            'unit' => $product->unit,
            'emoji' => $product->emoji,
            'cost_price' => $product->cost_price,
            'reorder_level' => $product->reorder_level,
            'min_stock' => $product->min_stock,
            'max_stock' => $product->max_stock,
            'supplier_id' => $product->supplier_id,
            'status' => 'Active',
        ]);

        $this->postJson('/api/workspace/pos/refunds', [
            'order_id' => $orderId,
            'item_sku' => $originalSku,
            'quantity' => 1,
            'reason' => 'Refund after the original SKU was reused.',
        ])->assertCreated();

        $this->assertSame(
            $originalStock,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
        $this->assertSame(
            $replacementStock,
            DB::table('products')->where('id', $replacementId)->value('stock_quantity'),
        );
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'movement_type' => 'Refund',
            'reference_id' => $orderId,
        ]);
        $this->assertDatabaseMissing('inventory_movements', [
            'product_id' => $replacementId,
            'movement_type' => 'Refund',
            'reference_id' => $orderId,
        ]);
    }

    private function checkout(int $productId, int $quantity): string
    {
        return $this->postJson('/api/workspace/pos/checkout', [
            'items' => [[
                'product_id' => $productId,
                'quantity' => $quantity,
            ]],
            'payment_method' => 'Cash',
        ])->assertOk()->json('order_id');
    }

    private function createCashier(string $username): User
    {
        $id = DB::table('admin_users')->insertGetId([
            'username' => $username,
            'password_hash' => 'not-used',
            'full_name' => $username,
            'role_id' => DB::table('roles')->where('name', 'Cashier')->value('id'),
            'status' => 'Active',
        ]);

        return User::findOrFail($id);
    }

    private function createUserWithPermissions(string $username, array $permissions): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => "{$username} role",
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'landing_page' => 'dashboard',
            'is_system' => 0,
        ]);
        $id = DB::table('admin_users')->insertGetId([
            'username' => $username,
            'password_hash' => 'not-used',
            'full_name' => $username,
            'role_id' => $roleId,
            'status' => 'Active',
        ]);

        return User::findOrFail($id);
    }
}
