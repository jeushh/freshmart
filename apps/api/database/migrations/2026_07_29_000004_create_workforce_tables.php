<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE attendance_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                log_date TEXT NOT NULL,
                time_in TEXT,
                time_out TEXT,
                status TEXT NOT NULL DEFAULT 'Present'
                    CHECK (status IN ('Present', 'Late', 'Absent', 'On Leave')),
                notes TEXT,
                UNIQUE (employee_id, log_date)
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_attendance_employee_date '
            .'ON attendance_logs(employee_id, log_date)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE hr_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                request_type TEXT NOT NULL
                    CHECK (request_type IN ('Leave', 'Overtime', 'Other')),
                start_date TEXT,
                end_date TEXT,
                hours REAL CHECK (hours IS NULL OR hours > 0),
                reason TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Pending'
                    CHECK (status IN ('Pending', 'Approved', 'Rejected')),
                reviewed_by INTEGER REFERENCES admin_users(id),
                reviewed_at TEXT,
                review_notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_hr_requests_status ON hr_requests(status)');
        DB::statement('CREATE INDEX idx_hr_requests_employee ON hr_requests(employee_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE payroll (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                pay_period_start TEXT NOT NULL,
                pay_period_end TEXT NOT NULL,
                basic_salary REAL NOT NULL DEFAULT 0 CHECK (basic_salary >= 0),
                hourly_rate REAL NOT NULL DEFAULT 0 CHECK (hourly_rate >= 0),
                regular_hours REAL NOT NULL DEFAULT 0 CHECK (regular_hours >= 0),
                overtime_hours REAL NOT NULL DEFAULT 0 CHECK (overtime_hours >= 0),
                overtime_pay REAL NOT NULL DEFAULT 0 CHECK (overtime_pay >= 0),
                allowances REAL NOT NULL DEFAULT 0 CHECK (allowances >= 0),
                bonuses REAL NOT NULL DEFAULT 0 CHECK (bonuses >= 0),
                deductions REAL NOT NULL DEFAULT 0 CHECK (deductions >= 0),
                net_pay REAL NOT NULL DEFAULT 0 CHECK (net_pay >= 0),
                status TEXT NOT NULL DEFAULT 'Draft'
                    CHECK (status IN ('Draft', 'Pending Approval', 'Approved', 'Paid')),
                created_by TEXT,
                approved_by TEXT,
                paid_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                pay_frequency TEXT NOT NULL DEFAULT 'Semi-monthly',
                UNIQUE (employee_id, pay_period_start, pay_period_end)
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_payroll_employee '
            .'ON payroll(employee_id, pay_period_start)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll');
        Schema::dropIfExists('hr_requests');
        Schema::dropIfExists('attendance_logs');
    }
};
