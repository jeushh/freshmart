<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceController extends Controller
{
    // -------------------------------------------------------------------------
    // READ
    // -------------------------------------------------------------------------

    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:Draft,Registered,Approved,Disputed,Void',
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = DB::table('supplier_invoices')
            ->join('suppliers', 'supplier_invoices.supplier_id', '=', 'suppliers.id')
            ->join('purchase_orders', 'supplier_invoices.purchase_order_id', '=', 'purchase_orders.id')
            ->select(
                'supplier_invoices.*',
                'suppliers.name as supplier_name',
                'purchase_orders.po_number',
            );

        if (isset($data['status'])) {
            $query->where('supplier_invoices.status', $data['status']);
        }
        if (isset($data['supplier_id'])) {
            $query->where('supplier_invoices.supplier_id', $data['supplier_id']);
        }

        return [
            'invoices' => $query->orderByDesc('supplier_invoices.id')->paginate($data['per_page'] ?? 20),
        ];
    }

    public function show(int $id)
    {
        $invoice = $this->findInvoiceWithRelations($id);
        abort_unless($invoice, 404);

        return [
            'invoice' => $invoice,
            'items' => $this->invoiceItems($id),
        ];
    }

    // -------------------------------------------------------------------------
    // CREATE (Draft)
    // -------------------------------------------------------------------------

    public function store(Request $request, int $purchaseOrder)
    {
        $data = $request->validate([
            'invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:200',
            'items.*.purchase_order_item_id' => 'required|integer|distinct',
            'items.*.invoiced_quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:999999999',
        ]);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $order = DB::table('purchase_orders')
                ->where('id', $purchaseOrder)
                ->lockForUpdate()
                ->first();
            abort_unless($order, 404);
            $this->assertInvoiceable($order);

            // supplier_id is always derived server-side from the PO
            $supplierId = $order->supplier_id;

            $this->assertUniqueInvoiceNumber($supplierId, $data['invoice_number'] ?? null);

            $items = $this->buildItems($data['items'], $purchaseOrder);

            $invoiceId = DB::table('supplier_invoices')->insertGetId([
                'purchase_order_id' => $purchaseOrder,
                'supplier_id' => $supplierId,
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'Draft',
                'created_by' => $request->user()->username,
            ]);

            $this->insertItems($invoiceId, $items);

            AuditLogger::record($request, 'supplier_invoice.created', 'supplier_invoice', $invoiceId, [
                'purchase_order_id' => $purchaseOrder,
                'supplier_id' => $supplierId,
                'invoice_number' => $data['invoice_number'] ?? null,
            ]);

            return response()->json($this->show($invoiceId), 201);
        });
    }

    // -------------------------------------------------------------------------
    // UPDATE (Draft / Disputed only)
    // -------------------------------------------------------------------------

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'invoice_number' => 'nullable|string|max:100',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1|max:200',
            'items.*.purchase_order_item_id' => 'required|integer|distinct',
            'items.*.invoiced_quantity' => 'required|integer|min:1|max:100000',
            'items.*.unit_cost' => 'required|numeric|min:0|max:999999999',
        ]);

        return DB::transaction(function () use ($data, $request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                in_array($invoice->status, ['Draft', 'Disputed'], true),
                409,
                "Supplier invoice cannot be edited in status {$invoice->status}.",
            );

            $order = DB::table('purchase_orders')
                ->where('id', $invoice->purchase_order_id)
                ->lockForUpdate()
                ->first();
            $this->assertInvoiceable($order);

            $newNumber = $data['invoice_number'] ?? null;
            if ($newNumber !== $invoice->invoice_number) {
                $this->assertUniqueInvoiceNumber($invoice->supplier_id, $newNumber, $id);
            }

            $items = $this->buildItems($data['items'], $invoice->purchase_order_id);

            // For Disputed edits, enforce ordered-quantity cap before persisting
            if ($invoice->status === 'Disputed') {
                $this->assertOrderedCap($items, $invoice->purchase_order_id, $id);
            }

            DB::table('supplier_invoices')->where('id', $id)->update([
                'invoice_number' => $newNumber,
                'invoice_date' => $data['invoice_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            DB::table('supplier_invoice_items')->where('supplier_invoice_id', $id)->delete();
            $this->insertItems($id, $items);

            AuditLogger::record($request, 'supplier_invoice.updated', 'supplier_invoice', $id, [
                'invoice_number' => $newNumber,
            ]);

            return $this->show($id);
        });
    }

    // -------------------------------------------------------------------------
    // TRANSITIONS
    // -------------------------------------------------------------------------

    public function register(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                in_array($invoice->status, ['Draft', 'Disputed'], true),
                409,
                "Cannot register invoice in status {$invoice->status}.",
            );

            $order = DB::table('purchase_orders')
                ->where('id', $invoice->purchase_order_id)
                ->lockForUpdate()
                ->first();
            $this->assertInvoiceable($order);

            // invoice_number required at registration
            abort_unless(
                ! empty($invoice->invoice_number),
                422,
                'Invoice number is required before registration.',
            );

            $items = DB::table('supplier_invoice_items')
                ->where('supplier_invoice_id', $id)
                ->get();
            abort_if($items->isEmpty(), 422, 'Invoice must have at least one line item.');

            // Lock PO items and check ordered-quantity cap
            $this->assertOrderedCapFromDb($items, $invoice->purchase_order_id, $id);

            DB::table('supplier_invoices')->where('id', $id)->update([
                'status' => 'Registered',
                'registered_by' => $request->user()->username,
                'registered_at' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::record($request, 'supplier_invoice.registered', 'supplier_invoice', $id, [
                'purchase_order_id' => $invoice->purchase_order_id,
            ]);

            return $this->show($id);
        });
    }

    public function approve(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                $invoice->status === 'Registered',
                409,
                "Cannot approve invoice in status {$invoice->status}.",
            );

            $order = DB::table('purchase_orders')
                ->where('id', $invoice->purchase_order_id)
                ->lockForUpdate()
                ->first();
            $this->assertInvoiceable($order);

            $items = DB::table('supplier_invoice_items')
                ->where('supplier_invoice_id', $id)
                ->get();

            // Three-way allocation: accepted received >= sum(approved) + this invoice
            $this->assertReceivedCoverage($items, $invoice->purchase_order_id, $id);

            // Compute server-authoritative total
            $total = round($items->sum('line_total'), 2);

            // Create exactly one AP row (unique index prevents duplicates)
            DB::table('accounts_payable')->insert([
                'supplier_invoice_id' => $id,
                'supplier_id' => $invoice->supplier_id,
                'purchase_order_id' => $invoice->purchase_order_id,
                'invoice_number' => $invoice->invoice_number,
                'total_amount' => $total,
                'amount_paid' => 0,
                'due_date' => $invoice->due_date,
                'status' => 'Unpaid',
            ]);

            DB::table('supplier_invoices')->where('id', $id)->update([
                'status' => 'Approved',
                'approved_by' => $request->user()->username,
                'approved_at' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::record($request, 'supplier_invoice.approved', 'supplier_invoice', $id, [
                'purchase_order_id' => $invoice->purchase_order_id,
                'total_amount' => $total,
            ]);

            return $this->show($id);
        });
    }

    public function dispute(Request $request, int $id)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);

        return DB::transaction(function () use ($data, $request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                $invoice->status === 'Registered',
                409,
                "Cannot dispute invoice in status {$invoice->status}.",
            );

            DB::table('supplier_invoices')->where('id', $id)->update([
                'status' => 'Disputed',
                'notes' => $data['notes'] ?? $invoice->notes,
            ]);

            AuditLogger::record($request, 'supplier_invoice.disputed', 'supplier_invoice', $id);

            return $this->show($id);
        });
    }

    public function resolveDispute(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                $invoice->status === 'Disputed',
                409,
                "Cannot resolve dispute for invoice in status {$invoice->status}.",
            );

            $order = DB::table('purchase_orders')
                ->where('id', $invoice->purchase_order_id)
                ->lockForUpdate()
                ->first();
            $this->assertInvoiceable($order);

            abort_unless(
                ! empty($invoice->invoice_number),
                422,
                'Invoice number is required before registration.',
            );

            $items = DB::table('supplier_invoice_items')
                ->where('supplier_invoice_id', $id)
                ->get();
            abort_if($items->isEmpty(), 422, 'Invoice must have at least one line item.');

            $this->assertOrderedCapFromDb($items, $invoice->purchase_order_id, $id);

            DB::table('supplier_invoices')->where('id', $id)->update([
                'status' => 'Registered',
                'registered_by' => $request->user()->username,
                'registered_at' => now()->format('Y-m-d H:i:s'),
            ]);

            AuditLogger::record($request, 'supplier_invoice.dispute_resolved', 'supplier_invoice', $id);

            return $this->show($id);
        });
    }

    public function void(Request $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $invoice = DB::table('supplier_invoices')->where('id', $id)->lockForUpdate()->first();
            abort_unless($invoice, 404);
            abort_unless(
                in_array($invoice->status, ['Draft', 'Registered', 'Disputed'], true),
                409,
                "Cannot void invoice in status {$invoice->status}.",
            );

            DB::table('supplier_invoices')->where('id', $id)->update(['status' => 'Void']);

            AuditLogger::record($request, 'supplier_invoice.voided', 'supplier_invoice', $id);

            return $this->show($id);
        });
    }

    // -------------------------------------------------------------------------
    // HELPERS
    // -------------------------------------------------------------------------

    private function assertInvoiceable(object $order): void
    {
        abort_unless(
            $order->approval_status === 'Approved'
                && $order->supplier_status === 'Accepted'
                && in_array($order->status, ['Ordered', 'Partially Received', 'Fully Received'], true),
            409,
            'Purchase order is not eligible for structured supplier invoicing.',
        );
    }

    private function assertUniqueInvoiceNumber(int $supplierId, ?string $number, ?int $excludeId = null): void
    {
        if ($number === null) {
            return;
        }
        $query = DB::table('supplier_invoices')
            ->where('supplier_id', $supplierId)
            ->where('invoice_number', $number);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        abort_if($query->exists(), 422, 'Invoice number already exists for this supplier.');
    }

    private function buildItems(array $rawItems, int $purchaseOrderId): array
    {
        $built = [];
        foreach ($rawItems as $item) {
            $line = DB::table('purchase_order_items')
                ->where('id', $item['purchase_order_item_id'])
                ->where('purchase_order_id', $purchaseOrderId)
                ->first();
            abort_unless($line, 422, 'A line item does not belong to this purchase order.');

            $product = DB::table('products')->where('id', $line->product_id)->first();
            abort_unless($product, 422, "Product {$line->sku} no longer exists.");

            $lineTotal = round((float) $item['invoiced_quantity'] * (float) $item['unit_cost'], 2);

            $built[] = [
                'purchase_order_item_id' => $line->id,
                'product_id' => $line->product_id,
                'sku' => $line->sku,
                'invoiced_quantity' => (int) $item['invoiced_quantity'],
                'unit_cost' => (float) $item['unit_cost'],
                'line_total' => $lineTotal,
                'po_unit_cost' => (float) $line->unit_cost,
            ];
        }

        return $built;
    }

    private function insertItems(int $invoiceId, array $items): void
    {
        foreach ($items as $item) {
            DB::table('supplier_invoice_items')->insert([
                'supplier_invoice_id' => $invoiceId,
                'purchase_order_item_id' => $item['purchase_order_item_id'],
                'product_id' => $item['product_id'],
                'sku' => $item['sku'],
                'invoiced_quantity' => $item['invoiced_quantity'],
                'unit_cost' => $item['unit_cost'],
                'line_total' => $item['line_total'],
            ]);
        }
    }

    /**
     * Ordered-quantity cap check using pre-built items array (for Draft edits).
     */
    private function assertOrderedCap(array $items, int $purchaseOrderId, int $excludeInvoiceId): void
    {
        foreach ($items as $item) {
            $poItem = DB::table('purchase_order_items')
                ->where('id', $item['purchase_order_item_id'])
                ->lockForUpdate()
                ->first();

            $committed = (int) DB::table('supplier_invoice_items')
                ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->where('supplier_invoice_items.purchase_order_item_id', $item['purchase_order_item_id'])
                ->whereIn('supplier_invoices.status', ['Registered', 'Disputed', 'Approved'])
                ->where('supplier_invoices.id', '!=', $excludeInvoiceId)
                ->sum('supplier_invoice_items.invoiced_quantity');

            abort_if(
                $committed + $item['invoiced_quantity'] > $poItem->quantity_ordered,
                422,
                "Invoiced quantity for SKU {$item['sku']} exceeds ordered quantity.",
            );
        }
    }

    /**
     * Ordered-quantity cap check reading items from DB (for register/resolve-dispute).
     */
    private function assertOrderedCapFromDb(object $items, int $purchaseOrderId, int $excludeInvoiceId): void
    {
        foreach ($items as $item) {
            $poItem = DB::table('purchase_order_items')
                ->where('id', $item->purchase_order_item_id)
                ->lockForUpdate()
                ->first();

            $committed = (int) DB::table('supplier_invoice_items')
                ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->where('supplier_invoice_items.purchase_order_item_id', $item->purchase_order_item_id)
                ->whereIn('supplier_invoices.status', ['Registered', 'Disputed', 'Approved'])
                ->where('supplier_invoices.id', '!=', $excludeInvoiceId)
                ->sum('supplier_invoice_items.invoiced_quantity');

            abort_if(
                $committed + $item->invoiced_quantity > $poItem->quantity_ordered,
                422,
                "Invoiced quantity for SKU {$item->sku} exceeds ordered quantity.",
            );
        }
    }

    /**
     * Three-way allocation: accepted received >= sum(approved) + this invoice.
     */
    private function assertReceivedCoverage(object $items, int $purchaseOrderId, int $invoiceId): void
    {
        foreach ($items as $item) {
            $poItem = DB::table('purchase_order_items')
                ->where('id', $item->purchase_order_item_id)
                ->lockForUpdate()
                ->first();

            // Accepted received = quantity_received on the PO item (project's authoritative semantics)
            $acceptedReceived = (int) $poItem->quantity_received;

            $alreadyApproved = (int) DB::table('supplier_invoice_items')
                ->join('supplier_invoices', 'supplier_invoice_items.supplier_invoice_id', '=', 'supplier_invoices.id')
                ->where('supplier_invoice_items.purchase_order_item_id', $item->purchase_order_item_id)
                ->where('supplier_invoices.status', 'Approved')
                ->where('supplier_invoices.id', '!=', $invoiceId)
                ->sum('supplier_invoice_items.invoiced_quantity');

            abort_if(
                $acceptedReceived < $alreadyApproved + $item->invoiced_quantity,
                409,
                "Insufficient accepted receiving for SKU {$item->sku}: "
                ."received={$acceptedReceived}, already_approved={$alreadyApproved}, "
                ."this_invoice={$item->invoiced_quantity}.",
            );
        }
    }

    private function findInvoiceWithRelations(int $id): ?object
    {
        return DB::table('supplier_invoices')
            ->join('suppliers', 'supplier_invoices.supplier_id', '=', 'suppliers.id')
            ->join('purchase_orders', 'supplier_invoices.purchase_order_id', '=', 'purchase_orders.id')
            ->where('supplier_invoices.id', $id)
            ->select(
                'supplier_invoices.*',
                'suppliers.name as supplier_name',
                'purchase_orders.po_number',
            )
            ->first();
    }

    private function invoiceItems(int $invoiceId): object
    {
        return DB::table('supplier_invoice_items')
            ->join('purchase_order_items', 'supplier_invoice_items.purchase_order_item_id', '=', 'purchase_order_items.id')
            ->where('supplier_invoice_items.supplier_invoice_id', $invoiceId)
            ->select(
                'supplier_invoice_items.*',
                'purchase_order_items.unit_cost as po_unit_cost',
            )
            ->get()
            ->map(function ($item) {
                $item->variance = round((float) $item->unit_cost - (float) $item->po_unit_cost, 2);

                return $item;
            });
    }
}
