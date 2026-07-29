<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:Draft,Pending Approval,Approved,Paid',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('payroll')
            ->join('employees', 'payroll.employee_id', '=', 'employees.id')
            ->select('payroll.*', 'employees.full_name', 'employees.employee_no');

        if (isset($data['status'])) {
            $query->where('payroll.status', $data['status']);
        }

        return $query->orderByDesc('pay_period_end')->paginate($data['per_page'] ?? 20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'pay_period_start' => [
                'required',
                'date',
                Rule::unique('payroll')->where(fn ($query) => $query
                    ->where('employee_id', $request->integer('employee_id'))
                    ->where('pay_period_end', $request->input('pay_period_end'))),
            ],
            'pay_period_end' => 'required|date|after_or_equal:pay_period_start',
            'regular_hours' => 'required|numeric|min:0',
            'overtime_hours' => 'required|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'bonuses' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
        ]);

        $employee = DB::table('employees')->find($data['employee_id']);
        abort_if($employee->employment_status === 'Terminated', 422, 'Payroll cannot be created for a terminated employee.');

        $basic = $employee->pay_type === 'Hourly'
            ? $data['regular_hours'] * $employee->hourly_rate
            : $employee->basic_salary / 2;
        $overtime = $data['overtime_hours'] * $employee->hourly_rate * 1.25;
        $net = $basic + $overtime + ($data['allowances'] ?? 0) + ($data['bonuses'] ?? 0) - ($data['deductions'] ?? 0);
        abort_if($net < 0, 422, 'Deductions cannot exceed gross pay.');

        $id = DB::table('payroll')->insertGetId($data + [
            'basic_salary' => round($basic, 2),
            'hourly_rate' => $employee->hourly_rate,
            'overtime_pay' => round($overtime, 2),
            'net_pay' => round($net, 2),
            'status' => 'Draft',
            'created_by' => $request->user()->username,
        ]);
        AuditLogger::record($request, 'payroll.created', 'payroll', $id);

        return response()->json(DB::table('payroll')->find($id), 201);
    }

    public function review(Request $request, int $payroll)
    {
        $data = $request->validate([
            'decision' => 'required|in:Approved,Paid',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $payroll, $request) {
            $row = DB::table('payroll')->where('id', $payroll)->lockForUpdate()->first();
            abort_unless($row, 404);

            $allowed = $data['decision'] === 'Approved'
                ? in_array($row->status, ['Draft', 'Pending Approval'], true)
                : $row->status === 'Approved';
            abort_unless($allowed, 409, "Payroll cannot move from {$row->status} to {$data['decision']}.");

            DB::table('payroll')->where('id', $payroll)->update([
                'status' => $data['decision'],
                'approved_by' => $request->user()->username,
                'paid_at' => $data['decision'] === 'Paid' ? now()->format('Y-m-d H:i:s') : null,
            ]);

            if ($data['decision'] === 'Paid') {
                DB::table('financial_transactions')->insert([
                    'transaction_type' => 'Payroll',
                    'amount' => $row->net_pay,
                    'direction' => 'Out',
                    'reference_type' => 'payroll',
                    'reference_id' => (string) $payroll,
                    'description' => "Payroll for employee {$row->employee_id}",
                    'category' => 'Payroll',
                    'created_by' => $request->user()->username,
                ]);
            }

            AuditLogger::record($request, 'payroll.'.strtolower($data['decision']), 'payroll', $payroll, [
                'previous_status' => $row->status,
                'notes' => $data['notes'] ?? null,
            ]);

            return DB::table('payroll')->find($payroll);
        });
    }
}
