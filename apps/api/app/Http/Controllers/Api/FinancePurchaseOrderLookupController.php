<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancePurchaseOrderLookupController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = $this->baseQuery();

        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($q) => $q
                ->where('purchase_orders.po_number', 'like', "%{$search}%")
                ->orWhere('suppliers.name', 'like', "%{$search}%"));
        }

        $orders = $query->orderByDesc('purchase_orders.id')->paginate($data['per_page'] ?? 20);

        return ['orders' => $orders];
    }

    public function show(int $purchaseOrder)
    {
        $order = $this->baseQuery()
            ->where('purchase_orders.id', $purchaseOrder)
            ->first();
        abort_unless($order, 404);

        $items = DB::table('purchase_order_items')
            ->join('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->where('purchase_order_items.purchase_order_id', $purchaseOrder)
            ->orderBy('purchase_order_items.id')
            ->get([
                'purchase_order_items.id as purchase_order_item_id',
                'products.id as product_id',
                'products.name as product_name',
                'purchase_order_items.sku',
                'purchase_order_items.quantity_ordered',
                'purchase_order_items.quantity_received as accepted_received_qty',
                'purchase_order_items.unit_cost as po_unit_cost',
            ])
            ->map(function ($item) {
                $committed = (int) DB::table('supplier_invoice_items')
                    ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                    ->where('supplier_invoice_items.purchase_order_item_id', $item->purchase_order_item_id)
                    ->whereIn('supplier_invoices.status', ['Registered', 'Disputed', 'Approved'])
                    ->sum('supplier_invoice_items.invoiced_quantity');

                $item->committed_invoice_qty = $committed;
                $item->remaining_invoiceable_qty = max(0, $item->quantity_ordered - $committed);

                return $item;
            });

        return [
            'order' => $order,
            'items' => $items,
        ];
    }

    private function baseQuery()
    {
        return DB::table('purchase_orders')
            ->join('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->where('purchase_orders.approval_status', 'Approved')
            ->where('purchase_orders.supplier_status', 'Accepted')
            ->whereIn('purchase_orders.status', ['Ordered', 'Partially Received', 'Fully Received'])
            ->select(
                'purchase_orders.id',
                'purchase_orders.po_number',
                'purchase_orders.status',
                'purchase_orders.supplier_id',
                'suppliers.name as supplier_name',
                DB::raw('1 as invoiceable'),
            );
    }
}
