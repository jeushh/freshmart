<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_order_items ADD COLUMN restock_request_item_id INTEGER REFERENCES restock_request_items(id)');
        DB::statement('CREATE INDEX idx_po_items_restock_item ON purchase_order_items(restock_request_item_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_po_items_restock_item');
    }
};
