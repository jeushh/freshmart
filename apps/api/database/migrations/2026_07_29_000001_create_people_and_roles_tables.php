<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                permissions TEXT NOT NULL DEFAULT '[]',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                landing_page TEXT NOT NULL DEFAULT 'pos'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE employees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_no TEXT NOT NULL UNIQUE,
                full_name TEXT NOT NULL,
                position TEXT NOT NULL,
                department TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                hire_date TEXT NOT NULL,
                employment_status TEXT NOT NULL DEFAULT 'Active'
                    CHECK (employment_status IN ('Active', 'On Leave', 'Terminated')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                leave_balance REAL NOT NULL DEFAULT 15 CHECK (leave_balance >= 0),
                hourly_rate REAL NOT NULL DEFAULT 0 CHECK (hourly_rate >= 0),
                basic_salary REAL NOT NULL DEFAULT 0 CHECK (basic_salary >= 0),
                emergency_contact_name TEXT,
                emergency_contact_phone TEXT,
                pay_type TEXT NOT NULL DEFAULT 'Monthly'
                    CHECK (pay_type IN ('Monthly', 'Hourly'))
            )
        SQL);

        DB::statement('CREATE INDEX idx_employees_name ON employees(full_name)');
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('roles');
    }
};
