<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN unit_price REAL '
            .'CHECK (unit_price IS NULL OR unit_price >= 0)',
        );
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN subtotal_amount REAL '
            .'CHECK (subtotal_amount IS NULL OR subtotal_amount >= 0)',
        );
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN tax_rate REAL '
            .'CHECK (tax_rate IS NULL OR (tax_rate >= 0 AND tax_rate <= 100))',
        );
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN tax_amount REAL '
            .'CHECK (tax_amount IS NULL OR tax_amount >= 0)',
        );
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN tax_inclusive INTEGER '
            .'CHECK (tax_inclusive IS NULL OR tax_inclusive IN (0, 1))',
        );
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN discount_amount REAL '
            .'CHECK (discount_amount IS NULL OR discount_amount >= 0)',
        );

        DB::statement(
            'CREATE INDEX idx_sales_timestamp ON sales_ledger(timestamp)',
        );
        DB::statement(
            'CREATE INDEX idx_sales_cashier_payment '
            .'ON sales_ledger(cashier_username, payment_method, timestamp)',
        );
        DB::statement(
            'CREATE INDEX idx_refunds_created ON refunds(created_at)',
        );
        DB::statement(
            'CREATE INDEX idx_po_reporting '
            .'ON purchase_orders(order_date, supplier_id, approval_status, status)',
        );
        DB::statement(
            'CREATE INDEX idx_receivings_date '
            .'ON stock_receivings(receiving_date, purchase_order_id)',
        );
        DB::statement(
            'CREATE INDEX idx_payroll_reporting '
            .'ON payroll(pay_period_end, status, employee_id)',
        );
        DB::statement(
            'CREATE INDEX idx_hr_reporting '
            .'ON hr_requests(created_at, status, employee_id)',
        );
        DB::statement(
            'CREATE INDEX idx_financial_reporting '
            .'ON financial_transactions(created_at, direction, category)',
        );
        DB::statement(
            'CREATE INDEX idx_payables_reporting '
            .'ON accounts_payable(status, due_date, supplier_id)',
        );
    }

    public function down(): void
    {
        foreach ([
            'idx_payables_reporting',
            'idx_financial_reporting',
            'idx_hr_reporting',
            'idx_payroll_reporting',
            'idx_receivings_date',
            'idx_po_reporting',
            'idx_refunds_created',
            'idx_sales_cashier_payment',
            'idx_sales_timestamp',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement('ALTER TABLE sales_ledger DROP COLUMN discount_amount');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN tax_inclusive');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN tax_amount');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN tax_rate');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN subtotal_amount');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN unit_price');
    }
};
