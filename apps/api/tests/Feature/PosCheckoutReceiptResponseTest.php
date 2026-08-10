<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosCheckoutReceiptResponseTest extends TestCase
{
    public function test_checkout_returns_receipt_complete_server_authoritative_data_without_changing_sale_effects(): void
    {
        $this->setSetting('tax_rate', '12');
        $this->setSetting('tax_inclusive', '0');
        app(SystemSettingsService::class)->forget();

        $products = DB::table('products')->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $products);
        DB::table('products')->where('id', $products[0]->id)->update([
            'price' => 100,
            'stock_quantity' => 20,
            'status' => 'Active',
        ]);
        DB::table('products')->where('id', $products[1]->id)->update([
            'price' => 25.50,
            'stock_quantity' => 20,
            'status' => 'Active',
        ]);

        $cashier = User::where('username', 'cashier')->firstOrFail();
        $this->actingAs($cashier);
        $databaseTimestampBefore = DB::selectOne("SELECT datetime('now') AS value")->value;
        Carbon::setTestNow('2026-08-09 10:11:12');

        try {
            $response = $this->postJson('/api/workspace/pos/checkout', [
                'items' => [
                    [
                        'product_id' => $products[0]->id,
                        'quantity' => 2,
                        'price' => 0.01,
                    ],
                    [
                        'product_id' => $products[1]->id,
                        'quantity' => 3,
                        'price' => 9999,
                    ],
                ],
                'payment_method' => 'Card',
                'completed_at' => '1999-01-01T00:00:00Z',
            ])->assertOk()
                ->assertJsonStructure([
                    'order_id',
                    'total',
                    'tax_total',
                    'tax_rate',
                    'tax_inclusive',
                    'completed_at',
                    'cashier_username',
                    'payment_method',
                    'subtotal',
                    'items' => [[
                        'product_id',
                        'sku',
                        'name',
                        'quantity',
                        'unit_price',
                        'subtotal',
                        'tax_amount',
                        'total',
                    ]],
                ])
                ->assertJsonPath('total', 309.68)
                ->assertJsonPath('tax_total', 33.18)
                ->assertJsonPath('tax_rate', 12)
                ->assertJsonPath('tax_inclusive', false)
                ->assertJsonPath('subtotal', 276.5)
                ->assertJsonPath('cashier_username', 'cashier')
                ->assertJsonPath('payment_method', 'Card')
                ->assertJsonPath('completed_at', '2026-08-09T02:11:12+00:00')
                ->assertJsonCount(2, 'items');
        } finally {
            Carbon::setTestNow();
        }
        $databaseTimestampAfter = DB::selectOne("SELECT datetime('now') AS value")->value;

        $orderId = $response->json('order_id');
        $this->assertMatchesRegularExpression('/^FM-20260809101112-\d{3}$/', $orderId);

        $expectedLines = [
            $products[0]->id => [2, 100.0, 200.0, 24.0, 224.0],
            $products[1]->id => [3, 25.5, 76.5, 9.18, 85.68],
        ];
        foreach ($response->json('items') as $line) {
            [$quantity, $unitPrice, $subtotal, $taxAmount, $total] = $expectedLines[$line['product_id']];
            $this->assertSame($quantity, $line['quantity']);
            $this->assertSame($unitPrice, (float) $line['unit_price']);
            $this->assertSame($subtotal, (float) $line['subtotal']);
            $this->assertSame($taxAmount, (float) $line['tax_amount']);
            $this->assertSame($total, (float) $line['total']);

            $ledger = DB::table('sales_ledger')
                ->where('order_id', $orderId)
                ->where('item_sku', $line['sku'])
                ->first();
            $this->assertNotNull($ledger);
            $this->assertSame($quantity, $ledger->quantity_sold);
            $this->assertSame($unitPrice, (float) $ledger->unit_price);
            $this->assertSame($subtotal, (float) $ledger->subtotal_amount);
            $this->assertSame($taxAmount, (float) $ledger->tax_amount);
            $this->assertSame($total, (float) $ledger->total_price);
            $this->assertSame('Card', $ledger->payment_method);
            $this->assertSame('cashier', $ledger->cashier_username);
            $this->assertGreaterThanOrEqual($databaseTimestampBefore, $ledger->timestamp);
            $this->assertLessThanOrEqual($databaseTimestampAfter, $ledger->timestamp);
        }

        $lines = collect($response->json('items'));
        $this->assertSame(276.5, $lines->sum(fn (array $line) => (float) $line['subtotal']));
        $this->assertSame(33.18, $lines->sum(fn (array $line) => (float) $line['tax_amount']));
        $this->assertSame(309.68, $lines->sum(fn (array $line) => (float) $line['total']));

        $this->assertSame(18, DB::table('products')->where('id', $products[0]->id)->value('stock_quantity'));
        $this->assertSame(17, DB::table('products')->where('id', $products[1]->id)->value('stock_quantity'));
        $this->assertSame(2, DB::table('inventory_movements')->where('reference_id', $orderId)->where('movement_type', 'Sale')->count());
        $this->assertDatabaseHas('financial_transactions', [
            'transaction_type' => 'Sale',
            'amount' => 309.68,
            'direction' => 'In',
            'reference_type' => 'sale',
            'reference_id' => $orderId,
            'payment_method' => 'Card',
            'created_by' => 'cashier',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sale.completed',
            'entity_type' => 'sale',
            'entity_id' => $orderId,
        ]);
    }

    public function test_failed_checkout_returns_no_receipt_data_and_rolls_back_all_sale_effects(): void
    {
        $products = DB::table('products')->orderBy('id')->limit(2)->get();
        $this->assertCount(2, $products);
        DB::table('products')->where('id', $products[0]->id)->update([
            'stock_quantity' => 10,
            'status' => 'Active',
        ]);
        DB::table('products')->where('id', $products[1]->id)->update([
            'stock_quantity' => 1,
            'status' => 'Active',
        ]);
        $before = [
            'sales' => DB::table('sales_ledger')->count(),
            'movements' => DB::table('inventory_movements')->count(),
            'transactions' => DB::table('financial_transactions')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];

        $this->actingAs(User::where('username', 'cashier')->firstOrFail());
        $response = $this->postJson('/api/workspace/pos/checkout', [
            'items' => [
                ['product_id' => $products[0]->id, 'quantity' => 2],
                ['product_id' => $products[1]->id, 'quantity' => 2],
            ],
            'payment_method' => 'Cash',
        ])->assertUnprocessable();

        foreach ([
            'order_id',
            'total',
            'tax_total',
            'tax_rate',
            'tax_inclusive',
            'completed_at',
            'cashier_username',
            'payment_method',
            'subtotal',
            'items',
        ] as $path) {
            $response->assertJsonMissingPath($path);
        }
        $this->assertSame(10, DB::table('products')->where('id', $products[0]->id)->value('stock_quantity'));
        $this->assertSame(1, DB::table('products')->where('id', $products[1]->id)->value('stock_quantity'));
        $this->assertSame($before['sales'], DB::table('sales_ledger')->count());
        $this->assertSame($before['movements'], DB::table('inventory_movements')->count());
        $this->assertSame($before['transactions'], DB::table('financial_transactions')->count());
        $this->assertSame($before['audits'], DB::table('audit_logs')->count());
    }

    private function setSetting(string $key, string $value): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['setting_key' => $key],
            ['setting_value' => $value, 'updated_at' => now()->format('Y-m-d H:i:s')],
        );
    }
}
