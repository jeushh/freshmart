<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestockRequestController extends Controller
{
    private const ACTIVE_STATUSES = [
        'Pending Approval',
        'Approved',
        'Purchase Order Created',
        'Ordered',
        'Partially Received',
    ];

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => [
                'sometimes',
                'in:Pending Approval,Approved,Rejected,Purchase Order Created,Ordered,Partially Received,Fully Received,Completed,Cancelled',
            ],
            'priority' => 'sometimes|in:Low,Normal,High,Urgent',
            'product_id' => 'sometimes|integer|exists:products,id',
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('restock_requests')
            ->join('products', 'restock_requests.product_id', '=', 'products.id')
            ->leftJoin('suppliers', 'restock_requests.supplier_id', '=', 'suppliers.id')
            ->select(
                'restock_requests.*',
                'products.name as product_name',
                'products.unit',
                'suppliers.name as supplier_name',
            );

        foreach (['status', 'priority', 'product_id'] as $filter) {
            if (isset($data[$filter])) {
                $query->where("restock_requests.{$filter}", $data[$filter]);
            }
        }
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($item) => $item
                ->where('restock_requests.ref_number', 'like', "%{$search}%")
                ->orWhere('restock_requests.sku', 'like', "%{$search}%")
                ->orWhere('products.name', 'like', "%{$search}%"));
        }

        return [
            'requests' => $query
                ->orderByDesc('restock_requests.id')
                ->paginate($data['per_page'] ?? 20),
            'products' => DB::table('products')
                ->where('status', 'Active')
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'sku',
                    'stock_quantity',
                    'reorder_level',
                    'max_stock',
                    'supplier_id',
                    'unit',
                ]),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'requested_quantity' => 'required|integer|min:1|max:100000',
            'priority' => 'required|in:Low,Normal,High,Urgent',
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $product = DB::table('products')
                ->where('id', $data['product_id'])
                ->lockForUpdate()
                ->first();
            abort_unless($product, 404);
            abort_if($product->status !== 'Active', 422, 'Only active products can be restocked.');
            abort_if(
                DB::table('restock_requests')
                    ->where('product_id', $product->id)
                    ->whereIn('status', self::ACTIVE_STATUSES)
                    ->exists(),
                409,
                'This product already has an active restock request.',
            );

            $id = DB::table('restock_requests')->insertGetId([
                'ref_number' => $this->referenceNumber(),
                'product_id' => $product->id,
                'sku' => $product->sku,
                'current_stock' => $product->stock_quantity,
                'reorder_level' => $product->reorder_level,
                'max_stock' => $product->max_stock,
                'recommended_quantity' => max(
                    1,
                    $product->max_stock - $product->stock_quantity,
                ),
                'requested_quantity' => $data['requested_quantity'],
                'supplier_id' => $product->supplier_id,
                'requested_by' => $request->user()->username,
                'priority' => $data['priority'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'status' => 'Pending Approval',
            ]);
            AuditLogger::record($request, 'restock_request.created', 'restock_request', $id, [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'requested_quantity' => $data['requested_quantity'],
            ]);

            return response()->json(DB::table('restock_requests')->find($id), 201);
        });
    }

    public function review(Request $request, int $restockRequest)
    {
        $data = $request->validate([
            'decision' => 'required|in:Approved,Rejected',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $restockRequest, $request) {
            $row = DB::table('restock_requests')
                ->where('id', $restockRequest)
                ->lockForUpdate()
                ->first();
            abort_unless($row, 404);
            abort_unless(
                $row->status === 'Pending Approval',
                409,
                "Restock request cannot move from {$row->status} to {$data['decision']}.",
            );

            DB::table('restock_requests')->where('id', $restockRequest)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->username,
                'reviewed_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? null,
            ]);
            AuditLogger::record(
                $request,
                'restock_request.'.strtolower($data['decision']),
                'restock_request',
                $restockRequest,
                [
                    'previous_status' => $row->status,
                    'notes' => $data['notes'] ?? null,
                ],
            );

            return DB::table('restock_requests')->find($restockRequest);
        });
    }

    private function referenceNumber(): string
    {
        do {
            $reference = 'RR-'.now()->format('Ymd').'-'.random_int(1000, 9999);
        } while (DB::table('restock_requests')->where('ref_number', $reference)->exists());

        return $reference;
    }
}
