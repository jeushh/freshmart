<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE supplier_invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id),
                supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
                invoice_number TEXT,
                invoice_date TEXT,
                due_date TEXT,
                notes TEXT,
                status TEXT NOT NULL DEFAULT 'Draft'
                    CHECK (status IN ('Draft', 'Registered', 'Approved', 'Disputed', 'Void')),
                registered_by TEXT,
                registered_at TEXT,
                approved_by TEXT,
                approved_at TEXT,
                created_by TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE UNIQUE INDEX idx_si_supplier_invoice_number '
            .'ON supplier_invoices(supplier_id, invoice_number) '
            .'WHERE invoice_number IS NOT NULL'
        );
        DB::statement(
            'CREATE INDEX idx_si_purchase_order ON supplier_invoices(purchase_order_id)'
        );
        DB::statement(
            'CREATE INDEX idx_si_status ON supplier_invoices(status)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE supplier_invoice_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_invoice_id INTEGER NOT NULL REFERENCES supplier_invoices(id),
                purchase_order_item_id INTEGER NOT NULL REFERENCES purchase_order_items(id),
                product_id INTEGER NOT NULL REFERENCES products(id),
                sku TEXT NOT NULL,
                invoiced_quantity INTEGER NOT NULL CHECK (invoiced_quantity > 0),
                unit_cost REAL NOT NULL CHECK (unit_cost >= 0),
                line_total REAL NOT NULL CHECK (line_total >= 0),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_sii_invoice ON supplier_invoice_items(supplier_invoice_id)'
        );
        DB::statement(
            'CREATE INDEX idx_sii_po_item ON supplier_invoice_items(purchase_order_item_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
        Schema::dropIfExists('supplier_invoices');
    }
};
