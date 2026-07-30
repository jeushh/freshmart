<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function store(Request $request, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'order_id' => 'required|string|max:100',
            'item_sku' => 'required|string|max:60',
            'quantity' => 'required|integer|min:1|max:100000',
            'reason' => 'required|string|max:500',
        ]);

        return response()->json($refunds->create($request, $data), 201);
    }
}
