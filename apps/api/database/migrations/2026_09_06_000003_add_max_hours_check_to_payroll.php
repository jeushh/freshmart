<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a 744-hour upper bound to the 5 payroll hour columns at the DB layer
 * (per DEVELOPMENT_LOG V57/V62). 744 = 31 days x 24h, the maximum physically
 * possible hours in any pay period regardless of frequency.
 *
 * The frontend has enforced this cap since V60 and PayrollController::store()
 * gains a matching `max:744` validation rule alongside this migration, but
 * neither of those stop a direct DB write (e.g. a raw insert, a future
 * import script, or a bug in another code path) from writing an
 * out-of-range value. This migration closes that gap at the data layer.
 *
 * SQLite has no ALTER TABLE support for adding/changing a CHECK constraint
 * on an existing column, so this rebuilds the table (SQLite's standard
 * 12-step pattern): create payroll_new with the extra upper-bound checks,
 * copy every row across unchanged, drop the old table, and rename the new
 * one into place. Existing rows are copied regardless of whether they
 * already exceed 744 on one of these columns -- this migration only
 * prevents new out-of-range writes going forward; it does not silently
 * mutate historical financial data. $withinTransaction is disabled because
 * SQLite refuses to toggle PRAGMA foreign_keys inside an active transaction.
 *
 * Columns capped at <= 744: regular_hours, overtime_hours,
 * rest_day_ot_hours, special_day_ot_hours, holiday_ot_hours.
 * The corresponding *_pay columns (computed money amounts, not hours) are
 * left as >= 0 only, unchanged.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COLUMNS = 'id, employee_id, pay_period_start, pay_period_end, basic_salary, '
        .'hourly_rate, regular_hours, overtime_hours, overtime_pay, allowances, bonuses, '
        .'deductions, net_pay, status, created_by, approved_by, paid_at, created_at, '
        .'pay_frequency, rest_day_ot_hours, rest_day_ot_pay, special_day_ot_hours, '
        .'special_day_ot_pay, holiday_ot_hours, holiday_ot_pay';

    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE payroll_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                pay_period_start TEXT NOT NULL,
                pay_period_end TEXT NOT NULL,
                basic_salary REAL NOT NULL DEFAULT 0 CHECK (basic_salary >= 0),
                hourly_rate REAL NOT NULL DEFAULT 0 CHECK (hourly_rate >= 0),
                regular_hours REAL NOT NULL DEFAULT 0
                    CHECK (regular_hours >= 0 AND regular_hours <= 744),
                overtime_hours REAL NOT NULL DEFAULT 0
                    CHECK (overtime_hours >= 0 AND overtime_hours <= 744),
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
                rest_day_ot_hours REAL NOT NULL DEFAULT 0
                    CHECK (rest_day_ot_hours >= 0 AND rest_day_ot_hours <= 744),
                rest_day_ot_pay REAL NOT NULL DEFAULT 0 CHECK (rest_day_ot_pay >= 0),
                special_day_ot_hours REAL NOT NULL DEFAULT 0
                    CHECK (special_day_ot_hours >= 0 AND special_day_ot_hours <= 744),
                special_day_ot_pay REAL NOT NULL DEFAULT 0 CHECK (special_day_ot_pay >= 0),
                holiday_ot_hours REAL NOT NULL DEFAULT 0
                    CHECK (holiday_ot_hours >= 0 AND holiday_ot_hours <= 744),
                holiday_ot_pay REAL NOT NULL DEFAULT 0 CHECK (holiday_ot_pay >= 0),
                UNIQUE (employee_id, pay_period_start, pay_period_end)
            )
        SQL);

        DB::statement('INSERT INTO payroll_new ('.self::COLUMNS.') SELECT '.self::COLUMNS.' FROM payroll');
        DB::statement('DROP TABLE payroll');
        DB::statement('ALTER TABLE payroll_new RENAME TO payroll');

        DB::statement('CREATE INDEX idx_payroll_employee ON payroll(employee_id, pay_period_start)');
        DB::statement(
            'CREATE INDEX idx_payroll_reporting '
            .'ON payroll(pay_period_end, status, employee_id)'
        );

        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE payroll_new (
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
                rest_day_ot_hours REAL NOT NULL DEFAULT 0 CHECK (rest_day_ot_hours >= 0),
                rest_day_ot_pay REAL NOT NULL DEFAULT 0 CHECK (rest_day_ot_pay >= 0),
                special_day_ot_hours REAL NOT NULL DEFAULT 0 CHECK (special_day_ot_hours >= 0),
                special_day_ot_pay REAL NOT NULL DEFAULT 0 CHECK (special_day_ot_pay >= 0),
                holiday_ot_hours REAL NOT NULL DEFAULT 0 CHECK (holiday_ot_hours >= 0),
                holiday_ot_pay REAL NOT NULL DEFAULT 0 CHECK (holiday_ot_pay >= 0),
                UNIQUE (employee_id, pay_period_start, pay_period_end)
            )
        SQL);

        DB::statement('INSERT INTO payroll_new ('.self::COLUMNS.') SELECT '.self::COLUMNS.' FROM payroll');
        DB::statement('DROP TABLE payroll');
        DB::statement('ALTER TABLE payroll_new RENAME TO payroll');

        DB::statement('CREATE INDEX idx_payroll_employee ON payroll(employee_id, pay_period_start)');
        DB::statement(
            'CREATE INDEX idx_payroll_reporting '
            .'ON payroll(pay_period_end, status, employee_id)'
        );

        DB::statement('PRAGMA foreign_keys=ON');
    }
};
