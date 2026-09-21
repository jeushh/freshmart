<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\RestockRequestStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RestockRequestController extends Controller
{
    private const ACTIVE_STATUSES = [
        'Pending Approval',
        'Approved',
        'Purchase Order Created',
        'Ordered',
        'Partially Received',
    ];

    public function __construct(private readonly RestockRequestStatusService $statuses) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:Pending Approval,Approved,Rejected,Purchase Order Created,Ordered,Partially Received,Fully Received,Completed,Cancelled',
            'priority' => 'sometimes|in:Low,Normal,High,Urgent',
            'product_id' => 'sometimes|integer|exists:products,id',
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = DB::table('restock_requests')->select('restock_requests.*');

        foreach (['status', 'priority'] as $filter) {
            if (isset($data[$filter])) {
                $query->where("restock_requests.{$filter}", $data[$filter]);
            }
        }

        if (isset($data['product_id'])) {
            $query->whereExists(fn ($item) => $item
                ->selectRaw('1')
                ->from('restock_request_items')
                ->whereColumn('restock_request_items.restock_request_id', 'restock_requests.id')
                ->where('restock_request_items.product_id', $data['product_id']));
        }

        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($outer) => $outer
                ->where('restock_requests.ref_number', 'like', "%{$search}%")
                ->orWhereExists(fn ($item) => $item
                    ->selectRaw('1')
                    ->from('restock_request_items')
                    ->join('products', 'restock_request_items.product_id', '=', 'products.id')
                    ->whereColumn('restock_request_items.restock_request_id', 'restock_requests.id')
                    ->where(fn ($line) => $line
                        ->where('restock_request_items.sku', 'like', "%{$search}%")
                        ->orWhere('products.name', 'like', "%{$search}%"))));
        }

        $requests = $query->orderByDesc('restock_requests.id')->paginate($data['per_page'] ?? 20);
        $this->attachItems($requests->getCollection());

        return [
            'requests' => $requests,
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
        $payload = $request->all();

        // Legacy single-product body support: normalise into a one-line request.
        if (! isset($payload['items']) && isset($payload['product_id'])) {
            $payload['items'] = [[
                'product_id' => $payload['product_id'],
                'requested_quantity' => $payload['requested_quantity'] ?? null,
                'notes' => $payload['notes'] ?? null,
            ]];
        }

        $data = validator($payload, [
            'priority' => 'required|in:Low,Normal,High,Urgent',
            'reason' => 'required|string|max:500',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1|max:50',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.requested_quantity' => 'required|integer|min:1|max:100000',
            'items.*.notes' => 'nullable|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($data, $request) {
            $productIds = collect($data['items'])->pluck('product_id');
            $products = DB::table('products')
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                abort_if(
                    $product->status !== 'Active',
                    422,
                    "Only active products can be restocked: {$product->sku}.",
                );
                abort_if(
                    DB::table('restock_request_items')
                        ->where('product_id', $product->id)
                        ->whereIn('status', self::ACTIVE_STATUSES)
                        ->exists(),
                    409,
                    "{$product->sku} already has an active restock request.",
                );
            }

            $single = count($data['items']) === 1
                ? $products[$data['items'][0]['product_id']]
                : null;

            $id = DB::table('restock_requests')->insertGetId([
                'ref_number' => $this->referenceNumber(),
                'product_id' => $single?->id,
                'sku' => $single?->sku,
                'current_stock' => $single?->stock_quantity,
                'reorder_level' => $single?->reorder_level,
                'max_stock' => $single?->max_stock,
                'recommended_quantity' => $single
                    ? max(1, $single->max_stock - $single->stock_quantity)
                    : null,
                'requested_quantity' => $single
                    ? $data['items'][0]['requested_quantity']
                    : null,
                'supplier_id' => $single?->supplier_id,
                'requested_by' => $request->user()->username,
                'priority' => $data['priority'],
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'status' => 'Pending Approval',
            ]);

            foreach ($data['items'] as $item) {
                $product = $products[$item['product_id']];
                DB::table('restock_request_items')->insert([
                    'restock_request_id' => $id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'current_stock' => $product->stock_quantity,
                    'reorder_level' => $product->reorder_level,
                    'max_stock' => $product->max_stock,
                    'recommended_quantity' => max(
                        1,
                        $product->max_stock - $product->stock_quantity,
                    ),
                    'requested_quantity' => $item['requested_quantity'],
                    'supplier_id' => $product->supplier_id,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            AuditLogger::record($request, 'restock_request.created', 'restock_request', $id, [
                'item_count' => count($data['items']),
                'product_ids' => $productIds->values()->all(),
            ]);

            return response()->json($this->withItems($id), 201);
        });
    }

    public function review(Request $request, int $restockRequest)
    {
        $payload = $request->all();

        // Legacy header-level body support: one decision applied to every line.
        if (! isset($payload['decisions']) && isset($payload['decision'])) {
            $payload['legacy_decision'] = $payload['decision'];
        }

        $data = validator($payload, [
            'notes' => 'nullable|string|max:500',
            'legacy_decision' => 'nullable|in:Approved,Rejected',
            'decisions' => 'required_without:legacy_decision|array|min:1|max:50',
            'decisions.*.item_id' => 'required|integer|distinct',
            'decisions.*.decision' => 'required|in:Approved,Rejected',
            'decisions.*.approved_quantity' => 'nullable|integer|min:1',
            'decisions.*.notes' => 'nullable|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($data, $request, $restockRequest) {
            $header = DB::table('restock_requests')
                ->where('id', $restockRequest)
                ->lockForUpdate()
                ->first();
            abort_unless($header, 404);
            abort_unless(
                $header->status === 'Pending Approval',
                409,
                "Restock request cannot move from {$header->status}.",
            );

            $items = DB::table('restock_request_items')
                ->where('restock_request_id', $restockRequest)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $decisions = $data['decisions'] ?? $items
                ->map(fn ($item) => [
                    'item_id' => $item->id,
                    'decision' => $data['legacy_decision'],
                ])
                ->values()
                ->all();

            $provided = collect($decisions)->pluck('item_id')->map(fn ($id) => (int) $id);
            $foreign = $provided->diff($items->keys());
            abort_if(
                $foreign->isNotEmpty(),
                404,
                'A review item does not belong to this request.',
            );

            $missing = $items->where('status', 'Pending Approval')->keys()->diff($provided);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'decisions' => ['Missing decisions for item IDs: '.$missing->implode(', ').'.'],
                ]);
            }

            foreach ($decisions as $decision) {
                $item = $items[$decision['item_id']];
                $approved = $decision['decision'] === 'Approved';
                abort_if(
                    ! $approved
                        && array_key_exists('approved_quantity', $decision)
                        && $decision['approved_quantity'] !== null,
                    422,
                    "Rejected lines cannot carry an approved quantity: {$item->sku}.",
                );

                $quantity = $approved
                    ? ($decision['approved_quantity'] ?? $item->requested_quantity)
                    : null;
                abort_if(
                    $approved && $quantity > $item->requested_quantity,
                    422,
                    "Approved quantity cannot exceed requested quantity: {$item->sku}.",
                );

                DB::table('restock_request_items')->where('id', $item->id)->update([
                    'status' => $decision['decision'],
                    'approved_quantity' => $quantity,
                    'review_notes' => $decision['notes'] ?? null,
                ]);
            }

            DB::table('restock_requests')->where('id', $restockRequest)->update([
                'reviewed_by' => $request->user()->username,
                'reviewed_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? null,
            ]);
            $this->statuses->sync($restockRequest);

            AuditLogger::record(
                $request,
                'restock_request.reviewed',
                'restock_request',
                $restockRequest,
                ['decisions' => $decisions],
            );

            return $this->withItems($restockRequest);
        });
    }

    private function attachItems($headers): void
    {
        $items = DB::table('restock_request_items')
            ->leftJoin('products', 'restock_request_items.product_id', '=', 'products.id')
            ->leftJoin('suppliers', 'restock_request_items.supplier_id', '=', 'suppliers.id')
            ->whereIn('restock_request_items.restock_request_id', $headers->pluck('id'))
            ->orderBy('restock_request_items.id')
            ->get([
                'restock_request_items.*',
                'products.name as product_name',
                'products.unit',
                'suppliers.name as supplier_name',
            ])
            ->groupBy('restock_request_id');

        foreach ($headers as $header) {
            $header->items = $items[$header->id] ?? collect();
        }
    }

    private function withItems(int $id)
    {
        $header = DB::table('restock_requests')->find($id);
        $this->attachItems(collect([$header]));

        return $header;
    }

    private function referenceNumber(): string
    {
        do {
            $reference = 'RR-'.now()->format('Ymd').'-'.random_int(1000, 9999);
        } while (DB::table('restock_requests')->where('ref_number', $reference)->exists());

        return $reference;
    }
}
