<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryCategoryListTest extends TestCase
{
    public function test_inventory_payload_lists_every_distinct_product_category_once(): void
    {
        $this->actingAs(User::where('username', 'inventory')->firstOrFail());

        $categories = $this->getJson('/api/workspace/inventory?per_page=1')
            ->assertOk()
            ->json('categories');

        $expected = DB::table('products')->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category')->all();

        $this->assertNotEmpty($categories);
        $this->assertSame($expected, $categories);
        $this->assertSame(array_values(array_unique($categories)), $categories);
    }
}
