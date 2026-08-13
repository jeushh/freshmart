<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProcurementCloseStatusService
{
    public const AWAITING_DELIVERY = 'Open — Awaiting Delivery';

    public const AWAITING_INVOICE = 'Open — Awaiting Invoice';

    public const AWAITING_PAYMENT = 'Open — Awaiting Payment';

    public const COMPLETE = 'Complete';

    public function forPurchaseOrder(?int $purchaseOrderId): string
    {
        $order = $purchaseOrderId === null
            ? null
            : DB::table('purchase_orders')->where('id', $purchaseOrderId)->first();

        if (! $order || $order->status !== 'Fully Received') {
            return self::AWAITING_DELIVERY;
        }

        return $order->supplier_status === null
            ? $this->legacyStatus($order->id)
            : $this->structuredStatus($order->id);
    }

    private function legacyStatus(int $purchaseOrderId): string
    {
        $payables = DB::table('accounts_payable')
            ->where('purchase_order_id', $purchaseOrderId)
            ->whereNull('supplier_invoice_id')
            ->get(['total_amount', 'amount_paid']);

        if ($payables->isNotEmpty()) {
            return $payables->contains(
                fn ($payable) => $this->outstandingCents($payable) > 0,
            ) ? self::AWAITING_PAYMENT : self::COMPLETE;
        }

        $expectedCostCents = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->get(['quantity_ordered', 'unit_cost'])
            ->sum(fn ($item) => (int) $item->quantity_ordered * $this->cents($item->unit_cost));

        return $expectedCostCents > 0 ? self::AWAITING_PAYMENT : self::COMPLETE;
    }

    private function structuredStatus(int $purchaseOrderId): string
    {
        $items = DB::table('purchase_order_items')
            ->where('purchase_order_id', $purchaseOrderId)
            ->get(['id', 'quantity_ordered']);

        if ($items->isEmpty()) {
            return self::AWAITING_INVOICE;
        }

        $approvedCoverage = DB::table('supplier_invoice_items')
            ->join(
                'supplier_invoices',
                'supplier_invoice_items.supplier_invoice_id',
                '=',
                'supplier_invoices.id',
            )
            ->where('supplier_invoices.purchase_order_id', $purchaseOrderId)
            ->where('supplier_invoices.status', 'Approved')
            ->selectRaw(
                'supplier_invoice_items.purchase_order_item_id, '
                .'SUM(supplier_invoice_items.invoiced_quantity) as approved_quantity',
            )
            ->groupBy('supplier_invoice_items.purchase_order_item_id')
            ->pluck('approved_quantity', 'purchase_order_item_id');

        foreach ($items as $item) {
            if ((int) ($approvedCoverage[$item->id] ?? 0) < (int) $item->quantity_ordered) {
                return self::AWAITING_INVOICE;
            }
        }

        $approvedInvoiceIds = DB::table('supplier_invoices')
            ->where('purchase_order_id', $purchaseOrderId)
            ->where('status', 'Approved')
            ->pluck('id');

        $payables = DB::table('accounts_payable')
            ->whereIn('supplier_invoice_id', $approvedInvoiceIds)
            ->get(['supplier_invoice_id', 'total_amount', 'amount_paid'])
            ->keyBy('supplier_invoice_id');

        foreach ($approvedInvoiceIds as $invoiceId) {
            $payable = $payables->get($invoiceId);
            if (! $payable || $this->outstandingCents($payable) > 0) {
                return self::AWAITING_PAYMENT;
            }
        }

        return self::COMPLETE;
    }

    private function outstandingCents(object $payable): int
    {
        return max(0, $this->cents($payable->total_amount) - $this->cents($payable->amount_paid));
    }

    private function cents(float|int|string $amount): int
    {
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }
}
