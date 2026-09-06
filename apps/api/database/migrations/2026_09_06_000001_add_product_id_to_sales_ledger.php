<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE sales_ledger ADD COLUMN product_id INTEGER '
            .'REFERENCES products(id)',
        );
        // Best-effort backfill for rows recorded before this column existed.
        // This still matches on item_sku, so it inherits the same SKU-reuse
        // ambiguity the rest of this change fixes going forward: any sale
        // whose SKU was later reused by a different product cannot be told
        // apart retroactively. New sales written after this migration always
        // capture product_id directly at checkout time and are exact.
        DB::statement(
            'UPDATE sales_ledger SET product_id = ('
            .'SELECT products.id FROM products '
            .'WHERE products.sku = sales_ledger.item_sku'
            .') WHERE product_id IS NULL',
        );
        DB::statement(
            'CREATE INDEX idx_sales_product ON sales_ledger(product_id)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_sales_product');
        DB::statement('ALTER TABLE sales_ledger DROP COLUMN product_id');
    }
};
