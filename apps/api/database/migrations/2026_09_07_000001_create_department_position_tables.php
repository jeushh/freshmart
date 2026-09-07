<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE departments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE positions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                department_id INTEGER NOT NULL REFERENCES departments(id),
                name TEXT NOT NULL,
                basic_salary REAL NOT NULL CHECK (basic_salary >= 0),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (department_id, name)
            )
        SQL);

        $this->seedTaxonomy();
        $this->remapEmployees();

        Schema::table('employees', function ($table) {
            $table->integer('department_id')->nullable()->after('department');
            $table->integer('position_id')->nullable()->after('position');
        });

        // Re-map existing employees to new taxonomy.
        // We do this per-row to avoid SQLite limitations with
        // UPDATE … FROM referencing newly added columns.
        $employees = DB::table('employees')->get();

        foreach ($employees as $employee) {
            $dept = DB::table('departments')
                ->where('name', $employee->department)
                ->first();

            $pos = $dept
                ? DB::table('positions')
                    ->where('department_id', $dept->id)
                    ->where('name', $employee->position)
                    ->first()
                : null;

            if ($dept && $pos) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'department_id' => $dept->id,
                        'position_id' => $pos->id,
                    ]);
            }
        }

        DB::statement(<<<'SQL'
            CREATE INDEX idx_employees_department_id ON employees(department_id)
        SQL);
        DB::statement(<<<'SQL'
            CREATE INDEX idx_employees_position_id ON employees(position_id)
        SQL);
    }

    public function down(): void
    {
        // Drop indexes first, then columns, then tables (SQLite requirement).
        DB::statement('DROP INDEX IF EXISTS idx_employees_position_id');
        DB::statement('DROP INDEX IF EXISTS idx_employees_department_id');

        Schema::table('employees', function ($table) {
            $table->dropColumn(['department_id', 'position_id']);
        });
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }

    private function seedTaxonomy(): void
    {
        $departments = [
            'Store Operations',
            'Inventory & Warehouse',
            'Administration & HR',
            'Finance & Accounting',
            'Purchasing',
        ];

        foreach ($departments as $name) {
            DB::table('departments')->insert(['name' => $name]);
        }

        $positions = [
            // Store Operations
            ['department_id' => 1, 'name' => 'Cashier', 'basic_salary' => 16000],
            ['department_id' => 1, 'name' => 'Sales Associate', 'basic_salary' => 15000],
            ['department_id' => 1, 'name' => 'Store Supervisor', 'basic_salary' => 22000],
            ['department_id' => 1, 'name' => 'Store Manager', 'basic_salary' => 30000],
            // Inventory & Warehouse
            ['department_id' => 2, 'name' => 'Inventory Clerk', 'basic_salary' => 16000],
            ['department_id' => 2, 'name' => 'Stock Clerk', 'basic_salary' => 15000],
            ['department_id' => 2, 'name' => 'Warehouse Staff', 'basic_salary' => 16000],
            ['department_id' => 2, 'name' => 'Warehouse Supervisor', 'basic_salary' => 23000],
            // Administration & HR
            ['department_id' => 3, 'name' => 'HR Assistant', 'basic_salary' => 18000],
            ['department_id' => 3, 'name' => 'HR Officer', 'basic_salary' => 25000],
            // Finance & Accounting
            ['department_id' => 4, 'name' => 'Accounting Assistant', 'basic_salary' => 18000],
            ['department_id' => 4, 'name' => 'Accountant', 'basic_salary' => 30000],
            // Purchasing
            ['department_id' => 5, 'name' => 'Purchasing Assistant', 'basic_salary' => 18000],
            ['department_id' => 5, 'name' => 'Purchasing Officer', 'basic_salary' => 25000],
        ];

        foreach ($positions as $position) {
            DB::table('positions')->insert($position);
        }
    }

    private function remapEmployees(): void
    {
        $mapping = [
            'Operations' => [
                'Senior Cashier' => ['department' => 'Store Operations', 'position' => 'Cashier'],
                'Store Supervisor' => ['department' => 'Store Operations', 'position' => 'Store Supervisor'],
            ],
            'Inventory' => [
                'Inventory Clerk' => ['department' => 'Inventory & Warehouse', 'position' => 'Inventory Clerk'],
            ],
        ];

        foreach ($mapping as $oldDept => $positionMap) {
            foreach ($positionMap as $oldPosition => $new) {
                $dept = DB::table('departments')->where('name', $new['department'])->first();
                $pos = DB::table('positions')
                    ->where('department_id', $dept->id)
                    ->where('name', $new['position'])
                    ->first();

                DB::table('employees')
                    ->where('department', $oldDept)
                    ->where('position', $oldPosition)
                    ->update([
                        'department' => $new['department'],
                        'position' => $new['position'],
                    ]);
            }
        }

        // Now that the free-text columns are updated, populate the FK columns.
        $employees = DB::table('employees')->get();

        foreach ($employees as $employee) {
            $dept = DB::table('departments')
                ->where('name', $employee->department)
                ->first();

            $pos = $dept
                ? DB::table('positions')
                    ->where('department_id', $dept->id)
                    ->where('name', $employee->position)
                    ->first()
                : null;

            if ($dept && $pos) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update([
                        'department_id' => $dept->id,
                        'position_id' => $pos->id,
                    ]);
            }
        }
    }
};