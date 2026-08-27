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
