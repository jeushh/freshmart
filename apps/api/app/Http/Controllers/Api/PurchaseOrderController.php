<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\RestockRequestStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly RestockRequestStatusService $restockStatuses) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'approval_status' => 'sometimes|in:Draft,Submitted,Approved,Rejected,Cancelled',
            'status' => 'sometimes|in:Pending,Approved,Ordered,Partially Received,Fully Received,Cancelled',
            'supplier_status' => 'sometimes|in:Not Sent,Sent,Accepted,Rejected',
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = $this->summaryQuery();

        foreach (['approval_status', 'status', 'supplier_status', 'supplier_id'] as $filter) {
            if (isset($data[$filter])) {
                $query->where("purchase_orders.{$filter}", $data[$filter]);
            }
        }
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($item) => $item
                ->where('purchase_orders.po_number', 'like', "%{$search}%")
                ->orWhere('suppliers.name', 'like', "%{$search}%"));
        }

        return [
            'orders' => $query
                ->orderByDesc('purchase_orders.id')
                ->paginate($data['per_page'] ?? 20),
            'suppliers' => DB::table('suppliers')
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'products' => DB::table('products')
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'cost_price', 'supplier_id', 'unit']),
            'approved_restock_requests' => DB::table('restock_request_items')
                ->join('restock_requests', 'restock_request_items.restock_request_id', '=', 'restock_requests.id')
                ->join('products', 'restock_request_items.product_id', '=', 'products.id')
                ->where('restock_request_items.status', 'Approved')
                ->whereNotNull('restock_request_items.supplier_id')
                ->orderByDesc('restock_requests.id')->orderBy('restock_request_items.id')
                ->get(['restock_requests.id as restock_request_id', 'restock_requests.ref_number', 'restock_request_items.id as restock_request_item_id', 'restock_request_items.product_id', 'restock_request_items.approved_quantity', 'restock_request_items.supplier_id', 'products.name as product_name', 'products.sku'])
                ->groupBy(fn ($item) => $item->restock_request_id.'-'.$item->supplier_id)
                ->map(fn ($items) => ['id' => $items->first()->restock_request_id, 'ref_number' => $items->first()->ref_number, 'supplier_id' => $items->first()->supplier_id, 'items' => $items->values()])
                ->values(),
        ];
    }

    public function show(int $purchaseOrder)
    {
        $order = $this->summaryQuery()
            ->where('purchase_orders.id', $purchaseOrder)
            ->first();
        abort_unless($order, 404);

        $items = DB::table('purchase_order_items')
            ->leftJoin('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->where('purchase_order_items.purchase_order_id', $purchaseOrder)
            ->orderBy('purchase_order_items.id')
            ->get([
                'purchase_order_items.*',
                'products.name as product_name',
                'products.stock_quantity as current_stock',
                'products.unit',
            ])
            ->map(function ($item) {
                $item->fulfilled_quantity = $item->quantity_received;
                $item->outstanding_quantity = max(
                    0,
                    $item->quantity_ordered - $item->fulfilled_quantity,
                );
                $item->line_total = round(
                    $item->quantity_ordered * $item->unit_cost,
                    2,
                );

                return $item;
            });
        $receivings = DB::table('stock_receivings')
            ->where('purchase_order_id', $purchaseOrder)
            ->orderByDesc('id')
            ->get()
            ->map(function ($receiving) {
                $receiving->items = DB::table('stock_receiving_items')
                    ->where('stock_receiving_id', $receiving->id)
                    ->orderBy('id')
                    ->get()
                    ->map(function ($item) {
                        $item->delivered_quantity = $item->received_quantity;
                        $item->accepted_quantity = $item->delivered_quantity
                            - $item->damaged_quantity
                            - $item->rejected_quantity;
                        $item->fulfilled_quantity = $item->accepted_quantity;
                        $item->accepted_cost = round(
                            $item->accepted_quantity * $item->unit_cost,
                            2,
                        );

                        return $item;
                    });

                return $receiving;
            });

        return [
            'order' => $order,
            'items' => $items,
            'receivings' => $receivings,
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $request) {
            [$supplier, $products, $restock, $restockItems] = $this->validateRelationships($data);
            $orderId = DB::table('purchase_orders')->insertGetId([
                'po_number' => $this->purchaseOrderNumber(),
                'restock_request_id' => $restock?->id,
                'supplier_id' => $supplier->id,
                'sku' => count($data['items']) === 1
                    ? $products[$data['items'][0]['product_id']]->sku
                    : null,
                'quantity_ordered' => collect($data['items'])->sum('quantity'),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'Pending',
                'approval_status' => 'Draft',
                'supplier_status' => 'Not Sent',
                'created_by' => $request->user()->username,
            ]);
            $this->replaceItems($orderId, $data['items'], $products, $restockItems);
            $this->linkRestock($restock, $orderId, $restockItems);
            AuditLogger::record($request, 'purchase_order.created', 'purchase_order', $orderId, [
                'supplier_id' => $supplier->id,
                'restock_request_id' => $restock?->id,
                'total_amount' => $this->total($data['items']),
            ]);

            return response()->json($this->show($orderId), 201);
        });
    }

    public function update(Request $request, int $purchaseOrder)
    {
        $data = $this->validated($request);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Draft' && $order->status === 'Pending',
                409,
                'Only draft purchase orders can be edited.',
            );
            abort_if(
                DB::table('stock_receivings')->where('purchase_order_id', $purchaseOrder)->exists(),
                409,
                'Purchase orders with receiving history cannot be edited.',
            );

            [$supplier, $products, $restock, $restockItems] = $this->validateRelationships(
                $data,
                $purchaseOrder,
            );
            $this->releaseRestockIfChanged($order, $restock?->id);
            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'restock_request_id' => $restock?->id,
                'supplier_id' => $supplier->id,
                'sku' => count($data['items']) === 1
                    ? $products[$data['items'][0]['product_id']]->sku
                    : null,
                'quantity_ordered' => collect($data['items'])->sum('quantity'),
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->replaceItems($purchaseOrder, $data['items'], $products, $restockItems);
            $this->linkRestock($restock, $purchaseOrder, $restockItems);
            AuditLogger::record($request, 'purchase_order.updated', 'purchase_order', $purchaseOrder, [
                'supplier_id' => $supplier->id,
                'restock_request_id' => $restock?->id,
                'total_amount' => $this->total($data['items']),
            ]);

            return $this->show($purchaseOrder);
        });
    }

    public function submit(Request $request, int $purchaseOrder)
    {
        return DB::transaction(function () use ($request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Draft' && $order->status === 'Pending',
                409,
                "Purchase order cannot be submitted from {$order->approval_status}.",
            );
            abort_unless(
                DB::table('purchase_order_items')
                    ->where('purchase_order_id', $purchaseOrder)
                    ->exists(),
                422,
                'A purchase order must contain at least one item.',
            );

            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'approval_status' => 'Submitted',
                'submitted_at' => now()->format('Y-m-d H:i:s'),
            ]);
            AuditLogger::record(
                $request,
                'purchase_order.submitted',
                'purchase_order',
                $purchaseOrder,
            );

            return $this->show($purchaseOrder);
        });
    }

    public function review(Request $request, int $purchaseOrder)
    {
        $data = $request->validate([
            'decision' => 'required|in:Approved,Rejected',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Submitted' && $order->status === 'Pending',
                409,
                "Purchase order cannot be reviewed from {$order->approval_status}.",
            );

            $approved = $data['decision'] === 'Approved';
            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'approval_status' => $data['decision'],
                'status' => $approved ? 'Approved' : 'Cancelled',
                'reviewed_by' => $request->user()->username,
                'reviewed_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? null,
            ]);
            if ($order->restock_request_id && ! $approved) {
                $this->releaseRestockOrder($order);
            }
            AuditLogger::record(
                $request,
                'purchase_order.'.strtolower($data['decision']),
                'purchase_order',
                $purchaseOrder,
                [
                    'previous_status' => $order->approval_status,
                    'notes' => $data['notes'] ?? null,
                ],
            );

            return $this->show($purchaseOrder);
        });
    }

    public function send(Request $request, int $purchaseOrder)
    {
        return DB::transaction(function () use ($request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Approved'
                    && $order->status === 'Approved'
                    && $order->supplier_status === 'Not Sent',
                409,
                "Purchase order cannot be sent to supplier from approval state {$order->approval_status} and supplier state {$order->supplier_status}.",
            );
            abort_if(
                DB::table('stock_receivings')->where('purchase_order_id', $purchaseOrder)->exists(),
                409,
                'Purchase orders with receiving history cannot be sent.',
            );

            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'supplier_status' => 'Sent',
                'sent_to_supplier_at' => now()->format('Y-m-d H:i:s'),
                'sent_by' => $request->user()->username,
                'status' => 'Ordered',
            ]);
            if ($order->restock_request_id) {
                $this->setRestockOrderStatus($order, 'Purchase Order Created', 'Ordered');
            }
            AuditLogger::record(
                $request,
                'purchase_order.sent_to_supplier',
                'purchase_order',
                $purchaseOrder,
                ['supplier_status' => 'Sent'],
            );

            return $this->show($purchaseOrder);
        });
    }

    public function supplierResponse(Request $request, int $purchaseOrder)
    {
        $data = $request->validate([
            'response' => 'required|in:Accepted,Rejected',
            'supplier_reference' => 'nullable|string|max:100',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                $order->approval_status === 'Approved'
                    && $order->status === 'Ordered'
                    && $order->supplier_status === 'Sent',
                409,
                "Supplier response cannot be recorded for PO in supplier state {$order->supplier_status}.",
            );

            $isAccepted = $data['response'] === 'Accepted';
            if (! $isAccepted) {
                abort_if(
                    DB::table('stock_receivings')->where('purchase_order_id', $purchaseOrder)->exists(),
                    409,
                    'Purchase orders with receiving history cannot be rejected by supplier.',
                );
            }

            $update = [
                'supplier_status' => $data['response'],
                'supplier_responded_at' => now()->format('Y-m-d H:i:s'),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'supplier_response_notes' => $data['notes'] ?? null,
            ];
            if (! empty($data['expected_delivery_date'])) {
                $update['expected_delivery_date'] = $data['expected_delivery_date'];
            }

            if ($isAccepted) {
                DB::table('purchase_orders')->where('id', $purchaseOrder)->update($update);
                AuditLogger::record(
                    $request,
                    'purchase_order.supplier_accepted',
                    'purchase_order',
                    $purchaseOrder,
                    [
                        'supplier_status' => 'Accepted',
                        'supplier_reference' => $data['supplier_reference'] ?? null,
                    ],
                );
            } else {
                $update['status'] = 'Cancelled';
                DB::table('purchase_orders')->where('id', $purchaseOrder)->update($update);
                if ($order->restock_request_id) {
                    $this->releaseRestockOrder($order);
                }
                AuditLogger::record(
                    $request,
                    'purchase_order.supplier_rejected',
                    'purchase_order',
                    $purchaseOrder,
                    [
                        'supplier_status' => 'Rejected',
                        'notes' => $data['notes'] ?? null,
                    ],
                );
            }

            return $this->show($purchaseOrder);
        });
    }

    public function cancel(Request $request, int $purchaseOrder)
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            abort_unless(
                in_array($order->approval_status, ['Draft', 'Submitted', 'Approved'], true),
                409,
                "Purchase order cannot be cancelled from {$order->approval_status}.",
            );
            $requiredPermission = $order->approval_status === 'Approved'
                ? 'procurement.purchase_orders.approve'
                : 'procurement.purchase_orders.manage';
            abort_unless(
                $request->user()->hasAnyPermission($requiredPermission),
                403,
                'Permission denied for this purchase-order state.',
            );
            abort_if(
                DB::table('stock_receivings')->where('purchase_order_id', $purchaseOrder)->exists(),
                409,
                'Purchase orders with receiving history cannot be cancelled.',
            );
            abort_if(
                DB::table('supplier_invoices')
                    ->where('purchase_order_id', $purchaseOrder)
                    ->whereIn('status', ['Draft', 'Registered', 'Disputed', 'Approved'])
                    ->exists(),
                409,
                'Purchase orders with active supplier invoices cannot be cancelled.',
            );

            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'approval_status' => 'Cancelled',
                'status' => 'Cancelled',
                'cancelled_by' => $request->user()->username,
                'cancelled_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? $order->review_notes,
            ]);
            if ($order->restock_request_id) {
                $this->releaseRestockOrder($order);
            }
            AuditLogger::record(
                $request,
                'purchase_order.cancelled',
                'purchase_order',
                $purchaseOrder,
                ['notes' => $data['notes'] ?? null],
            );

            return $this->show($purchaseOrder);
        });
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,id',
            'restock_request_id' => 'nullable|integer|exists:restock_requests,id',
            'expected_delivery_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:999999999',
        ]);
    }

    private function validateRelationships(array $data, ?int $purchaseOrder = null): array
    {
        $supplier = DB::table('suppliers')
            ->where('id', $data['supplier_id'])
            ->lockForUpdate()
            ->first();
        abort_unless($supplier, 404);
        abort_if($supplier->status !== 'Active', 422, 'The supplier must be active.');

        $productIds = collect($data['items'])->pluck('product_id');
        $products = DB::table('products')
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        abort_unless(
            $products->count() === $productIds->unique()->count(),
            422,
            'One or more products are unavailable.',
        );
        foreach ($products as $product) {
            abort_if($product->status !== 'Active', 422, "{$product->name} is inactive.");
            abort_if(
                $product->supplier_id !== null
                    && (int) $product->supplier_id !== (int) $supplier->id,
                422,
                "{$product->name} is assigned to a different supplier.",
            );
        }

        $restock = null;
        $restockItems = collect();
        if (! empty($data['restock_request_id'])) {
            $restock = DB::table('restock_requests')
                ->where('id', $data['restock_request_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($restock, 404);
            $linkedToCurrent = $purchaseOrder !== null && DB::table('purchase_order_items')
                ->join(
                    'restock_request_items',
                    'purchase_order_items.restock_request_item_id',
                    '=',
                    'restock_request_items.id',
                )
                ->where('purchase_order_items.purchase_order_id', $purchaseOrder)
                ->where('restock_request_items.restock_request_id', $restock->id)
                ->exists();
            abort_unless(
                in_array($restock->status, ['Approved', 'Purchase Order Created'], true)
                    || $linkedToCurrent,
                409,
                'Only approved, uncommitted restock requests can be linked.',
            );

            $restockItems = DB::table('restock_request_items')
                ->where('restock_request_id', $restock->id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');
            abort_unless(
                $restockItems->count() === $productIds->unique()->count(),
                422,
                'The purchase order must contain only restock-request products.',
            );

            // A line already committed to a different, still-active purchase order must
            // never be orderable again, or the same approved quantity would be bought
            // twice. A Cancelled order (rejected on review, cancelled, or rejected by the
            // supplier) already released its restock items back to 'Approved' via
            // releaseRestockOrder(), so its leftover purchase_order_items rows must not
            // count as a claim here.
            $claimedElsewhere = DB::table('purchase_order_items')
                ->join(
                    'purchase_orders',
                    'purchase_order_items.purchase_order_id',
                    '=',
                    'purchase_orders.id',
                )
                ->whereIn('purchase_order_items.restock_request_item_id', $restockItems->pluck('id'))
                ->where('purchase_orders.status', '!=', 'Cancelled')
                ->when(
                    $purchaseOrder !== null,
                    fn ($query) => $query->where('purchase_order_items.purchase_order_id', '!=', $purchaseOrder),
                )
                ->pluck('purchase_order_items.restock_request_item_id')
                ->all();

            foreach ($data['items'] as $item) {
                $restockItem = $restockItems[$item['product_id']];
                abort_if(
                    in_array($restockItem->id, $claimedElsewhere, true),
                    409,
                    "{$restockItem->sku} is already on another purchase order.",
                );
                abort_unless(
                    in_array($restockItem->status, ['Approved', 'Purchase Order Created'], true),
                    409,
                    "{$restockItem->sku} is not available for ordering.",
                );
                abort_if(
                    $restockItem->supplier_id === null,
                    422,
                    "{$restockItem->sku} needs a supplier assigned first.",
                );
                abort_if(
                    (int) $restockItem->supplier_id !== (int) $supplier->id,
                    422,
                    'A purchase order can contain restock lines for only one supplier.',
                );
                abort_if(
                    $restockItem->approved_quantity === null
                        || $item['quantity'] > $restockItem->approved_quantity,
                    422,
                    "{$restockItem->sku} exceeds its approved quantity.",
                );
            }
        }

        return [$supplier, $products, $restock, $restockItems];
    }

    private function replaceItems(int $purchaseOrder, array $items, Collection $products, Collection $restockItems): void
    {
        DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrder)
            ->delete();
        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $purchaseOrder,
                'product_id' => $product->id,
                'sku' => $product->sku,
                'quantity_ordered' => $item['quantity'],
                'quantity_received' => 0,
                'unit_cost' => round((float) $item['unit_cost'], 2),
                'restock_request_item_id' => $restockItems->get($product->id)?->id,
            ]);
        }
    }

    private function linkRestock(?object $restock, int $purchaseOrder, Collection $restockItems): void
    {
        if (! $restock) {
            return;
        }
        DB::table('restock_request_items')->whereIn('id', $restockItems->pluck('id'))->update(['status' => 'Purchase Order Created']);
        $this->restockStatuses->sync($restock->id);
    }

    private function releaseRestockIfChanged(object $order, ?int $nextRestockId): void
    {
        if (
            $order->restock_request_id
            && (int) $order->restock_request_id !== $nextRestockId
        ) {
            $itemIds = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->whereNotNull('restock_request_item_id')->pluck('restock_request_item_id');
            DB::table('restock_request_items')->whereIn('id', $itemIds)->where('status', 'Purchase Order Created')->update(['status' => 'Approved']);
            $this->restockStatuses->sync($order->restock_request_id);
        }
    }

    private function releaseRestockOrder(object $order): void
    {
        $itemIds = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->whereNotNull('restock_request_item_id')->pluck('restock_request_item_id');
        DB::table('restock_request_items')->whereIn('id', $itemIds)->whereIn('status', ['Purchase Order Created', 'Ordered'])->update(['status' => 'Approved']);
        $this->restockStatuses->sync($order->restock_request_id);
    }

    private function setRestockOrderStatus(object $order, string $from, string $to): void
    {
        $itemIds = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->whereNotNull('restock_request_item_id')->pluck('restock_request_item_id');
        DB::table('restock_request_items')->whereIn('id', $itemIds)->where('status', $from)->update(['status' => $to]);
        $this->restockStatuses->sync($order->restock_request_id);
    }

    private function summaryQuery()
    {
        return DB::table('purchase_orders')
            ->leftJoin('suppliers', 'purchase_orders.supplier_id', '=', 'suppliers.id')
            ->leftJoin(
                'restock_requests',
                'purchase_orders.restock_request_id',
                '=',
                'restock_requests.id',
            )
            ->select(
                'purchase_orders.*',
                'suppliers.name as supplier_name',
                'restock_requests.ref_number as restock_ref_number',
            )
            ->selectSub(
                DB::table('purchase_order_items')
                    ->selectRaw('ROUND(COALESCE(SUM(quantity_ordered * unit_cost), 0), 2)')
                    ->whereColumn(
                        'purchase_order_items.purchase_order_id',
                        'purchase_orders.id',
                    ),
                'total_amount',
            )
            ->selectSub(
                DB::table('purchase_order_items')
                    ->selectRaw('COALESCE(SUM(quantity_ordered), 0)')
                    ->whereColumn(
                        'purchase_order_items.purchase_order_id',
                        'purchase_orders.id',
                    ),
                'total_ordered',
            )
            ->selectSub(
                DB::table('purchase_order_items')
                    ->selectRaw('COALESCE(SUM(quantity_received), 0)')
                    ->whereColumn(
                        'purchase_order_items.purchase_order_id',
                        'purchase_orders.id',
                    ),
                'total_fulfilled',
            );
    }

    private function total(array $items): float
    {
        return round((float) collect($items)->sum(
            fn ($item) => $item['quantity'] * $item['unit_cost'],
        ), 2);
    }

    private function purchaseOrderNumber(): string
    {
        do {
            $number = 'PO-'.now()->format('Ymd').'-'.random_int(1000, 9999);
        } while (DB::table('purchase_orders')->where('po_number', $number)->exists());

        return $number;
    }
}
