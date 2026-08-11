<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE supplier_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                accounts_payable_id INTEGER NOT NULL REFERENCES accounts_payable(id),
                supplier_id INTEGER REFERENCES suppliers(id),
                purchase_order_id INTEGER REFERENCES purchase_orders(id),
                supplier_invoice_id INTEGER REFERENCES supplier_invoices(id),
                amount REAL NOT NULL CHECK (amount > 0),
                payment_method TEXT NOT NULL,
                reference_number TEXT,
                payment_date TEXT NOT NULL,
                notes TEXT,
                idempotency_key TEXT NOT NULL,
                created_by TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (idempotency_key)
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_supplier_payments_payable '
            .'ON supplier_payments(accounts_payable_id)'
        );
        DB::statement(
            'CREATE INDEX idx_supplier_payments_supplier '
            .'ON supplier_payments(supplier_id)'
        );
        DB::statement(
            'CREATE INDEX idx_supplier_payments_date '
            .'ON supplier_payments(payment_date)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
