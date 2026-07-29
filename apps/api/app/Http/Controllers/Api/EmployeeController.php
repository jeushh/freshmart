<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'search' => 'sometimes|string|max:120',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('employees');

        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($item) => $item
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('employee_no', 'like', "%{$search}%"));
        }

        return $query->orderBy('full_name')->paginate($data['per_page'] ?? 20);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $id = DB::table('employees')->insertGetId($data);
        AuditLogger::record($request, 'employee.created', 'employee', $id);

        return response()->json(DB::table('employees')->find($id), 201);
    }

    public function update(Request $request, int $employee)
    {
        abort_unless(DB::table('employees')->where('id', $employee)->exists(), 404);
        DB::table('employees')->where('id', $employee)->update($this->validated($request, $employee));
        AuditLogger::record($request, 'employee.updated', 'employee', $employee);

        return DB::table('employees')->find($employee);
    }

    public function show(int $employee)
    {
        return DB::table('employees')->find($employee) ?? abort(404);
    }

    public function destroy(Request $request, int $employee)
    {
        abort_unless(DB::table('employees')->where('id', $employee)->exists(), 404);
        DB::table('employees')->where('id', $employee)->update(['employment_status' => 'Terminated']);
        AuditLogger::record($request, 'employee.terminated', 'employee', $employee);

        return response()->noContent();
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:40', Rule::unique('employees', 'employee_no')->ignore($id)],
            'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:40',
            'position' => 'nullable|string|max:80',
            'department' => 'nullable|string|max:80',
            'hire_date' => $id === null ? 'nullable|date' : 'sometimes|date',
            'status' => 'required|in:active,on_leave,terminated',
            'pay_type' => 'required|in:monthly,hourly',
            'basic_salary' => 'nullable|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
            'leave_balance' => 'nullable|numeric|min:0',
        ]);

        $record = [
            'employee_no' => $data['employee_code'],
            'full_name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'position' => ($data['position'] ?? null) ?: 'Staff',
            'department' => ($data['department'] ?? null) ?: 'General',
            'employment_status' => [
                'active' => 'Active',
                'on_leave' => 'On Leave',
                'terminated' => 'Terminated',
            ][$data['status']],
            'pay_type' => $data['pay_type'] === 'hourly' ? 'Hourly' : 'Monthly',
            'basic_salary' => $data['basic_salary'] ?? 0,
            'hourly_rate' => $data['hourly_rate'] ?? 0,
            'leave_balance' => $data['leave_balance'] ?? 15,
        ];

        if ($id === null) {
            $record['hire_date'] = $data['hire_date'] ?? now()->toDateString();
        } elseif (array_key_exists('hire_date', $data)) {
            $record['hire_date'] = $data['hire_date'];
        }

        return $record;
    }
}
