<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE purchase_orders ADD COLUMN supplier_status TEXT '
            ."CHECK (supplier_status IS NULL OR supplier_status IN ('Not Sent', 'Sent', 'Accepted', 'Rejected'))"
        );
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN sent_to_supplier_at TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN sent_by TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN supplier_responded_at TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN supplier_reference TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN supplier_response_notes TEXT');

        DB::statement('CREATE INDEX idx_po_supplier_status ON purchase_orders(supplier_status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_po_supplier_status');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN supplier_response_notes');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN supplier_reference');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN supplier_responded_at');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN sent_by');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN sent_to_supplier_at');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN supplier_status');
    }
};
