<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceSupplierCreateTest extends TestCase
{
    public function test_inventory_manager_can_create_a_supplier_and_assign_it_to_a_new_product(): void
    {
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $supplier = $this->postJson('/api/workspace/suppliers', [
            'name' => 'Brand New Trading',
            'contact_person' => 'Ana Reyes',
            'phone' => '0917 000 0000',
            'status' => 'Active',
        ])->assertCreated()->assertJsonPath('name', 'Brand New Trading');

        $this->assertDatabaseHas('suppliers', ['id' => $supplier->json('id'), 'name' => 'Brand New Trading']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'supplier.created')->exists());

        $this->getJson('/api/workspace/inventory')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Brand New Trading']);

        $this->postJson('/api/workspace/products', [
            'sku' => 'SUP-TEST-1',
            'name' => 'Supplier Linked Product',
            'category' => 'General',
            'price' => 50,
            'cost_price' => 30,
            'stock_quantity' => 5,
            'reorder_level' => 2,
            'unit' => 'pc',
            'supplier_id' => $supplier->json('id'),
            'status' => 'Active',
        ])->assertCreated()->assertJsonPath('supplier_id', $supplier->json('id'));
    }

    public function test_supplier_creation_requires_inventory_manage_and_a_name(): void
    {
        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $this->postJson('/api/workspace/suppliers', ['name' => 'Nope', 'status' => 'Active'])->assertForbidden();

        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $this->postJson('/api/workspace/suppliers', ['status' => 'Active'])->assertUnprocessable();
    }
}
