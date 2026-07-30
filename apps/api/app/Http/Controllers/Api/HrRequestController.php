<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HrRequestController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:Pending,Approved,Rejected',
            'request_type' => 'sometimes|in:Leave,Overtime,Other',
            'employee_id' => 'sometimes|integer|exists:employees,id',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = DB::table('hr_requests')
            ->join('employees', 'hr_requests.employee_id', '=', 'employees.id')
            ->leftJoin('admin_users', 'hr_requests.reviewed_by', '=', 'admin_users.id')
            ->select(
                'hr_requests.*',
                'employees.full_name',
                'employees.employee_no',
                'employees.leave_balance',
                'admin_users.full_name as reviewer_name',
            );

        foreach (['status', 'request_type', 'employee_id'] as $filter) {
            if (isset($data[$filter])) {
                $query->where("hr_requests.{$filter}", $data[$filter]);
            }
        }
        if (isset($data['from'])) {
            $query->whereDate('hr_requests.created_at', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('hr_requests.created_at', '<=', $data['to']);
        }

        return [
            'requests' => $query
                ->orderByDesc('hr_requests.id')
                ->paginate($data['per_page'] ?? 20),
            'employees' => DB::table('employees')
                ->where('employment_status', '!=', 'Terminated')
                ->orderBy('full_name')
                ->get(['id', 'employee_no', 'full_name']),
        ];
    }

    public function review(Request $request, int $hrRequest)
    {
        $data = $request->validate([
            'decision' => 'required|in:Approved,Rejected',
            'notes' => 'nullable|string|max:500',
        ]);

        return DB::transaction(function () use ($data, $hrRequest, $request) {
            $row = DB::table('hr_requests')
                ->where('id', $hrRequest)
                ->lockForUpdate()
                ->first();
            abort_unless($row, 404);
            abort_unless(
                $row->status === 'Pending',
                409,
                "HR request cannot move from {$row->status} to {$data['decision']}.",
            );

            $leaveDays = null;
            if ($data['decision'] === 'Approved' && $row->request_type === 'Leave') {
                abort_unless($row->start_date && $row->end_date, 422, 'Leave dates are required.');
                $startDate = CarbonImmutable::parse($row->start_date);
                $endDate = CarbonImmutable::parse($row->end_date);
                abort_if($endDate->isBefore($startDate), 422, 'Leave end date cannot precede its start date.');
                $leaveDays = $startDate->diffInDays($endDate) + 1;
                $employee = DB::table('employees')
                    ->where('id', $row->employee_id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($employee, 422, 'The employee record no longer exists.');
                abort_if(
                    $leaveDays > (float) $employee->leave_balance,
                    422,
                    'The employee does not have enough leave balance.',
                );
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'leave_balance' => round(
                            (float) $employee->leave_balance - $leaveDays,
                            2,
                        ),
                    ]);
            }

            DB::table('hr_requests')->where('id', $hrRequest)->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now()->format('Y-m-d H:i:s'),
                'review_notes' => $data['notes'] ?? null,
            ]);
            AuditLogger::record(
                $request,
                'hr_request.'.strtolower($data['decision']),
                'hr_request',
                $hrRequest,
                [
                    'previous_status' => $row->status,
                    'leave_days' => $leaveDays,
                    'notes' => $data['notes'] ?? null,
                ],
            );

            return DB::table('hr_requests')->find($hrRequest);
        });
    }
}
