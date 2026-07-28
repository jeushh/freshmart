<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller; use App\Models\Supplier; use Illuminate\Http\Request;
class SupplierController extends Controller {public function index(Request $r){return Supplier::orderBy('name')->paginate($r->integer('per_page',15));}public function store(Request $r){return response()->json(Supplier::create($r->validate(['name'=>'required|string|max:150','contact_person'=>'nullable|string|max:120','phone'=>'nullable|string|max:40','email'=>'nullable|email','address'=>'nullable|string|max:500','status'=>'required|in:active,inactive'])),201);}}
