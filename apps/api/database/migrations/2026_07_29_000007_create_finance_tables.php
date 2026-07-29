<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE finance_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL REFERENCES employees(id),
                request_type TEXT NOT NULL
                    CHECK (request_type IN ('Reimbursement', 'Purchase')),
                amount REAL NOT NULL CHECK (amount > 0),
                category TEXT,
                description TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'Pending'
                    CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Paid')),
                reviewed_by INTEGER REFERENCES admin_users(id),
                reviewed_at TEXT,
                review_notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_finance_requests_status ON finance_requests(status)'
        );
        DB::statement(
            'CREATE INDEX idx_finance_requests_employee ON finance_requests(employee_id)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category TEXT NOT NULL,
                amount REAL NOT NULL CHECK (amount >= 0),
                description TEXT NOT NULL,
                expense_date TEXT NOT NULL DEFAULT (date('now')),
                requested_by TEXT NOT NULL,
                approved_by TEXT,
                status TEXT NOT NULL DEFAULT 'Pending'
                    CHECK (status IN ('Pending', 'Approved', 'Rejected')),
                payment_status TEXT NOT NULL DEFAULT 'Unpaid'
                    CHECK (payment_status IN ('Unpaid', 'Paid')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_expenses_status ON expenses(status)');

        DB::statement(<<<'SQL'
            CREATE TABLE financial_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_type TEXT NOT NULL CHECK (
                    transaction_type IN (
                        'Sale', 'Refund', 'Purchase', 'Supplier Payment',
                        'Payroll', 'Expense', 'Adjustment'
                    )
                ),
                amount REAL NOT NULL CHECK (amount >= 0),
                direction TEXT NOT NULL CHECK (direction IN ('In', 'Out')),
                reference_type TEXT,
                reference_id TEXT,
                description TEXT,
                category TEXT,
                payment_method TEXT,
                created_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_fin_txn_type '
            .'ON financial_transactions(transaction_type, created_at)'
        );
        DB::statement(
            'CREATE INDEX idx_fin_txn_reference '
            .'ON financial_transactions(reference_type, reference_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('finance_requests');
    }
};
