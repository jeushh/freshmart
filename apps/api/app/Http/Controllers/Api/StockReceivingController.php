<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReceivingController extends Controller
{
    public function store(Request $request, int $purchaseOrder)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
            'invoice_number' => 'nullable|string|max:100',
            'due_date' => 'nullable|date',
            'items' => 'required|array|min:1|max:100',
            'items.*.purchase_order_item_id' => 'required|integer|distinct',
            'items.*.delivered_quantity' => 'required|integer|min:0|max:100000',
            'items.*.damaged_quantity' => 'sometimes|integer|min:0|max:100000',
            'items.*.rejected_quantity' => 'sometimes|integer|min:0|max:100000',
            'items.*.batch_no' => 'nullable|string|max:100',
            'items.*.expiration_date' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data, $purchaseOrder, $request) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Approved',
                409,
                'Only approved purchase orders can be received.',
            );
            if ($order->supplier_status === null) {
                abort_unless(
                    in_array($order->status, ['Approved', 'Ordered', 'Partially Received'], true),
                    409,
                    'Only outstanding legacy purchase orders can be received.',
                );
            } else {
                abort_unless(
                    $order->supplier_status === 'Accepted'
                        && in_array($order->status, ['Ordered', 'Partially Received'], true),
                    409,
                    'Only supplier-accepted purchase orders can be received.',
                );
            }

            $prepared = [];
            foreach ($data['items'] as $item) {
                $delivered = $item['delivered_quantity'];
                $damaged = $item['damaged_quantity'] ?? 0;
                $rejected = $item['rejected_quantity'] ?? 0;
                abort_if(
                    $damaged + $rejected > $delivered,
                    422,
                    'Damaged and rejected quantities cannot exceed the delivered quantity.',
                );
                if ($delivered === 0) {
                    continue;
                }
                $accepted = $delivered - $damaged - $rejected;
                // Only accepted units satisfy the supplier's purchase-order obligation.
                $fulfilled = $accepted;

                $line = DB::table('purchase_order_items')
                    ->where('id', $item['purchase_order_item_id'])
                    ->where('purchase_order_id', $purchaseOrder)
                    ->lockForUpdate()
                    ->first();
                abort_unless($line, 422, 'A receiving line does not belong to this purchase order.');
                $outstanding = $line->quantity_ordered - $line->quantity_received;
                abort_if(
                    $fulfilled > $outstanding,
                    409,
                    "Cannot fulfill {$fulfilled} of {$line->sku}; only {$outstanding} remain outstanding.",
                );
                $product = DB::table('products')
                    ->where('id', $line->product_id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($product, 422, "Product {$line->sku} no longer exists.");
                $prepared[] = [
                    'input' => $item,
                    'line' => $line,
                    'product' => $product,
                    'delivered' => $delivered,
                    'damaged' => $damaged,
                    'rejected' => $rejected,
                    'accepted' => $accepted,
                    'fulfilled' => $fulfilled,
                ];
            }
            abort_if($prepared === [], 422, 'At least one delivered quantity must be greater than zero.');

            $receivingId = DB::table('stock_receivings')->insertGetId([
                'purchase_order_id' => $purchaseOrder,
                'received_by' => $request->user()->username,
                'receiving_date' => now()->format('Y-m-d H:i:s'),
                'notes' => $data['notes'] ?? null,
            ]);
            $totalCost = 0.0;
            $auditLines = [];
            $currentStocks = [];

            foreach ($prepared as $entry) {
                $line = $entry['line'];
                $product = $entry['product'];
                DB::table('stock_receiving_items')->insert([
                    'stock_receiving_id' => $receivingId,
                    'purchase_order_item_id' => $line->id,
                    'product_id' => $product->id,
                    'sku' => $line->sku,
                    // This existing history column stores the physical delivery quantity.
                    'received_quantity' => $entry['delivered'],
                    'damaged_quantity' => $entry['damaged'],
                    'rejected_quantity' => $entry['rejected'],
                    'batch_no' => $entry['input']['batch_no'] ?? null,
                    'expiration_date' => $entry['input']['expiration_date'] ?? null,
                    'unit_cost' => $line->unit_cost,
                ]);
                DB::table('purchase_order_items')->where('id', $line->id)->update([
                    'quantity_received' => $line->quantity_received + $entry['fulfilled'],
                ]);

                $previousStock = $currentStocks[$product->id] ?? $product->stock_quantity;
                $newStock = $previousStock + $entry['accepted'];
                $currentStocks[$product->id] = $newStock;
                if ($entry['accepted'] > 0) {
                    DB::table('products')->where('id', $product->id)->update([
                        'stock_quantity' => $newStock,
                    ]);
                    DB::table('inventory_movements')->insert([
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'movement_type' => 'Receiving',
                        'quantity' => $entry['accepted'],
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'reference_id' => $order->po_number,
                        'performed_by' => $request->user()->username,
                        'notes' => "Stock receiving #{$receivingId}",
                    ]);
                }
                $totalCost += $entry['accepted'] * $line->unit_cost;
                $auditLines[] = [
                    'sku' => $product->sku,
                    'delivered_quantity' => $entry['delivered'],
                    'accepted_quantity' => $entry['accepted'],
                    'fulfilled_quantity' => $entry['fulfilled'],
                    'damaged_quantity' => $entry['damaged'],
                    'rejected_quantity' => $entry['rejected'],
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                ];
            }

            $totals = DB::table('purchase_order_items')
                ->where('purchase_order_id', $purchaseOrder)
                ->selectRaw(
                    'SUM(quantity_ordered) as ordered, SUM(quantity_received) as fulfilled',
                )
                ->first();
            $newStatus = $totals->fulfilled >= $totals->ordered
                ? 'Fully Received'
                : 'Partially Received';
            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'status' => $newStatus,
                'received_at' => now()->format('Y-m-d H:i:s'),
            ]);
            if ($order->restock_request_id) {
                DB::table('restock_requests')
                    ->where('id', $order->restock_request_id)
                    ->update([
                        'status' => $newStatus === 'Fully Received'
                            ? 'Completed'
                            : 'Partially Received',
                    ]);
            }

            $totalCost = round($totalCost, 2);
            if ($totalCost > 0) {
                DB::table('financial_transactions')->insert([
                    'transaction_type' => 'Purchase',
                    'amount' => $totalCost,
                    'direction' => 'Out',
                    'reference_type' => 'purchase_order',
                    'reference_id' => (string) $purchaseOrder,
                    'description' => "Stock receiving for PO {$order->po_number}",
                    'category' => 'Inventory Purchase',
                    'created_by' => $request->user()->username,
                ]);
                $payable = DB::table('accounts_payable')
                    ->where('purchase_order_id', $purchaseOrder)
                    ->lockForUpdate()
                    ->first();
                if ($payable) {
                    $newTotal = round($payable->total_amount + $totalCost, 2);
                    DB::table('accounts_payable')->where('id', $payable->id)->update([
                        'total_amount' => $newTotal,
                        'invoice_number' => $data['invoice_number'] ?? $payable->invoice_number,
                        'due_date' => $data['due_date'] ?? $payable->due_date,
                        'status' => $payable->amount_paid >= $newTotal
                            ? 'Paid'
                            : ($payable->amount_paid > 0 ? 'Partially Paid' : 'Unpaid'),
                    ]);
                } else {
                    DB::table('accounts_payable')->insert([
                        'supplier_id' => $order->supplier_id,
                        'purchase_order_id' => $purchaseOrder,
                        'invoice_number' => $data['invoice_number'] ?? null,
                        'total_amount' => $totalCost,
                        'amount_paid' => 0,
                        'due_date' => $data['due_date'] ?? null,
                        'status' => 'Unpaid',
                    ]);
                }
            }

            AuditLogger::record(
                $request,
                'stock_receiving.created',
                'stock_receiving',
                $receivingId,
                [
                    'purchase_order_id' => $purchaseOrder,
                    'purchase_order_status' => $newStatus,
                    'total_purchase_cost' => $totalCost,
                    'lines' => $auditLines,
                ],
            );

            return response()->json([
                'stock_receiving_id' => $receivingId,
                'purchase_order_id' => $purchaseOrder,
                'purchase_order_status' => $newStatus,
                'total_purchase_cost' => $totalCost,
                'lines' => $auditLines,
            ], 201);
        });
    }
}
