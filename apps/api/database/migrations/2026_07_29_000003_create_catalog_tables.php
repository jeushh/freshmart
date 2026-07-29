<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE suppliers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                contact_person TEXT,
                phone TEXT,
                email TEXT,
                address TEXT,
                status TEXT NOT NULL DEFAULT 'Active'
                    CHECK (status IN ('Active', 'Inactive')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_suppliers_name ON suppliers(name)');

        DB::statement(<<<'SQL'
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                sku TEXT NOT NULL UNIQUE,
                price REAL NOT NULL CHECK (price >= 0),
                category TEXT NOT NULL,
                stock_quantity INTEGER NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
                unit TEXT NOT NULL DEFAULT 'pc',
                emoji TEXT NOT NULL DEFAULT '🛒',
                cost_price REAL NOT NULL DEFAULT 0 CHECK (cost_price >= 0),
                reorder_level INTEGER NOT NULL DEFAULT 5 CHECK (reorder_level >= 0),
                min_stock INTEGER NOT NULL DEFAULT 0 CHECK (min_stock >= 0),
                max_stock INTEGER NOT NULL DEFAULT 100 CHECK (max_stock >= min_stock),
                supplier_id INTEGER REFERENCES suppliers(id),
                status TEXT NOT NULL DEFAULT 'Active'
                    CHECK (status IN ('Active', 'Inactive'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_products_category ON products(category)');
        DB::statement('CREATE INDEX idx_products_supplier ON products(supplier_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
    }
};
