<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE restock_request_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                restock_request_id INTEGER NOT NULL REFERENCES restock_requests(id),
                product_id INTEGER NOT NULL REFERENCES products(id),
                sku TEXT NOT NULL,
                current_stock INTEGER NOT NULL CHECK (current_stock >= 0),
                reorder_level INTEGER NOT NULL CHECK (reorder_level >= 0),
                max_stock INTEGER NOT NULL CHECK (max_stock >= 0),
                recommended_quantity INTEGER NOT NULL CHECK (recommended_quantity > 0),
                requested_quantity INTEGER NOT NULL CHECK (requested_quantity > 0),
                approved_quantity INTEGER CHECK (approved_quantity IS NULL OR approved_quantity > 0),
                supplier_id INTEGER REFERENCES suppliers(id),
                status TEXT NOT NULL DEFAULT 'Pending Approval' CHECK (status IN (
                    'Pending Approval', 'Approved', 'Rejected',
                    'Purchase Order Created', 'Ordered', 'Partially Received',
                    'Fully Received', 'Completed', 'Cancelled'
                )),
                notes TEXT,
                review_notes TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (restock_request_id, product_id)
            )
        SQL);
        DB::statement('CREATE INDEX idx_restock_items_request ON restock_request_items(restock_request_id)');
        DB::statement('CREATE INDEX idx_restock_items_product_status ON restock_request_items(product_id, status)');

        DB::table('restock_requests')->orderBy('id')->each(function (object $request): void {
            DB::table('restock_request_items')->insert([
                'restock_request_id' => $request->id,
                'product_id' => $request->product_id,
                'sku' => $request->sku,
                'current_stock' => $request->current_stock,
                'reorder_level' => $request->reorder_level,
                'max_stock' => $request->max_stock,
                'recommended_quantity' => $request->recommended_quantity,
                'requested_quantity' => $request->requested_quantity,
                'approved_quantity' => in_array($request->status, [
                    'Approved', 'Purchase Order Created', 'Ordered', 'Partially Received', 'Fully Received', 'Completed',
                ], true) ? $request->requested_quantity : null,
                'supplier_id' => $request->supplier_id,
                'status' => $request->status === 'Completed' ? 'Fully Received' : $request->status,
                'notes' => $request->notes,
                'review_notes' => $request->review_notes,
                'created_at' => $request->created_at,
            ]);
        });

        abort_unless(
            DB::table('restock_requests')->count() === DB::table('restock_request_items')->count(),
            500,
            'Restock request item backfill did not preserve every request.',
        );

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement(<<<'SQL'
            CREATE TABLE restock_requests_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ref_number TEXT UNIQUE,
                product_id INTEGER REFERENCES products(id),
                sku TEXT,
                current_stock INTEGER CHECK (current_stock IS NULL OR current_stock >= 0),
                reorder_level INTEGER CHECK (reorder_level IS NULL OR reorder_level >= 0),
                max_stock INTEGER CHECK (max_stock IS NULL OR max_stock >= 0),
                recommended_quantity INTEGER CHECK (recommended_quantity IS NULL OR recommended_quantity > 0),
                requested_quantity INTEGER CHECK (requested_quantity IS NULL OR requested_quantity > 0),
                supplier_id INTEGER REFERENCES suppliers(id),
                requested_by TEXT NOT NULL,
                priority TEXT NOT NULL DEFAULT 'Normal' CHECK (priority IN ('Low', 'Normal', 'High', 'Urgent')),
                reason TEXT,
                notes TEXT,
                status TEXT NOT NULL DEFAULT 'Pending Approval' CHECK (status IN (
                    'Pending Approval', 'Approved', 'Rejected', 'Purchase Order Created', 'Ordered',
                    'Partially Received', 'Fully Received', 'Completed', 'Cancelled'
                )),
                reviewed_by TEXT,
                reviewed_at TEXT,
                review_notes TEXT,
                purchase_order_id INTEGER REFERENCES purchase_orders(id),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement('INSERT INTO restock_requests_new SELECT * FROM restock_requests');
        DB::statement('DROP TABLE restock_requests');
        DB::statement('ALTER TABLE restock_requests_new RENAME TO restock_requests');
        DB::statement('CREATE INDEX idx_restock_status ON restock_requests(status)');
        DB::statement('CREATE INDEX idx_restock_product ON restock_requests(product_id)');
        DB::statement('PRAGMA foreign_keys = ON');

        abort_if((bool) DB::selectOne('PRAGMA foreign_key_check'), 500, 'Restock request migration failed foreign-key validation.');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement(<<<'SQL'
            CREATE TABLE restock_requests_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT, ref_number TEXT UNIQUE,
                product_id INTEGER NOT NULL REFERENCES products(id), sku TEXT NOT NULL,
                current_stock INTEGER NOT NULL CHECK (current_stock >= 0), reorder_level INTEGER NOT NULL CHECK (reorder_level >= 0),
                max_stock INTEGER NOT NULL CHECK (max_stock >= 0), recommended_quantity INTEGER NOT NULL CHECK (recommended_quantity > 0),
                requested_quantity INTEGER NOT NULL CHECK (requested_quantity > 0), supplier_id INTEGER REFERENCES suppliers(id),
                requested_by TEXT NOT NULL, priority TEXT NOT NULL DEFAULT 'Normal' CHECK (priority IN ('Low', 'Normal', 'High', 'Urgent')),
                reason TEXT, notes TEXT, status TEXT NOT NULL DEFAULT 'Pending Approval' CHECK (status IN ('Pending Approval', 'Approved', 'Rejected', 'Purchase Order Created', 'Ordered', 'Partially Received', 'Fully Received', 'Completed', 'Cancelled')),
                reviewed_by TEXT, reviewed_at TEXT, review_notes TEXT, purchase_order_id INTEGER REFERENCES purchase_orders(id),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO restock_requests_old
            SELECT r.id, r.ref_number, i.product_id, i.sku, i.current_stock, i.reorder_level, i.max_stock,
                   i.recommended_quantity, i.requested_quantity, i.supplier_id, r.requested_by, r.priority, r.reason,
                   r.notes, r.status, r.reviewed_by, r.reviewed_at, r.review_notes, r.purchase_order_id, r.created_at
            FROM restock_requests r
            JOIN restock_request_items i ON i.id = (SELECT id FROM restock_request_items WHERE restock_request_id = r.id ORDER BY id LIMIT 1)
        SQL);
        DB::statement('DROP TABLE restock_requests');
        DB::statement('ALTER TABLE restock_requests_old RENAME TO restock_requests');
        DB::statement('CREATE INDEX idx_restock_status ON restock_requests(status)');
        DB::statement('CREATE INDEX idx_restock_product ON restock_requests(product_id)');
        DB::statement('DROP TABLE restock_request_items');
        DB::statement('PRAGMA foreign_keys = ON');
    }
};
