<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestockRequestMultiItemTest extends TestCase
{
    // ---------------------------------------------------------------- creation

    public function test_multi_item_request_creates_lines_and_nulls_legacy_header_fields(): void
    {
        $products = $this->activeProducts(2);
        $this->actingAs($this->requester());

        $response = $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Multiple products need replenishment.',
            'items' => $products
                ->map(fn ($product) => [
                    'product_id' => $product->id,
                    'requested_quantity' => 3,
                ])
                ->all(),
        ])->assertCreated()->assertJsonCount(2, 'items');

        $id = $response->json('id');
        $this->assertSame(
            2,
            DB::table('restock_request_items')->where('restock_request_id', $id)->count(),
        );
        $this->assertDatabaseHas('restock_requests', [
            'id' => $id,
            'product_id' => null,
            'sku' => null,
            'requested_quantity' => null,
        ]);
    }

    public function test_line_snapshots_record_stock_levels_at_submission_time(): void
    {
        $product = $this->activeProducts(1)->first();
        $this->actingAs($this->requester());

        $id = $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Snapshot check.',
            'items' => [['product_id' => $product->id, 'requested_quantity' => 2]],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('restock_request_items', [
            'restock_request_id' => $id,
            'current_stock' => $product->stock_quantity,
            'reorder_level' => $product->reorder_level,
            'max_stock' => $product->max_stock,
        ]);
    }

    public function test_legacy_request_body_creates_one_line_and_populates_legacy_header(): void
    {
        $product = $this->activeProducts(1)->first();
        $this->actingAs($this->requester());

        $id = $this->postJson('/api/restock-requests', [
            'product_id' => $product->id,
            'requested_quantity' => 4,
            'priority' => 'High',
            'reason' => 'Compatibility test.',
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('restock_requests', [
            'id' => $id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'requested_quantity' => 4,
        ]);
        $this->assertDatabaseHas('restock_request_items', [
            'restock_request_id' => $id,
            'product_id' => $product->id,
            'requested_quantity' => 4,
        ]);
    }

    public function test_duplicate_product_within_one_request_is_rejected(): void
    {
        $product = $this->activeProducts(1)->first();
        $this->actingAs($this->requester());

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Duplicate line.',
            'items' => [
                ['product_id' => $product->id, 'requested_quantity' => 2],
                ['product_id' => $product->id, 'requested_quantity' => 5],
            ],
        ])->assertStatus(422);
    }

    public function test_product_with_an_active_line_elsewhere_is_rejected_by_sku(): void
    {
        $products = $this->activeProducts(2);
        $this->actingAs($this->requester());

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'First request.',
            'items' => [['product_id' => $products[0]->id, 'requested_quantity' => 2]],
        ])->assertCreated();

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Second request reusing a product.',
            'items' => [
                ['product_id' => $products[1]->id, 'requested_quantity' => 2],
                ['product_id' => $products[0]->id, 'requested_quantity' => 2],
            ],
        ])->assertStatus(409)->assertSee($products[0]->sku);
    }

    public function test_a_rejected_item_rolls_back_the_whole_request(): void
    {
        $products = $this->activeProducts(2);
        $before = DB::table('restock_requests')->count();
        $this->actingAs($this->requester());

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'First request.',
            'items' => [['product_id' => $products[0]->id, 'requested_quantity' => 2]],
        ])->assertCreated();

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Conflicting request.',
            'items' => [
                ['product_id' => $products[1]->id, 'requested_quantity' => 2],
                ['product_id' => $products[0]->id, 'requested_quantity' => 2],
            ],
        ])->assertStatus(409);

        $this->assertSame($before + 1, DB::table('restock_requests')->count());
        $this->assertDatabaseMissing('restock_request_items', [
            'product_id' => $products[1]->id,
        ]);
    }

    public function test_empty_item_list_is_rejected(): void
    {
        $this->actingAs($this->requester());

        $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'No lines.',
            'items' => [],
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------ review

    public function test_review_can_approve_one_line_and_reject_another(): void
    {
        $items = $this->submitRequest(2);
        $id = $items['id'];

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$id}/review", [
                'decisions' => [
                    ['item_id' => $items['items'][0]['id'], 'decision' => 'Approved', 'approved_quantity' => 3],
                    ['item_id' => $items['items'][1]['id'], 'decision' => 'Rejected'],
                ],
            ])->assertOk();

        $this->assertDatabaseHas('restock_request_items', [
            'id' => $items['items'][0]['id'],
            'status' => 'Approved',
            'approved_quantity' => 3,
        ]);
        $this->assertDatabaseHas('restock_request_items', [
            'id' => $items['items'][1]['id'],
            'status' => 'Rejected',
            'approved_quantity' => null,
        ]);
        $this->assertDatabaseHas('restock_requests', ['id' => $id, 'status' => 'Approved']);
    }

    public function test_rejecting_every_line_rolls_the_header_up_to_rejected(): void
    {
        $request = $this->submitRequest(2);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", [
                'decisions' => collect($request['items'])
                    ->map(fn ($item) => ['item_id' => $item['id'], 'decision' => 'Rejected'])
                    ->all(),
            ])->assertOk();

        $this->assertDatabaseHas('restock_requests', [
            'id' => $request['id'],
            'status' => 'Rejected',
        ]);
    }

    public function test_review_missing_a_pending_line_is_rejected(): void
    {
        $request = $this->submitRequest(2);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", [
                'decisions' => [
                    ['item_id' => $request['items'][0]['id'], 'decision' => 'Approved'],
                ],
            ])->assertStatus(422);

        $this->assertDatabaseHas('restock_requests', [
            'id' => $request['id'],
            'status' => 'Pending Approval',
        ]);
    }

    public function test_approved_quantity_above_requested_is_rejected(): void
    {
        $request = $this->submitRequest(1, 5);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", [
                'decisions' => [
                    ['item_id' => $request['items'][0]['id'], 'decision' => 'Approved', 'approved_quantity' => 6],
                ],
            ])->assertStatus(422);
    }

    public function test_approved_quantity_on_a_rejected_line_is_rejected(): void
    {
        $request = $this->submitRequest(1);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", [
                'decisions' => [
                    ['item_id' => $request['items'][0]['id'], 'decision' => 'Rejected', 'approved_quantity' => 2],
                ],
            ])->assertStatus(422);
    }

    public function test_review_item_from_another_request_is_rejected(): void
    {
        $first = $this->submitRequest(1);
        $second = $this->submitRequest(1);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$first['id']}/review", [
                'decisions' => [
                    ['item_id' => $second['items'][0]['id'], 'decision' => 'Approved'],
                ],
            ])->assertStatus(404);
    }

    public function test_reviewing_an_already_reviewed_request_is_rejected(): void
    {
        $request = $this->submitRequest(1);
        $decisions = [['item_id' => $request['items'][0]['id'], 'decision' => 'Approved']];

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", ['decisions' => $decisions])
            ->assertOk();
        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", ['decisions' => $decisions])
            ->assertStatus(409);
    }

    public function test_legacy_review_body_approves_every_line(): void
    {
        $request = $this->submitRequest(2);

        $this->actingAs($this->approver())
            ->postJson("/api/restock-requests/{$request['id']}/review", ['decision' => 'Approved'])
            ->assertOk();

        $this->assertSame(
            0,
            DB::table('restock_request_items')
                ->where('restock_request_id', $request['id'])
                ->where('status', '!=', 'Approved')
                ->count(),
        );
    }

    // -------------------------------------------------------------------- RBAC

    public function test_requester_cannot_review(): void
    {
        $request = $this->submitRequest(1);

        $this->actingAs($this->requester())
            ->postJson("/api/restock-requests/{$request['id']}/review", [
                'decisions' => [['item_id' => $request['items'][0]['id'], 'decision' => 'Approved']],
            ])->assertStatus(403);
    }

    public function test_approver_cannot_create_requests(): void
    {
        $product = $this->activeProducts(1)->first();

        $this->actingAs($this->approver())
            ->postJson('/api/restock-requests', [
                'priority' => 'Normal',
                'reason' => 'Approver should not be able to raise this.',
                'items' => [['product_id' => $product->id, 'requested_quantity' => 2]],
            ])->assertStatus(403);
    }

    // --------------------------------------------------------------- migration

    public function test_schema_is_consistent_after_the_restock_table_rebuild(): void
    {
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $columns = collect(DB::select('PRAGMA table_info(restock_requests)'))->keyBy('name');
        foreach (['product_id', 'sku', 'current_stock', 'reorder_level', 'max_stock', 'recommended_quantity', 'requested_quantity'] as $column) {
            $this->assertSame(
                0,
                (int) $columns[$column]->notnull,
                "restock_requests.{$column} should be nullable after the rebuild.",
            );
        }
        $this->assertSame(1, (int) $columns['requested_by']->notnull);

        $indexes = collect(DB::select('PRAGMA index_list(restock_requests)'))->pluck('name');
        $this->assertTrue($indexes->contains('idx_restock_status'));
        $this->assertTrue($indexes->contains('idx_restock_product'));
    }

    public function test_every_restock_request_has_at_least_one_line(): void
    {
        $orphans = DB::table('restock_requests')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('restock_request_items')
                ->whereColumn('restock_request_items.restock_request_id', 'restock_requests.id'))
            ->count();

        $this->assertSame(0, $orphans);
    }

    // ------------------------------------------------------------------ helpers

    private function activeProducts(int $count)
    {
        $products = DB::table('products')
            ->where('status', 'Active')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('restock_request_items')
                ->whereColumn('restock_request_items.product_id', 'products.id')
                ->whereIn('restock_request_items.status', [
                    'Pending Approval', 'Approved', 'Purchase Order Created',
                    'Ordered', 'Partially Received',
                ]))
            ->orderBy('id')
            ->limit($count)
            ->get()
            ->values();

        $this->assertCount($count, $products, 'Not enough free active products to run this test.');

        return $products;
    }

    private function submitRequest(int $lines, int $quantity = 5): array
    {
        $products = $this->activeProducts($lines);
        $this->actingAs($this->requester());

        return $this->postJson('/api/restock-requests', [
            'priority' => 'Normal',
            'reason' => 'Automated test request.',
            'items' => $products
                ->map(fn ($product) => [
                    'product_id' => $product->id,
                    'requested_quantity' => $quantity,
                ])
                ->all(),
        ])->assertCreated()->json();
    }

    private function requester(): User
    {
        return User::where('username', 'inventory')->firstOrFail();
    }

    private function approver(): User
    {
        return User::where('username', 'operations')->firstOrFail();
    }
}
