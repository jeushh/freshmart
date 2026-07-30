<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RefundService
{
    public function create(Request $request, array $data): array
    {
        return DB::transaction(function () use ($request, $data) {
            $saleReference = DB::table('sales_ledger')
                ->where('order_id', $data['order_id'])
                ->where('item_sku', $data['item_sku'])
                ->select('product_id')
                ->first();
            abort_unless($saleReference, 404, 'The sale item was not found.');

            $productQuery = DB::table('products');
            $productId = $saleReference->product_id ?? null;
            $productId === null
                ? $productQuery->where('sku', $data['item_sku'])
                : $productQuery->where('id', $productId);

            // Canonical lock order: products first, then dependent ledger/refund rows.
            $product = $productQuery->lockForUpdate()->first();
            $sales = DB::table('sales_ledger')
                ->where('order_id', $data['order_id'])
                ->where('item_sku', $data['item_sku'])
                ->lockForUpdate()
                ->get();
            abort_if($sales->isEmpty(), 404, 'The sale item was not found.');

            $cashiers = $sales->pluck('cashier_username')
                ->uniqueStrict()
                ->values();
            abort_unless(
                $cashiers->count() === 1,
                409,
                'The sale ownership record is inconsistent.',
            );
            $saleOwner = $cashiers->first();
            $user = $request->user();
            abort_unless(
                $user->hasAnyPermission('sales.view')
                    || ($saleOwner !== null && $saleOwner === $user->username),
                403,
                'Cashiers may refund only their own sales.',
            );

            $priorRefunds = DB::table('refunds')
                ->where('order_id', $data['order_id'])
                ->where('item_sku', $data['item_sku'])
                ->lockForUpdate()
                ->get();
            $soldQuantity = (int) $sales->sum('quantity_sold');
            $refundedQuantity = (int) $priorRefunds->sum('quantity_refunded');
            $remainingQuantity = $soldQuantity - $refundedQuantity;
            abort_if(
                $data['quantity'] > $remainingQuantity,
                409,
                "Only {$remainingQuantity} unit(s) remain refundable.",
            );

            $grossAmount = round((float) $sales->sum('total_price'), 2);
            $previousRefundAmount = round((float) $priorRefunds->sum('refund_amount'), 2);
            $refundAmount = $data['quantity'] === $remainingQuantity
                ? round($grossAmount - $previousRefundAmount, 2)
                : round(($grossAmount / $soldQuantity) * $data['quantity'], 2);

            abort_unless($product, 422, 'The sold product no longer exists.');
            $newStock = $product->stock_quantity + $data['quantity'];

            $refundId = DB::table('refunds')->insertGetId([
                'order_id' => $data['order_id'],
                'item_sku' => $data['item_sku'],
                'quantity_refunded' => $data['quantity'],
                'refund_amount' => $refundAmount,
                'reason' => $data['reason'],
                'processed_by' => $user->username,
            ]);
            DB::table('products')->where('id', $product->id)->update([
                'stock_quantity' => $newStock,
            ]);
            DB::table('inventory_movements')->insert([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'movement_type' => 'Refund',
                'quantity' => $data['quantity'],
                'previous_stock' => $product->stock_quantity,
                'new_stock' => $newStock,
                'reference_id' => $data['order_id'],
                'performed_by' => $user->username,
                'notes' => $data['reason'],
            ]);
            DB::table('financial_transactions')->insert([
                'transaction_type' => 'Refund',
                'amount' => $refundAmount,
                'direction' => 'Out',
                'reference_type' => 'refund',
                'reference_id' => (string) $refundId,
                'description' => "Refund for {$data['order_id']}",
                'payment_method' => $sales->first()->payment_method,
                'created_by' => $user->username,
            ]);
            AuditLogger::record($request, 'refund.completed', 'refund', $refundId, [
                'order_id' => $data['order_id'],
                'item_sku' => $data['item_sku'],
                'quantity' => $data['quantity'],
                'amount' => $refundAmount,
                'sale_owner' => $saleOwner,
            ]);

            return [
                'id' => $refundId,
                'order_id' => $data['order_id'],
                'item_sku' => $data['item_sku'],
                'quantity_refunded' => $data['quantity'],
                'refund_amount' => $refundAmount,
                'processed_by' => $user->username,
                'sale_owner' => $saleOwner,
            ];
        });
    }
}
