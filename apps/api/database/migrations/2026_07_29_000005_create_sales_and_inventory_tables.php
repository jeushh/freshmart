<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE sales_ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                item_sku TEXT NOT NULL,
                quantity_sold INTEGER NOT NULL CHECK (quantity_sold > 0),
                total_price REAL NOT NULL CHECK (total_price >= 0),
                payment_method TEXT NOT NULL DEFAULT 'Cash',
                timestamp TEXT NOT NULL DEFAULT (datetime('now')),
                cashier_username TEXT,
                discount_type TEXT,
                discount_id_number TEXT
            )
        SQL);
        DB::statement('CREATE INDEX idx_ledger_order ON sales_ledger(order_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT NOT NULL,
                item_sku TEXT NOT NULL,
                quantity_refunded INTEGER NOT NULL CHECK (quantity_refunded > 0),
                refund_amount REAL NOT NULL CHECK (refund_amount >= 0),
                reason TEXT,
                processed_by TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_refunds_order ON refunds(order_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE inventory_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                product_id INTEGER NOT NULL REFERENCES products(id),
                sku TEXT NOT NULL,
                movement_type TEXT NOT NULL CHECK (
                    movement_type IN (
                        'Sale', 'Refund', 'Stock In', 'Stock Out',
                        'Adjustment', 'Purchase', 'Receiving'
                    )
                ),
                quantity INTEGER NOT NULL CHECK (quantity <> 0),
                previous_stock INTEGER NOT NULL CHECK (previous_stock >= 0),
                new_stock INTEGER NOT NULL CHECK (new_stock >= 0),
                reference_id TEXT,
                performed_by TEXT,
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_inv_move_product '
            .'ON inventory_movements(product_id, created_at)'
        );
        DB::statement(
            'CREATE INDEX idx_inv_move_type ON inventory_movements(movement_type)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE cash_drawers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                business_date TEXT NOT NULL UNIQUE,
                opened_by TEXT,
                opening_cash REAL NOT NULL DEFAULT 0 CHECK (opening_cash >= 0),
                cash_in REAL NOT NULL DEFAULT 0 CHECK (cash_in >= 0),
                cash_out REAL NOT NULL DEFAULT 0 CHECK (cash_out >= 0),
                actual_cash REAL CHECK (actual_cash IS NULL OR actual_cash >= 0),
                closed_by TEXT,
                status TEXT NOT NULL DEFAULT 'Open'
                    CHECK (status IN ('Open', 'Closed')),
                opened_at TEXT NOT NULL DEFAULT (datetime('now')),
                closed_at TEXT
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_drawers');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('sales_ledger');
    }
};
