<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseBootstrapTest extends TestCase
{
    public function test_empty_database_rebuild_contains_the_complete_schema_and_seed_data(): void
    {
        $applicationTables = [
            'accounts_payable',
            'admin_users',
            'attendance_logs',
            'audit_logs',
            'cash_drawers',
            'employees',
            'expenses',
            'finance_requests',
            'financial_transactions',
            'hr_requests',
            'inventory_movements',
            'payroll',
            'products',
            'purchase_order_items',
            'purchase_orders',
            'refunds',
            'restock_requests',
            'roles',
            'sales_ledger',
            'stock_receiving_items',
            'stock_receivings',
            'suppliers',
            'system_settings',
        ];

        foreach ($applicationTables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        foreach ([
            'migrations',
            'password_reset_tokens',
            'personal_access_tokens',
            'sessions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing Laravel table: {$table}");
        }

        $this->assertSame(7, DB::table('roles')->count());
        $this->assertDatabaseHas('roles', [
            'name' => 'System Administrator',
            'landing_page' => 'admin',
        ]);
        $this->assertDatabaseHas('admin_users', [
            'username' => 'admin',
            'status' => 'Active',
        ]);
        $this->assertTrue(Hash::check(
            'testing-admin-password',
            DB::table('admin_users')->where('username', 'admin')->value('password_hash'),
        ));
        $this->assertSame(3, DB::table('employees')->count());
        $this->assertSame(3, DB::table('suppliers')->count());
        $this->assertSame(8, DB::table('products')->count());
    }

    public function test_seeders_are_repeatable_without_creating_duplicates(): void
    {
        $counts = $this->seededCounts();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($counts, $this->seededCounts());
    }

    public function test_rebuilt_database_has_foreign_keys_enabled_and_passes_sqlite_checks(): void
    {
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_all_baseline_migrations_roll_back_cleanly(): void
    {
        $this->artisan('migrate:rollback', ['--force' => true])->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('roles'));
        $this->assertFalse(Schema::hasTable('products'));
        $this->assertFalse(Schema::hasTable('financial_transactions'));
    }

    private function seededCounts(): array
    {
        return [
            'roles' => DB::table('roles')->count(),
            'admin_users' => DB::table('admin_users')->count(),
            'employees' => DB::table('employees')->count(),
            'suppliers' => DB::table('suppliers')->count(),
            'products' => DB::table('products')->count(),
            'attendance_logs' => DB::table('attendance_logs')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'hr_requests' => DB::table('hr_requests')->count(),
            'finance_requests' => DB::table('finance_requests')->count(),
            'payroll' => DB::table('payroll')->count(),
        ];
    }
}
