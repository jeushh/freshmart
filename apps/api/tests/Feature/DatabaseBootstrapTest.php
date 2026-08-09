<?php

namespace Tests\Feature;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'is_system' => 1,
        ]);
        $this->assertTrue(Schema::hasColumn('roles', 'is_system'));
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'approval_status'));
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'reviewed_by'));
        $this->assertTrue(Schema::hasColumn('sales_ledger', 'tax_rate'));
        $this->assertTrue(Schema::hasColumn('sales_ledger', 'tax_amount'));
        $this->assertTrue(Schema::hasColumn('sales_ledger', 'discount_amount'));
        $this->assertDatabaseHas('admin_users', [
            'username' => 'admin',
            'status' => 'Active',
        ]);
        $this->assertTrue(Hash::check(
            'test123',
            DB::table('admin_users')->where('username', 'admin')->value('password_hash'),
        ));
        $this->assertSame(3, DB::table('employees')->count());
        $this->assertSame(6, DB::table('suppliers')->count());
        $this->assertSame(50, DB::table('products')->count());
        $inventoryPermissions = json_decode(
            DB::table('roles')->where('name', 'Inventory Staff')->value('permissions'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertContains('procurement.purchase_orders.manage', $inventoryPermissions);
        $this->assertContains('procurement.stock.receive', $inventoryPermissions);
        $this->assertContains('reports.inventory.view', $inventoryPermissions);
        $this->assertContains('reports.procurement.view', $inventoryPermissions);
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'currency_code',
            'setting_value' => 'PHP',
        ]);
    }

    #[DataProvider('demoAccountUsernames')]
    public function test_every_local_demo_account_authenticates_with_classroom_password(
        string $username,
    ): void {
        $this->withHeaders([
            'Origin' => 'http://127.0.0.1:5173',
            'Referer' => 'http://127.0.0.1:5173/',
        ]);

        $this->postJson('/api/login', [
            'username' => $username,
            'password' => 'test123',
        ])->assertOk()
            ->assertJsonPath('user.username', $username);

        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
    }

    public static function demoAccountUsernames(): array
    {
        return [
            'administrator' => ['admin'],
            'cashier' => ['cashier'],
            'hr manager' => ['hr'],
            'finance manager' => ['finance'],
            'operations manager' => ['operations'],
            'inventory staff' => ['inventory'],
            'employee' => ['employee'],
        ];
    }

    public function test_seeders_are_repeatable_without_creating_duplicates(): void
    {
        $counts = $this->seededCounts();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($counts, $this->seededCounts());
    }

    public function test_demo_catalog_is_unique_linked_idempotent_and_preserves_unknown_products(): void
    {
        $this->assertSame(50, DB::table('products')->count());
        $this->assertSame(50, DB::table('products')->distinct()->count('sku'));
        $this->assertSame(0, DB::table('products')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
            ->whereNull('suppliers.id')
            ->count());

        $manualProduct = [
            'name' => 'Teacher Test Product',
            'sku' => 'CUSTOM-CLASSROOM-001',
            'price' => 123.45,
            'category' => 'Classroom',
            'stock_quantity' => 17,
            'unit' => 'piece',
            'emoji' => '🧪',
            'cost_price' => 80.25,
            'reorder_level' => 4,
            'min_stock' => 2,
            'max_stock' => 40,
            'supplier_id' => DB::table('suppliers')->value('id'),
            'status' => 'Inactive',
        ];
        DB::table('products')->insert($manualProduct);
        $beforeReseed = (array) DB::table('products')
            ->where('sku', $manualProduct['sku'])
            ->first();

        app(DemoDataSeeder::class)->run();
        app(DemoDataSeeder::class)->run();

        $this->assertSame(51, DB::table('products')->count());
        $this->assertSame(51, DB::table('products')->distinct()->count('sku'));
        $this->assertSame(
            $beforeReseed,
            (array) DB::table('products')->where('sku', $manualProduct['sku'])->first(),
        );
    }

    public function test_demo_seeder_is_a_true_no_op_when_disabled(): void
    {
        $this->setDemoSeedFlag(false);

        try {
            $this->assertFalse(filter_var(env('FRESHMART_SEED_DEMO'), FILTER_VALIDATE_BOOL));
            foreach ([
                'inventory_movements',
                'hr_requests',
                'finance_requests',
                'payroll',
                'attendance_logs',
                'admin_users',
                'products',
                'suppliers',
                'employees',
            ] as $table) {
                DB::table($table)->delete();
            }
            $this->assertSame(0, DB::table('products')->count());
            app(DemoDataSeeder::class)->run();

            $this->assertSame(0, DB::table('products')->count());
            $this->assertSame(0, DB::table('employees')->count());
            $this->assertSame(0, DB::table('suppliers')->count());
            $this->assertSame(0, DB::table('admin_users')->count());
            $this->assertSame(0, DB::table('attendance_logs')->count());
        } finally {
            $this->setDemoSeedFlag(true);
        }
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

    public function test_reporting_migration_preserves_existing_settings_and_fills_missing_defaults(): void
    {
        $migration = require database_path(
            'migrations/2026_07_30_000001_add_reporting_and_tax_metadata.php',
        );
        $migration->down();
        DB::table('system_settings')->whereIn('setting_key', [
            'currency_code',
            'currency_symbol',
            'currency_locale',
            'tax_rate',
            'business_contact',
        ])->delete();
        DB::table('system_settings')->where('setting_key', 'currency')
            ->update(['setting_value' => 'USD']);
        DB::table('system_settings')->where('setting_key', 'default_tax_rate')
            ->update(['setting_value' => '7.5']);
        DB::table('system_settings')->insert([
            'setting_key' => 'business_contact',
            'setting_value' => 'Keep this value',
        ]);

        $migration->up();

        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'currency_code',
            'setting_value' => 'USD',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'currency_symbol',
            'setting_value' => '$',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'tax_rate',
            'setting_value' => '7.5',
        ]);
        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'business_contact',
            'setting_value' => 'Keep this value',
        ]);
        $this->assertTrue(Schema::hasColumn('sales_ledger', 'tax_amount'));
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

    private function setDemoSeedFlag(bool $enabled): void
    {
        $value = $enabled ? 'true' : 'false';
        putenv("FRESHMART_SEED_DEMO={$value}");
        $_ENV['FRESHMART_SEED_DEMO'] = $value;
        $_SERVER['FRESHMART_SEED_DEMO'] = $value;
        if ($enabled) {
            Env::enablePutenv();
        } else {
            Env::disablePutenv();
        }
    }
}
