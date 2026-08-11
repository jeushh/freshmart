<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountsPayableController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'sometimes|integer|exists:suppliers,id',
            'status' => 'sometimes|string|max:50',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = $this->baseQuery();

        if (isset($data['supplier_id'])) {
            $query->where('accounts_payable.supplier_id', $data['supplier_id']);
        }
        if (isset($data['status'])) {
            $query->where('accounts_payable.status', $data['status']);
        }

        $rows = $query->orderByDesc('accounts_payable.id')->paginate($data['per_page'] ?? 20);
        $rows->getCollection()->transform(fn ($row) => $this->decorate($row));

        return ['payables' => $rows];
    }

    public function show(int $id)
    {
        $row = $this->baseQuery()->where('accounts_payable.id', $id)->first();
        abort_unless($row, 404);

        return ['payable' => $this->decorate($row)];
    }

    private function baseQuery()
    {
        return DB::table('accounts_payable')
            ->leftJoin('suppliers', 'accounts_payable.supplier_id', '=', 'suppliers.id')
            ->leftJoin('purchase_orders', 'accounts_payable.purchase_order_id', '=', 'purchase_orders.id')
            ->leftJoin('supplier_invoices', 'accounts_payable.supplier_invoice_id', '=', 'supplier_invoices.id')
            ->select(
                'accounts_payable.id',
                'accounts_payable.supplier_id',
                'suppliers.name as supplier_name',
                'accounts_payable.purchase_order_id',
                'purchase_orders.po_number',
                'accounts_payable.invoice_number',
                'accounts_payable.supplier_invoice_id',
                'accounts_payable.total_amount',
                'accounts_payable.amount_paid',
                'accounts_payable.due_date',
                'accounts_payable.status',
                'accounts_payable.created_at',
            );
    }

    private function decorate(object $row): object
    {
        $totalCents = (int) round((float) $row->total_amount * 100, 0, PHP_ROUND_HALF_UP);
        $paidCents = (int) round((float) $row->amount_paid * 100, 0, PHP_ROUND_HALF_UP);
        $row->outstanding_balance = max(0, $totalCents - $paidCents) / 100;
        $row->source = $row->supplier_invoice_id !== null ? 'structured' : 'legacy';
        $row->overdue = $row->status !== 'Paid'
            && $row->due_date !== null
            && $row->due_date < now()->format('Y-m-d');

        return $row;
    }
}
