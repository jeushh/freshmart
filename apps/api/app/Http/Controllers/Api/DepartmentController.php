<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = DB::table('departments')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($departments);
    }

    public function positions(Request $request, int $department)
    {
        $request->validate([
            'search' => 'sometimes|string|max:80',
        ]);

        $search = trim($request->query('search', ''));

        $query = DB::table('positions')
            ->where('department_id', $department)
            ->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $positions = $query->get(['id', 'name', 'basic_salary']);

        return response()->json($positions);
    }
}