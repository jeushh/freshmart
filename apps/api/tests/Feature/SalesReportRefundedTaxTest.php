<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesReportRefundedTaxTest extends TestCase
{
    private const CASHIER = 'refunded-tax-cashier';

    private function sale(
        string $order,
        string $sku,
        int $quantity,
        float $total,
        ?float $tax,
        string $timestamp = '2026-07-10 10:00:00',
    ): void {
        DB::table('sales_ledger')->insert([
            'order_id' => $order,
            'item_sku' => $sku,
            'quantity_sold' => $quantity,
            'unit_price' => $total / $quantity,
            'subtotal_amount' => $tax === null ? null : $total - $tax,
            'tax_rate' => $tax === null ? null : 12,
            'tax_amount' => $tax,
            'tax_inclusive' => $tax === null ? null : 1,
            'discount_amount' => 0,
            'total_price' => $total,
            'payment_method' => 'Cash',
            'cashier_username' => self::CASHIER,
            'timestamp' => $timestamp,
        ]);
    }

    private function refund(string $order, string $sku, int $quantity, float $amount): void
    {
        DB::table('refunds')->insert([
            'order_id' => $order,
            'item_sku' => $sku,
            'quantity_refunded' => $quantity,
            'refund_amount' => $amount,
            'created_at' => '2026-07-11 10:00:00',
        ]);
    }

    private function summary(): array
    {
        $this->actingAs(User::where('username', 'admin')->firstOrFail());

        return $this->getJson(
            '/api/reports/sales?from=2026-07-01&to=2026-07-31&cashier='.self::CASHIER,
        )->assertOk()->json('summary');
    }

    public function test_full_refund_removes_the_vat_from_net_tax(): void
    {
        $sku = DB::table('products')->value('sku');
        $this->sale('TAX-FULL', $sku, 1, 112, 12);
        $this->refund('TAX-FULL', $sku, 1, 112);

        $summary = $this->summary();

        $this->assertEquals(112, $summary['gross_sales']);
        $this->assertEquals(112, $summary['refunds']);
        $this->assertEquals(0, $summary['net_sales']);
        $this->assertEquals(12, $summary['tax_total']);
        $this->assertEquals(12, $summary['tax_refunded']);
        $this->assertEquals(0, $summary['tax_net']);
    }

    public function test_partial_refund_removes_only_its_share_of_the_vat(): void
    {
        $sku = DB::table('products')->value('sku');
        $this->sale('TAX-PARTIAL', $sku, 2, 224, 24);
        $this->refund('TAX-PARTIAL', $sku, 1, 112);

        $summary = $this->summary();

        $this->assertEquals(24, $summary['tax_total']);
        $this->assertEquals(12, $summary['tax_refunded']);
        $this->assertEquals(12, $summary['tax_net']);
    }

    public function test_sales_without_refunds_keep_net_tax_equal_to_tax_total(): void
    {
        $sku = DB::table('products')->value('sku');
        $this->sale('TAX-NONE', $sku, 1, 112, 12);

        $summary = $this->summary();

        $this->assertEquals(12, $summary['tax_total']);
        $this->assertEquals(0, $summary['tax_refunded']);
        $this->assertEquals(12, $summary['tax_net']);
    }

    public function test_refunds_of_legacy_sales_without_a_tax_snapshot_are_not_estimated(): void
    {
        $sku = DB::table('products')->value('sku');
        $this->sale('TAX-LEGACY', $sku, 1, 112, null);
        $this->refund('TAX-LEGACY', $sku, 1, 112);

        $summary = $this->summary();

        $this->assertEquals(0, $summary['tax_total']);
        $this->assertEquals(0, $summary['tax_refunded']);
        $this->assertEquals(0, $summary['tax_net']);
        $this->assertSame(1, $summary['tax_unknown_records']);
    }

    public function test_orders_outside_the_date_range_do_not_affect_refunded_tax(): void
    {
        $sku = DB::table('products')->value('sku');
        $this->sale('TAX-IN', $sku, 1, 112, 12);
        $this->sale('TAX-OUT', $sku, 1, 112, 12, '2026-08-05 10:00:00');
        $this->refund('TAX-OUT', $sku, 1, 112);

        $summary = $this->summary();

        $this->assertEquals(12, $summary['tax_total']);
        $this->assertEquals(0, $summary['tax_refunded']);
        $this->assertEquals(12, $summary['tax_net']);
    }
}
