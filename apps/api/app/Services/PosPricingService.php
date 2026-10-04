<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Computes a POS sale total exactly the way BusinessController::checkout does
 * (per-line tax calculation, each line rounded to centavos, then summed),
 * so the QR amount always matches the checkout total.
 */
class PosPricingService
{
    /** @param array<int, array{product_id:int, quantity:int}> $items */
    public function total(array $items, SystemSettingsService $settings): float
    {
        $totalCents = 0;

        foreach ($items as $item) {
            $product = DB::table('products')->where('id', $item['product_id'])->first();
            abort_if(! $product || $product->status !== 'Active', 422, 'A product in this sale is not available.');
            abort_if($product->stock_quantity < $item['quantity'], 422, "Insufficient stock for {$product->name}.");

            $tax = $settings->calculateTax(((float) $product->price) * $item['quantity']);
            $totalCents += (int) round($tax['total'] * 100);
        }

        return $totalCents / 100;
    }
}
