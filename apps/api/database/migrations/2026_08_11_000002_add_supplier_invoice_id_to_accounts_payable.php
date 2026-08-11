<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE accounts_payable ADD COLUMN supplier_invoice_id INTEGER '
            .'REFERENCES supplier_invoices(id)'
        );
        DB::statement(
            'CREATE UNIQUE INDEX idx_ap_supplier_invoice_id '
            .'ON accounts_payable(supplier_invoice_id) '
            .'WHERE supplier_invoice_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_ap_supplier_invoice_id');
        DB::statement('ALTER TABLE accounts_payable DROP COLUMN supplier_invoice_id');
    }
};
