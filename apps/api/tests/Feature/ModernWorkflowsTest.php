<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModernWorkflowsTest extends TestCase
{
    public function test_workflow_endpoints_require_their_specific_permissions(): void
    {
        $this->actingAs(User::where('username', 'employee')->firstOrFail());

        $this->getJson('/api/roles')->assertForbidden();
        $this->getJson('/api/settings')->assertForbidden();
        $this->getJson('/api/hr/requests')->assertForbidden();
        $this->getJson('/api/restock-requests')->assertForbidden();
        $this->getJson('/api/purchase-orders')->assertForbidden();
    }

    public function test_hr_request_view_and_approval_permissions_are_not_interchangeable(): void
    {
        $requestId = $this->createHrRequest(1, 'Other', null, null);
        $this->actingAs($this->userWithPermissions(
            'hr-view-only',
            ['hr.requests.view'],
        ));

        $this->getJson('/api/hr/requests')->assertOk();
        $this->postJson("/api/hr/requests/{$requestId}/review", [
            'decision' => 'Rejected',
        ])->assertForbidden();

        $this->actingAs($this->userWithPermissions(
            'hr-approval-only',
            ['hr.requests.approve'],
        ));
        $this->getJson('/api/hr/requests')->assertForbidden();
        $this->postJson("/api/hr/requests/{$requestId}/review", [
            'decision' => 'Rejected',
        ])->assertOk();
    }

    public function test_role_permissions_persist_and_role_management_cannot_be_locked_out(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());
        $cashier = DB::table('roles')->where('name', 'Cashier')->first();
        $permissions = json_decode($cashier->permissions, true, flags: JSON_THROW_ON_ERROR);
        $permissions[] = 'system.audit.view';

        $this->putJson("/api/roles/{$cashier->id}", [
            'name' => $cashier->name,
            'description' => 'Updated cashier permissions.',
            'landing_page' => $cashier->landing_page,
            'permissions' => $permissions,
        ])->assertOk()
            ->assertJsonPath('description', 'Updated cashier permissions.');

        $refresh = $this->getJson('/api/roles?per_page=100')->assertOk();
        $saved = collect($refresh->json('roles.data'))->firstWhere('id', $cashier->id);
        $this->assertContains('system.audit.view', $saved['permissions']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.updated',
            'entity_id' => (string) $cashier->id,
        ]);

        $adminRole = DB::table('roles')->where('name', 'System Administrator')->first();
        $adminPermissions = array_values(array_filter(
            json_decode($adminRole->permissions, true, flags: JSON_THROW_ON_ERROR),
            fn (string $permission) => $permission !== 'system.roles.manage',
        ));
        $this->putJson("/api/roles/{$adminRole->id}", [
            'name' => $adminRole->name,
            'description' => $adminRole->description,
            'landing_page' => $adminRole->landing_page,
            'permissions' => $adminPermissions,
        ])->assertUnprocessable();

        $this->putJson("/api/roles/{$cashier->id}", [
            'name' => 'Renamed Cashier',
            'description' => $cashier->description,
            'landing_page' => $cashier->landing_page,
            'permissions' => $permissions,
        ])->assertUnprocessable();
    }

    public function test_custom_roles_may_have_an_empty_permission_set(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->postJson('/api/roles', [
            'name' => 'Read Nothing',
            'description' => 'A deliberately unprivileged custom role.',
            'landing_page' => 'dashboard',
            'permissions' => [],
        ])->assertCreated()
            ->assertJsonPath('is_system', false)
            ->assertJsonPath('permissions', []);
    }

    public function test_settings_are_allowlisted_validated_persisted_and_audited(): void
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertJsonMissing(['APP_KEY'])
            ->assertJsonMissing(['DB_PASSWORD']);

        $this->putJson('/api/settings', [
            'settings' => [
                'business_name' => 'FreshMart North',
                'default_tax_rate' => 12.5,
                'low_stock_alert_enabled' => false,
            ],
        ])->assertOk();

        $this->assertSame(
            'FreshMart North',
            DB::table('system_settings')->where('setting_key', 'business_name')->value('setting_value'),
        );
        $this->assertSame(
            '12.5',
            DB::table('system_settings')->where('setting_key', 'default_tax_rate')->value('setting_value'),
        );
        $this->assertSame(
            '0',
            DB::table('system_settings')->where('setting_key', 'low_stock_alert_enabled')->value('setting_value'),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->putJson('/api/settings', [
            'settings' => ['APP_KEY' => 'must-not-be-written'],
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'APP_KEY']);
    }

    public function test_hr_leave_approval_decrements_balance_once_and_rejection_does_not(): void
    {
        $employeeId = DB::table('employees')->where('employee_no', 'EMP-0001')->value('id');
        $initialBalance = (float) DB::table('employees')->where('id', $employeeId)->value('leave_balance');
        $approvedId = $this->createHrRequest($employeeId, 'Leave', '2030-01-10', '2030-01-12');
        $rejectedId = $this->createHrRequest($employeeId, 'Leave', '2030-02-01', '2030-02-02');
        $reviewer = User::where('username', 'hr')->firstOrFail();
        $this->actingAs($reviewer);

        $this->postJson("/api/hr/requests/{$approvedId}/review", [
            'decision' => 'Approved',
            'notes' => 'Coverage confirmed.',
        ])->assertOk()
            ->assertJsonPath('status', 'Approved')
            ->assertJsonPath('reviewed_by', $reviewer->id);
        $this->assertEquals(
            $initialBalance - 3,
            (float) DB::table('employees')->where('id', $employeeId)->value('leave_balance'),
        );

        $this->postJson("/api/hr/requests/{$approvedId}/review", [
            'decision' => 'Approved',
        ])->assertStatus(409);
        $this->postJson("/api/hr/requests/{$rejectedId}/review", [
            'decision' => 'Rejected',
        ])->assertOk();
        $this->assertEquals(
            $initialBalance - 3,
            (float) DB::table('employees')->where('id', $employeeId)->value('leave_balance'),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_request.approved',
            'entity_id' => (string) $approvedId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'hr_request.rejected',
            'entity_id' => (string) $rejectedId,
        ]);
    }

    public function test_restock_request_creation_approval_and_duplicate_guards(): void
    {
        $product = DB::table('products')->where('sku', 'FRU-001')->first();
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());
        $response = $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 25,
            'priority' => 'High',
            'reason' => 'Forecasted weekend demand.',
        ])->assertCreated()
            ->assertJsonPath('status', 'Pending Approval')
            ->assertJsonPath('current_stock', $product->stock_quantity);
        $requestId = $response->json('id');

        $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 10,
            'priority' => 'Normal',
            'reason' => 'Duplicate active request.',
        ])->assertStatus(409);

        $this->actingAs(User::where('username', 'operations')->firstOrFail());
        $this->postJson("/api/restock-requests/{$requestId}/review", [
            'decision' => 'Approved',
            'notes' => 'Supplier capacity checked.',
        ])->assertOk()
            ->assertJsonPath('status', 'Approved');
        $this->postJson("/api/restock-requests/{$requestId}/review", [
            'decision' => 'Rejected',
        ])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restock_request.created',
            'entity_id' => (string) $requestId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'restock_request.approved',
            'entity_id' => (string) $requestId,
        ]);
    }

    public function test_purchase_order_totals_relationships_permissions_and_transitions(): void
    {
        $products = DB::table('products')
            ->whereIn('sku', ['FRU-001', 'FRU-002'])
            ->orderBy('sku')
            ->get();
        $inventory = User::where('username', 'inventory')->firstOrFail();
        $operations = User::where('username', 'operations')->firstOrFail();
        $this->actingAs($inventory);

        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $products[0]->supplier_id,
            'notes' => 'Two-line test order.',
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 10, 'unit_cost' => 2.50],
                ['product_id' => $products[1]->id, 'quantity' => 4, 'unit_cost' => 3],
            ],
        ])->assertCreated()
            ->assertJsonPath('order.approval_status', 'Draft');
        $purchaseOrderId = $response->json('order.id');
        $this->assertEquals(37, (float) $response->json('order.total_amount'));

        $otherSupplierId = DB::table('suppliers')
            ->where('id', '!=', $products[0]->supplier_id)
            ->value('id');
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $otherSupplierId,
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 1, 'unit_cost' => 1],
            ],
        ])->assertUnprocessable();

        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/review", [
            'decision' => 'Approved',
        ])->assertStatus(409);

        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/submit", [])
            ->assertOk()
            ->assertJsonPath('order.approval_status', 'Submitted');

        $this->actingAs($operations);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/review", [
            'decision' => 'Approved',
            'notes' => 'Budget confirmed.',
        ])->assertOk()
            ->assertJsonPath('order.approval_status', 'Approved')
            ->assertJsonPath('order.status', 'Approved');
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/review", [
            'decision' => 'Rejected',
        ])->assertStatus(409);

        $this->actingAs($inventory);
        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/cancel", [])
            ->assertForbidden();
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrderId,
            'approval_status' => 'Approved',
        ]);
        foreach (['purchase_order.created', 'purchase_order.submitted', 'purchase_order.approved'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'entity_id' => (string) $purchaseOrderId,
            ]);
        }
    }

    public function test_only_accepted_units_fulfill_an_order_and_replacements_complete_it(): void
    {
        [$purchaseOrderId, $line, $product] = $this->createApprovedPurchaseOrder(10, 5);
        $initialStock = $product->stock_quantity;
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $first = $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'invoice_number' => 'INV-TEST-001',
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'delivered_quantity' => 10,
                'damaged_quantity' => 0,
                'rejected_quantity' => 3,
            ]],
        ])->assertCreated()
            ->assertJsonPath('purchase_order_status', 'Partially Received')
            ->assertJsonPath('total_purchase_cost', 35)
            ->assertJsonPath('lines.0.delivered_quantity', 10)
            ->assertJsonPath('lines.0.accepted_quantity', 7)
            ->assertJsonPath('lines.0.fulfilled_quantity', 7)
            ->assertJsonPath('lines.0.damaged_quantity', 0)
            ->assertJsonPath('lines.0.rejected_quantity', 3)
            ->assertJsonPath('lines.0.previous_stock', $initialStock)
            ->assertJsonPath('lines.0.new_stock', $initialStock + 7);
        $this->assertSame(
            $initialStock + 7,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $line->id,
            'quantity_received' => 7,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrderId,
            'status' => 'Partially Received',
        ]);
        $this->assertDatabaseHas('stock_receiving_items', [
            'stock_receiving_id' => $first->json('stock_receiving_id'),
            'received_quantity' => 10,
            'damaged_quantity' => 0,
            'rejected_quantity' => 3,
        ]);
        $this->assertSame(
            35.0,
            (float) DB::table('accounts_payable')
                ->where('purchase_order_id', $purchaseOrderId)
                ->value('total_amount'),
        );
        $detail = $this->getJson("/api/purchase-orders/{$purchaseOrderId}")
            ->assertOk();
        $detail->assertJsonPath('items.0.fulfilled_quantity', 7)
            ->assertJsonPath('items.0.outstanding_quantity', 3)
            ->assertJsonPath('receivings.0.items.0.delivered_quantity', 10)
            ->assertJsonPath('receivings.0.items.0.accepted_quantity', 7)
            ->assertJsonPath('receivings.0.items.0.fulfilled_quantity', 7);

        $firstAudit = json_decode(
            DB::table('audit_logs')
                ->where('action', 'stock_receiving.created')
                ->where('entity_id', (string) $first->json('stock_receiving_id'))
                ->value('details'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame([
            'sku' => $product->sku,
            'delivered_quantity' => 10,
            'accepted_quantity' => 7,
            'fulfilled_quantity' => 7,
            'damaged_quantity' => 0,
            'rejected_quantity' => 3,
            'previous_stock' => $initialStock,
            'new_stock' => $initialStock + 7,
        ], $firstAudit['lines'][0]);

        $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'delivered_quantity' => 4,
            ]],
        ])->assertStatus(409);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $line->id,
            'quantity_received' => 7,
        ]);

        $second = $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'delivered_quantity' => 4,
                'damaged_quantity' => 1,
            ]],
        ])->assertCreated()
            ->assertJsonPath('purchase_order_status', 'Fully Received')
            ->assertJsonPath('total_purchase_cost', 15)
            ->assertJsonPath('lines.0.delivered_quantity', 4)
            ->assertJsonPath('lines.0.accepted_quantity', 3)
            ->assertJsonPath('lines.0.fulfilled_quantity', 3)
            ->assertJsonPath('lines.0.damaged_quantity', 1)
            ->assertJsonPath('lines.0.rejected_quantity', 0);
        $this->assertSame(
            $initialStock + 10,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrderId,
            'status' => 'Fully Received',
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $line->id,
            'quantity_received' => 10,
        ]);
        $this->assertDatabaseHas('stock_receiving_items', [
            'stock_receiving_id' => $second->json('stock_receiving_id'),
            'received_quantity' => 4,
            'damaged_quantity' => 1,
            'rejected_quantity' => 0,
        ]);
        $secondAudit = json_decode(
            DB::table('audit_logs')
                ->where('action', 'stock_receiving.created')
                ->where('entity_id', (string) $second->json('stock_receiving_id'))
                ->value('details'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(4, $secondAudit['lines'][0]['delivered_quantity']);
        $this->assertSame(3, $secondAudit['lines'][0]['accepted_quantity']);
        $this->assertSame(3, $secondAudit['lines'][0]['fulfilled_quantity']);
        $this->assertSame(1, $secondAudit['lines'][0]['damaged_quantity']);
        $this->assertSame(0, $secondAudit['lines'][0]['rejected_quantity']);
        $this->assertSame($initialStock + 7, $secondAudit['lines'][0]['previous_stock']);
        $this->assertSame($initialStock + 10, $secondAudit['lines'][0]['new_stock']);
        $this->assertSame(
            10,
            DB::table('inventory_movements')
                ->where('reference_id', DB::table('purchase_orders')->where('id', $purchaseOrderId)->value('po_number'))
                ->sum('quantity'),
        );
        $this->assertSame(
            50.0,
            (float) DB::table('accounts_payable')
                ->where('purchase_order_id', $purchaseOrderId)
                ->value('total_amount'),
        );
        $this->assertSame(
            50.0,
            (float) DB::table('financial_transactions')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $purchaseOrderId)
                ->sum('amount'),
        );
        $this->assertSame(
            14,
            DB::table('stock_receiving_items')
                ->join(
                    'stock_receivings',
                    'stock_receiving_items.stock_receiving_id',
                    '=',
                    'stock_receivings.id',
                )
                ->where('stock_receivings.purchase_order_id', $purchaseOrderId)
                ->sum('stock_receiving_items.received_quantity'),
        );
        $this->assertSame(
            2,
            DB::table('audit_logs')
                ->where('action', 'stock_receiving.created')
                ->whereJsonContains('details->purchase_order_id', $purchaseOrderId)
                ->count(),
        );
    }

    public function test_rejected_deliveries_alone_cannot_fulfill_a_purchase_order(): void
    {
        [$purchaseOrderId, $line, $product] = $this->createApprovedPurchaseOrder(10, 5);
        $initialStock = $product->stock_quantity;
        $poNumber = DB::table('purchase_orders')->where('id', $purchaseOrderId)->value('po_number');
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $response = $this->postJson("/api/purchase-orders/{$purchaseOrderId}/receive", [
            'items' => [[
                'purchase_order_item_id' => $line->id,
                'delivered_quantity' => 10,
                'rejected_quantity' => 10,
            ]],
        ])->assertCreated()
            ->assertJsonPath('purchase_order_status', 'Partially Received')
            ->assertJsonPath('total_purchase_cost', 0)
            ->assertJsonPath('lines.0.delivered_quantity', 10)
            ->assertJsonPath('lines.0.accepted_quantity', 0)
            ->assertJsonPath('lines.0.fulfilled_quantity', 0)
            ->assertJsonPath('lines.0.rejected_quantity', 10)
            ->assertJsonPath('lines.0.previous_stock', $initialStock)
            ->assertJsonPath('lines.0.new_stock', $initialStock);

        $this->assertDatabaseHas('purchase_order_items', [
            'id' => $line->id,
            'quantity_received' => 0,
        ]);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $purchaseOrderId,
            'status' => 'Partially Received',
        ]);
        $this->assertDatabaseHas('stock_receiving_items', [
            'stock_receiving_id' => $response->json('stock_receiving_id'),
            'received_quantity' => 10,
            'rejected_quantity' => 10,
        ]);
        $this->assertSame(
            $initialStock,
            DB::table('products')->where('id', $product->id)->value('stock_quantity'),
        );
        $this->assertDatabaseMissing('accounts_payable', [
            'purchase_order_id' => $purchaseOrderId,
        ]);
        $this->assertSame(
            0,
            DB::table('financial_transactions')
                ->where('reference_type', 'purchase_order')
                ->where('reference_id', (string) $purchaseOrderId)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table('inventory_movements')->where('reference_id', $poNumber)->count(),
        );
    }

    public function test_seeded_roles_only_contain_catalogued_permissions(): void
    {
        $catalog = PermissionCatalog::all();

        foreach (DB::table('roles')->where('is_system', 1)->get() as $role) {
            foreach (json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR) as $permission) {
                $this->assertContains($permission, $catalog, "{$role->name} has an unknown permission.");
            }
        }
    }

    private function createHrRequest(
        int $employeeId,
        string $type,
        ?string $start,
        ?string $end,
    ): int {
        return DB::table('hr_requests')->insertGetId([
            'employee_id' => $employeeId,
            'request_type' => $type,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Modern workflow regression test.',
            'status' => 'Pending',
        ]);
    }

    private function createApprovedPurchaseOrder(int $quantity, float $unitCost): array
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

        return [
            $purchaseOrderId,
            DB::table('purchase_order_items')
                ->where('purchase_order_id', $purchaseOrderId)
                ->first(),
            $product,
        ];
    }

    private function userWithPermissions(string $username, array $permissions): User
    {
        $roleId = DB::table('roles')->insertGetId([
            'name' => "{$username} role",
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'landing_page' => 'dashboard',
        ]);
        $userId = DB::table('admin_users')->insertGetId([
            'username' => $username,
            'password_hash' => 'not-used',
            'full_name' => $username,
            'role_id' => $roleId,
            'status' => 'Active',
        ]);

        return User::findOrFail($userId);
    }
}
