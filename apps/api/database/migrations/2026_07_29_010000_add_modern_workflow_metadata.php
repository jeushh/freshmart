<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE roles ADD COLUMN is_system INTEGER NOT NULL DEFAULT 0 '
            .'CHECK (is_system IN (0, 1))'
        );
        DB::table('roles')
            ->whereIn('name', [
                'System Administrator',
                'Cashier',
                'HR Manager',
                'Finance Manager',
                'Employee',
                'Operations Manager',
                'Inventory Staff',
            ])
            ->update(['is_system' => 1]);

        DB::statement(
            "ALTER TABLE purchase_orders ADD COLUMN approval_status TEXT NOT NULL DEFAULT 'Draft' "
            ."CHECK (approval_status IN ('Draft', 'Submitted', 'Approved', 'Rejected', 'Cancelled'))"
        );
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN created_by TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN submitted_at TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN reviewed_by TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN reviewed_at TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN review_notes TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN cancelled_by TEXT');
        DB::statement('ALTER TABLE purchase_orders ADD COLUMN cancelled_at TEXT');

        DB::statement(<<<'SQL'
            UPDATE purchase_orders
            SET approval_status = CASE
                WHEN status IN ('Approved', 'Ordered', 'Partially Received', 'Fully Received')
                    THEN 'Approved'
                WHEN status = 'Cancelled' THEN 'Cancelled'
                ELSE 'Draft'
            END
        SQL);
        DB::statement(
            'CREATE INDEX idx_po_approval_status ON purchase_orders(approval_status, status)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_po_approval_status');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN cancelled_at');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN cancelled_by');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN review_notes');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN reviewed_at');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN reviewed_by');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN submitted_at');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN created_by');
        DB::statement('ALTER TABLE purchase_orders DROP COLUMN approval_status');
        DB::statement('ALTER TABLE roles DROP COLUMN is_system');
    }
};
