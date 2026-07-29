<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE restock_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ref_number TEXT UNIQUE,
                product_id INTEGER NOT NULL REFERENCES products(id),
                sku TEXT NOT NULL,
                current_stock INTEGER NOT NULL CHECK (current_stock >= 0),
                reorder_level INTEGER NOT NULL CHECK (reorder_level >= 0),
                max_stock INTEGER NOT NULL CHECK (max_stock >= 0),
                recommended_quantity INTEGER NOT NULL CHECK (recommended_quantity > 0),
                requested_quantity INTEGER NOT NULL CHECK (requested_quantity > 0),
                supplier_id INTEGER REFERENCES suppliers(id),
                requested_by TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'Normal'
                    CHECK (priority IN ('Low', 'Normal', 'High', 'Urgent')),
                reason TEXT,
                notes TEXT,
                status TEXT NOT NULL DEFAULT 'Pending Approval' CHECK (
                    status IN (
                        'Pending Approval', 'Approved', 'Rejected',
                        'Purchase Order Created', 'Ordered', 'Partially Received',
                        'Fully Received', 'Completed', 'Cancelled'
                    )
                ),
                reviewed_by TEXT,
                reviewed_at TEXT,
                review_notes TEXT,
                purchase_order_id INTEGER REFERENCES purchase_orders(id),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('CREATE INDEX idx_restock_status ON restock_requests(status)');
        DB::statement('CREATE INDEX idx_restock_product ON restock_requests(product_id)');

        DB::statement(<<<'SQL'
            CREATE TABLE purchase_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                po_number TEXT UNIQUE,
                restock_request_id INTEGER REFERENCES restock_requests(id),
                supplier_id INTEGER REFERENCES suppliers(id),
                sku TEXT,
                quantity_ordered INTEGER CHECK (
                    quantity_ordered IS NULL OR quantity_ordered > 0
                ),
                order_date TEXT NOT NULL DEFAULT (datetime('now')),
                expected_delivery_date TEXT,
                notes TEXT,
                status TEXT NOT NULL DEFAULT 'Pending' CHECK (
                    status IN (
                        'Pending', 'Approved', 'Ordered', 'Partially Received',
                        'Fully Received', 'Cancelled'
                    )
                ),
                triggered_by_order_id TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                received_at TEXT
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_po_sku_status ON purchase_orders(sku, status)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE purchase_order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id),
                product_id INTEGER REFERENCES products(id),
                sku TEXT NOT NULL,
                quantity_ordered INTEGER NOT NULL CHECK (quantity_ordered > 0),
                quantity_received INTEGER NOT NULL DEFAULT 0
                    CHECK (quantity_received >= 0),
                unit_cost REAL NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_po_items_po ON purchase_order_items(purchase_order_id)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE stock_receivings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_order_id INTEGER NOT NULL REFERENCES purchase_orders(id),
                received_by TEXT NOT NULL,
                receiving_date TEXT NOT NULL DEFAULT (datetime('now')),
                notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_receivings_po ON stock_receivings(purchase_order_id)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE stock_receiving_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                stock_receiving_id INTEGER NOT NULL REFERENCES stock_receivings(id),
                purchase_order_item_id INTEGER REFERENCES purchase_order_items(id),
                product_id INTEGER NOT NULL REFERENCES products(id),
                sku TEXT NOT NULL,
                received_quantity INTEGER NOT NULL DEFAULT 0
                    CHECK (received_quantity >= 0),
                damaged_quantity INTEGER NOT NULL DEFAULT 0
                    CHECK (damaged_quantity >= 0),
                rejected_quantity INTEGER NOT NULL DEFAULT 0
                    CHECK (rejected_quantity >= 0),
                batch_no TEXT,
                expiration_date TEXT,
                unit_cost REAL NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_receiving_items_receiving '
            .'ON stock_receiving_items(stock_receiving_id)'
        );

        DB::statement(<<<'SQL'
            CREATE TABLE accounts_payable (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                supplier_id INTEGER REFERENCES suppliers(id),
                purchase_order_id INTEGER REFERENCES purchase_orders(id),
                invoice_number TEXT,
                total_amount REAL NOT NULL DEFAULT 0 CHECK (total_amount >= 0),
                amount_paid REAL NOT NULL DEFAULT 0 CHECK (
                    amount_paid >= 0 AND amount_paid <= total_amount
                ),
                due_date TEXT,
                status TEXT NOT NULL DEFAULT 'Unpaid'
                    CHECK (status IN ('Unpaid', 'Partially Paid', 'Paid', 'Overdue')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(
            'CREATE INDEX idx_ap_supplier ON accounts_payable(supplier_id, status)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_payable');
        Schema::dropIfExists('stock_receiving_items');
        Schema::dropIfExists('stock_receivings');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('restock_requests');
    }
};
