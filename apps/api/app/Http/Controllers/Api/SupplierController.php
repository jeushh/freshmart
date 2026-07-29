<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);

        return Supplier::orderBy('name')->paginate($data['per_page'] ?? 15);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'contact_person' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'status' => 'required|in:Active,Inactive',
        ]);

        return response()->json(Supplier::create($data), 201);
    }
}
