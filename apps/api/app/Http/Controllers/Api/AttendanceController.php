<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'sometimes|integer|exists:employees,id',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $query = DB::table('attendance_logs')
            ->join('employees', 'attendance_logs.employee_id', '=', 'employees.id')
            ->select('attendance_logs.*', 'employees.full_name', 'employees.employee_no');

        if (isset($data['employee_id'])) {
            $query->where('attendance_logs.employee_id', $data['employee_id']);
        }
        if (isset($data['from'])) {
            $query->whereDate('log_date', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('log_date', '<=', $data['to']);
        }

        return $query->orderByDesc('log_date')->paginate($data['per_page'] ?? 20);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'log_date' => 'required|date',
            'status' => 'required|in:Present,Late,Absent,On Leave',
            'time_in' => 'nullable|date_format:H:i',
            'time_out' => 'nullable|date_format:H:i|after_or_equal:time_in',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::table('attendance_logs')->updateOrInsert(
            ['employee_id' => $data['employee_id'], 'log_date' => $data['log_date']],
            $data,
        );

        $row = DB::table('attendance_logs')
            ->where('employee_id', $data['employee_id'])
            ->where('log_date', $data['log_date'])
            ->first();

        AuditLogger::record($request, 'attendance.saved', 'attendance', $row->id, [
            'employee_id' => $data['employee_id'],
            'log_date' => $data['log_date'],
            'status' => $data['status'],
        ]);

        return response()->json($row, 201);
    }
}
