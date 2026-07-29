<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = Product::query();

        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($item) => $item
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%"));
        }

        return $query->orderBy('name')->paginate($data['per_page'] ?? 20);
    }

    public function store(Request $request)
    {
        return response()->json(Product::create($this->validated($request)), 201);
    }

    public function update(Request $request, Product $product)
    {
        $product->update($this->validated($request, $product->id));

        return $product;
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($id)],
            'name' => 'required|string|max:150',
            'category' => 'required|string|max:80',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'required|numeric|min:0',
            'stock_quantity' => [
                Rule::prohibitedIf($id !== null),
                'sometimes',
                'integer',
                'min:0',
            ],
            'reorder_level' => 'required|integer|min:0',
            'unit' => 'required|string|max:20',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'emoji' => 'nullable|string|max:16',
            'status' => 'required|in:Active,Inactive',
        ]);
    }
}
