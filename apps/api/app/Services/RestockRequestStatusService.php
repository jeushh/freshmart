<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RestockRequestStatusService
{
    public function sync(int $restockRequestId): void
    {
        $items = DB::table('restock_request_items')
            ->where('restock_request_id', $restockRequestId)
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        $statuses = $items->pluck('status');
        if ($statuses->contains('Pending Approval')) {
            $status = 'Pending Approval';
        } elseif ($statuses->every(fn ($value) => $value === 'Cancelled')) {
            $status = 'Cancelled';
        } elseif ($statuses->every(fn ($value) => $value === 'Rejected')) {
            $status = 'Rejected';
        } elseif ($items->where('status', '!=', 'Rejected')->every(fn ($item) => $item->status === 'Fully Received')) {
            $status = 'Fully Received';
        } elseif ($statuses->contains('Partially Received')) {
            $status = 'Partially Received';
        } elseif ($statuses->contains('Ordered')) {
            $status = 'Ordered';
        } elseif ($statuses->contains('Purchase Order Created')) {
            $status = 'Purchase Order Created';
        } else {
            $status = 'Approved';
        }

        $poIds = DB::table('purchase_order_items')
            ->whereIn('restock_request_item_id', $items->pluck('id'))
            ->distinct()
            ->pluck('purchase_order_id');
        $hasUnlinkedApprovedItem = $items->whereIn('status', ['Approved', 'Pending Approval'])->isNotEmpty();
        DB::table('restock_requests')->where('id', $restockRequestId)->update([
            'status' => $status,
            'purchase_order_id' => $poIds->count() === 1 && ! $hasUnlinkedApprovedItem ? $poIds->first() : null,
        ]);
    }
}
