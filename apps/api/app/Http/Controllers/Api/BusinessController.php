<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    private function page($query, Request $request, int $default = 20)
    {
        return $query->paginate(min(100, max(1, $request->integer('per_page', $default))));
    }

    public function inventory(Request $request)
    {
        $request->validate([
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('products')
            ->leftJoin('suppliers', 'products.supplier_id', '=', 'suppliers.id')
            ->select('products.*', 'suppliers.name as supplier_name');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($item) => $item
                ->where('products.name', 'like', "%{$search}%")
                ->orWhere('products.sku', 'like', "%{$search}%"));
        }

        return response()->json([
            'products' => $this->page($query->orderBy('products.name'), $request),
            'suppliers' => DB::table('suppliers')->where('status', 'Active')->orderBy('name')->get(),
            'low_stock' => DB::table('products')
                ->where('status', 'Active')
                ->whereColumn('stock_quantity', '<=', 'reorder_level')
                ->count(),
            'low_stock_products' => DB::table('products')
                ->leftJoin('suppliers', 'products.supplier_id', '=', 'suppliers.id')
                ->where('products.status', 'Active')
                ->whereColumn('products.stock_quantity', '<=', 'products.reorder_level')
                ->orderBy('products.stock_quantity')
                ->orderBy('products.name')
                ->get([
                    'products.id',
                    'products.sku',
                    'products.name',
                    'products.stock_quantity',
                    'products.reorder_level',
                    'products.max_stock',
                    'products.unit',
                    'suppliers.name as supplier_name',
                ]),
            'inventory_movements' => DB::table('inventory_movements')
                ->leftJoin('products', 'inventory_movements.product_id', '=', 'products.id')
                ->orderByDesc('inventory_movements.id')
                ->limit(100)
                ->get([
                    'inventory_movements.id',
                    'inventory_movements.created_at',
                    'inventory_movements.sku',
                    'products.name as product_name',
                    'inventory_movements.movement_type',
                    'inventory_movements.quantity',
                    'inventory_movements.previous_stock',
                    'inventory_movements.new_stock',
                    'inventory_movements.reference_id',
                    'inventory_movements.performed_by',
                    'inventory_movements.notes',
                ]),
        ]);
    }

    public function saveProduct(Request $request, ?int $id = null)
    {
        if ($id !== null) {
            abort_unless(DB::table('products')->where('id', $id)->exists(), 404);
        }

        $data = $request->validate([
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
            'status' => 'required|in:Active,Inactive',
        ]);

        if ($id !== null) {
            DB::table('products')->where('id', $id)->update($data);
            AuditLogger::record($request, 'product.updated', 'product', $id);

            return DB::table('products')->find($id);
        }

        $id = DB::table('products')->insertGetId($data + ['emoji' => '🛒']);
        AuditLogger::record($request, 'product.created', 'product', $id);

        return response()->json(DB::table('products')->find($id), 201);
    }

    public function adjustStock(Request $request, int $id)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|not_in:0',
            'notes' => 'nullable|string|max:300',
        ]);

        return DB::transaction(function () use ($data, $id, $request) {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404);
            $newStock = $product->stock_quantity + $data['quantity'];
            abort_if($newStock < 0, 422, 'Stock cannot be negative.');

            DB::table('products')->where('id', $id)->update(['stock_quantity' => $newStock]);
            DB::table('inventory_movements')->insert([
                'product_id' => $id,
                'sku' => $product->sku,
                'movement_type' => 'Adjustment',
                'quantity' => $data['quantity'],
                'previous_stock' => $product->stock_quantity,
                'new_stock' => $newStock,
                'performed_by' => $request->user()->username,
                'notes' => $data['notes'] ?? null,
            ]);
            AuditLogger::record($request, 'inventory.adjusted', 'product', $id, [
                'quantity' => $data['quantity'],
                'previous_stock' => $product->stock_quantity,
                'new_stock' => $newStock,
            ]);

            return ['stock_quantity' => $newStock];
        });
    }

    public function financeRequests(Request $request)
    {
        $request->validate(['per_page' => 'sometimes|integer|min:1|max:100']);

        return [
            'requests' => $this->page(
                DB::table('finance_requests')
                    ->leftJoin('employees', 'finance_requests.employee_id', '=', 'employees.id')
                    ->select('finance_requests.*', 'employees.full_name')
                    ->orderByDesc('finance_requests.id'),
                $request,
            ),
        ];
    }

    public function financeOverview()
    {
        return [
            'expenses' => DB::table('expenses')->orderByDesc('id')->limit(50)->get(),
            'transactions' => DB::table('financial_transactions')->orderByDesc('id')->limit(50)->get(),
        ];
    }

    public function reviewFinance(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => 'required|in:Approved,Rejected,Paid',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $id, $request) {
            $row = DB::table('finance_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);

            $allowed = $data['decision'] === 'Paid'
                ? $row->status === 'Approved'
                : $row->status === 'Pending';
            abort_unless($allowed, 409, "Finance request cannot move from {$row->status} to {$data['decision']}.");

            DB::table('finance_requests')->where('id', $id)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? null,
            ]);

            if ($data['decision'] === 'Paid') {
                DB::table('financial_transactions')->insert([
                    'transaction_type' => 'Expense',
                    'amount' => $row->amount,
                    'direction' => 'Out',
                    'reference_type' => 'finance_request',
                    'reference_id' => (string) $id,
                    'description' => $row->description,
                    'category' => $row->category,
                    'created_by' => $request->user()->username,
                ]);
            }

            AuditLogger::record($request, 'finance_request.'.strtolower($data['decision']), 'finance_request', $id, [
                'previous_status' => $row->status,
                'notes' => $data['notes'] ?? null,
            ]);

            return DB::table('finance_requests')->find($id);
        });
    }

    public function pos(Request $request, SystemSettingsService $settings)
    {
        $user = $request->user();

        return [
            'products' => DB::table('products')
                ->where('status', 'Active')
                ->where('stock_quantity', '>', 0)
                ->orderBy('name')
                ->get([
                    'id',
                    'sku',
                    'name',
                    'category',
                    'price',
                    'stock_quantity',
                    'unit',
                    'emoji',
                ]),
            'sales' => DB::table('sales_ledger')
                ->when(
                    ! $user->hasAnyPermission('sales.view'),
                    fn ($query) => $query->where('cashier_username', $user->username),
                )
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'settings' => $settings->public(),
        ];
    }

    public function checkout(
        Request $request,
        SystemSettingsService $settings,
    ) {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'payment_method' => 'required|in:Cash,Card,QR',
        ]);

        return DB::transaction(function () use ($data, $request, $settings) {
            $orderId = 'FM-'.now()->format('YmdHis').'-'.random_int(100, 999);
            $totalCents = 0;
            $taxCents = 0;

            foreach ($data['items'] as $item) {
                // Canonical lock order: products first, then dependent ledger/refund rows.
                $product = DB::table('products')->where('id', $item['product_id'])->lockForUpdate()->first();
                abort_if($product->status !== 'Active', 422, "{$product->name} is not available for sale.");
                abort_if($product->stock_quantity < $item['quantity'], 422, "Insufficient stock for {$product->name}.");

                $tax = $settings->calculateTax(
                    (float) $product->price * $item['quantity'],
                );
                $lineTotal = $tax['total'];
                $totalCents += (int) round($lineTotal * 100);
                $taxCents += (int) round($tax['tax'] * 100);
                $newStock = $product->stock_quantity - $item['quantity'];

                DB::table('products')->where('id', $product->id)->update(['stock_quantity' => $newStock]);
                DB::table('sales_ledger')->insert([
                    'order_id' => $orderId,
                    'item_sku' => $product->sku,
                    'product_id' => $product->id,
                    'quantity_sold' => $item['quantity'],
                    'unit_price' => round((float) $product->price, 2),
                    'subtotal_amount' => $tax['subtotal'],
                    'total_price' => $lineTotal,
                    'tax_rate' => $tax['rate'],
                    'tax_amount' => $tax['tax'],
                    'tax_inclusive' => $tax['inclusive'] ? 1 : 0,
                    'discount_amount' => 0,
                    'payment_method' => $data['payment_method'],
                    'cashier_username' => $request->user()->username,
                ]);
                DB::table('inventory_movements')->insert([
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'movement_type' => 'Sale',
                    'quantity' => -$item['quantity'],
                    'previous_stock' => $product->stock_quantity,
                    'new_stock' => $newStock,
                    'reference_id' => $orderId,
                    'performed_by' => $request->user()->username,
                ]);
            }

            $total = $totalCents / 100;
            $taxTotal = $taxCents / 100;
            DB::table('financial_transactions')->insert([
                'transaction_type' => 'Sale',
                'amount' => $total,
                'direction' => 'In',
                'reference_type' => 'sale',
                'reference_id' => $orderId,
                'description' => 'POS sale',
                'payment_method' => $data['payment_method'],
                'created_by' => $request->user()->username,
            ]);
            AuditLogger::record($request, 'sale.completed', 'sale', $orderId, [
                'total' => $total,
                'tax_total' => $taxTotal,
                'payment_method' => $data['payment_method'],
            ]);

            return [
                'order_id' => $orderId,
                'total' => $total,
                'tax_total' => $taxTotal,
                'tax_rate' => $settings->all()['tax_rate'],
                'tax_inclusive' => $settings->all()['tax_inclusive'],
            ];
        });
    }

    public function admin(Request $request)
    {
        $user = $request->user()->loadMissing('role');

        return [
            'users' => $user->hasAnyPermission('system.users.manage')
                ? DB::table('admin_users')
                    ->join('roles', 'admin_users.role_id', '=', 'roles.id')
                    ->select('admin_users.id', 'admin_users.username', 'admin_users.full_name', 'admin_users.status', 'admin_users.last_login', 'roles.name as role_name')
                    ->orderBy('admin_users.username')
                    ->get()
                : [],
            'roles' => $user->hasAnyPermission('system.roles.manage', 'system.users.manage')
                ? DB::table('roles')->orderBy('name')->get()
                : [],
            'employees' => $user->hasAnyPermission('system.users.manage')
                ? DB::table('employees')
                    ->where('employment_status', '!=', 'Terminated')
                    ->select('id', 'employee_no', 'full_name')
                    ->orderBy('full_name')
                    ->get()
                : [],
            'settings' => $user->hasAnyPermission('system.settings.manage')
                ? DB::table('system_settings')->orderBy('setting_key')->get()
                : [],
            'audit' => $user->hasAnyPermission('system.audit.view')
                ? DB::table('audit_logs')->orderByDesc('id')->limit(100)->get()
                : [],
        ];
    }

    public function saveUser(Request $request, ?int $id = null)
    {
        if ($id !== null) {
            abort_unless(DB::table('admin_users')->where('id', $id)->exists(), 404);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:60', Rule::unique('admin_users', 'username')->ignore($id)],
            'full_name' => 'required|string|max:120',
            'role_id' => 'required|integer|exists:roles,id',
            'employee_id' => ['nullable', 'integer', 'exists:employees,id', Rule::unique('admin_users', 'employee_id')->ignore($id)],
            'status' => 'required|in:Active,Disabled',
            'password' => 'nullable|string|min:8|max:255',
        ]);
        $permissions = json_decode(
            DB::table('roles')->where('id', $data['role_id'])->value('permissions'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        abort_if(
            in_array('employee.self', $permissions, true) && empty($data['employee_id']),
            422,
            'An employee record is required for an employee self-service account.',
        );
        $password = $data['password'] ?? null;
        unset($data['password']);

        if ($password) {
            $data['password_hash'] = Hash::make($password);
        }

        if ($id !== null) {
            DB::table('admin_users')->where('id', $id)->update($data);
            AuditLogger::record($request, 'user.updated', 'user', $id, ['username' => $data['username']]);

            return $this->safeUser($id);
        }

        abort_unless($password, 422, 'Password is required for a new account.');
        $data['created_at'] = now()->format('Y-m-d H:i:s');
        $id = DB::table('admin_users')->insertGetId($data);
        AuditLogger::record($request, 'user.created', 'user', $id, ['username' => $data['username']]);

        return response()->json($this->safeUser($id), 201);
    }

    public function self(Request $request)
    {
        $user = $request->user();
        abort_unless($user->employee_id, 422, 'This account is not linked to an employee.');
        $profile = DB::table('employees')->find($user->employee_id);
        abort_unless($profile, 422, 'The linked employee record no longer exists.');

        return [
            'profile' => $profile,
            'hr_requests' => DB::table('hr_requests')->where('employee_id', $user->employee_id)->orderByDesc('id')->get(),
            'finance_requests' => DB::table('finance_requests')->where('employee_id', $user->employee_id)->orderByDesc('id')->get(),
            'attendance' => DB::table('attendance_logs')->where('employee_id', $user->employee_id)->orderByDesc('log_date')->limit(30)->get(),
        ];
    }

    public function submitSelf(Request $request)
    {
        $user = $request->user();
        abort_unless($user->employee_id, 422, 'This account is not linked to an employee.');
        abort_unless(DB::table('employees')->where('id', $user->employee_id)->exists(), 422, 'The linked employee record no longer exists.');

        $kind = $request->input('kind');
        $requestType = $request->input('request_type');
        $data = $request->validate([
            'kind' => 'required|in:hr,finance',
            'request_type' => [
                'required',
                Rule::when($kind === 'hr', Rule::in(['Leave', 'Overtime', 'Other'])),
                Rule::when($kind === 'finance', Rule::in(['Reimbursement', 'Purchase'])),
            ],
            'reason' => 'required|string|max:500',
            'amount' => [Rule::requiredIf($kind === 'finance'), 'nullable', 'numeric', 'min:0.01'],
            'start_date' => [Rule::requiredIf($kind === 'hr' && $requestType === 'Leave'), 'nullable', 'date'],
            'end_date' => [Rule::requiredIf($kind === 'hr' && $requestType === 'Leave'), 'nullable', 'date', 'after_or_equal:start_date'],
            'hours' => [Rule::requiredIf($kind === 'hr' && $requestType === 'Overtime'), 'nullable', 'numeric', 'min:0.25'],
        ]);

        if ($data['kind'] === 'hr') {
            $id = DB::table('hr_requests')->insertGetId([
                'employee_id' => $user->employee_id,
                'request_type' => $data['request_type'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'hours' => $data['hours'] ?? null,
                'reason' => $data['reason'],
                'status' => 'Pending',
            ]);
            AuditLogger::record($request, 'hr_request.created', 'hr_request', $id);
        } else {
            $id = DB::table('finance_requests')->insertGetId([
                'employee_id' => $user->employee_id,
                'request_type' => $data['request_type'],
                'amount' => $data['amount'],
                'category' => $data['request_type'],
                'description' => $data['reason'],
                'status' => 'Pending',
            ]);
            AuditLogger::record($request, 'finance_request.created', 'finance_request', $id);
        }

        return response()->json(['id' => $id], 201);
    }

    private function safeUser(int $id)
    {
        return DB::table('admin_users')
            ->where('id', $id)
            ->select('id', 'username', 'full_name', 'role_id', 'employee_id', 'status', 'created_at', 'last_login')
            ->first();
    }
}
