<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! filter_var(
            env('FRESHMART_SEED_DEMO', app()->environment(['local', 'testing'])),
            FILTER_VALIDATE_BOOL,
        )) {
            return;
        }

        $this->seedEmployees();
        $this->seedSuppliers();
        $this->seedProducts();
        $this->seedAccounts();
        $this->seedAttendance();
        $this->seedRequestsAndPayroll();
    }

    private function seedEmployees(): void
    {
        $employees = [
            [
                'employee_no' => 'EMP-0001',
                'full_name' => 'Maria Santos',
                'position' => 'Senior Cashier',
                'department' => 'Operations',
                'email' => 'maria.santos@example.test',
                'phone' => '0917-555-0101',
                'hire_date' => '2024-03-15',
                'employment_status' => 'Active',
                'leave_balance' => 15,
                'hourly_rate' => 120,
                'basic_salary' => 20000,
                'emergency_contact_name' => 'Ramon Santos',
                'emergency_contact_phone' => '0917-555-0191',
                'pay_type' => 'Monthly',
            ],
            [
                'employee_no' => 'EMP-0002',
                'full_name' => 'Juan dela Cruz',
                'position' => 'Store Supervisor',
                'department' => 'Operations',
                'email' => 'juan.delacruz@example.test',
                'phone' => '0917-555-0102',
                'hire_date' => '2023-01-10',
                'employment_status' => 'Active',
                'leave_balance' => 12.5,
                'hourly_rate' => 180,
                'basic_salary' => 30000,
                'emergency_contact_name' => 'Elena dela Cruz',
                'emergency_contact_phone' => '0917-555-0192',
                'pay_type' => 'Monthly',
            ],
            [
                'employee_no' => 'EMP-0003',
                'full_name' => 'Leah Mendoza',
                'position' => 'Inventory Clerk',
                'department' => 'Inventory',
                'email' => 'leah.mendoza@example.test',
                'phone' => '0917-555-0103',
                'hire_date' => '2025-06-02',
                'employment_status' => 'Active',
                'leave_balance' => 10,
                'hourly_rate' => 135,
                'basic_salary' => 22000,
                'emergency_contact_name' => 'Paolo Mendoza',
                'emergency_contact_phone' => '0917-555-0193',
                'pay_type' => 'Monthly',
            ],
        ];

        foreach ($employees as $employee) {
            DB::table('employees')->updateOrInsert(
                ['employee_no' => $employee['employee_no']],
                $employee,
            );
        }
    }

    private function seedSuppliers(): void
    {
        $suppliers = [
            [
                'name' => 'Manila Fresh Produce Co.',
                'contact_person' => 'Ana Reyes',
                'phone' => '0917-100-2001',
                'email' => 'sales@manilafresh.example.test',
                'address' => 'Divisoria, Manila',
                'status' => 'Active',
            ],
            [
                'name' => 'Luzon Pantry Supplies',
                'contact_person' => 'Marco Villanueva',
                'phone' => '0917-100-2002',
                'email' => 'orders@luzonpantry.example.test',
                'address' => 'Quezon City, Metro Manila',
                'status' => 'Active',
            ],
            [
                'name' => 'Metro Beverage Distribution',
                'contact_person' => 'Joyce Lim',
                'phone' => '0917-100-2003',
                'email' => 'trade@metrobeverage.example.test',
                'address' => 'Pasig City, Metro Manila',
                'status' => 'Active',
            ],
        ];

        foreach ($suppliers as $supplier) {
            DB::table('suppliers')->updateOrInsert(
                ['name' => $supplier['name']],
                $supplier,
            );
        }
    }

    private function seedProducts(): void
    {
        $supplierIds = DB::table('suppliers')->pluck('id', 'name');
        $products = [
            [
                'sku' => 'FRU-001',
                'name' => 'Banana (Lakatan)',
                'price' => 65,
                'category' => 'Fruits',
                'stock_quantity' => 50,
                'unit' => 'kg',
                'emoji' => '🍌',
                'cost_price' => 42.25,
                'reorder_level' => 10,
                'min_stock' => 5,
                'max_stock' => 150,
                'supplier_id' => $supplierIds['Manila Fresh Produce Co.'],
                'status' => 'Active',
            ],
            [
                'sku' => 'FRU-002',
                'name' => 'Mango (Ripe)',
                'price' => 150,
                'category' => 'Fruits',
                'stock_quantity' => 24,
                'unit' => 'kg',
                'emoji' => '🥭',
                'cost_price' => 97.50,
                'reorder_level' => 8,
                'min_stock' => 4,
                'max_stock' => 60,
                'supplier_id' => $supplierIds['Manila Fresh Produce Co.'],
                'status' => 'Active',
            ],
            [
                'sku' => 'VEG-001',
                'name' => 'Tomato',
                'price' => 60,
                'category' => 'Vegetables',
                'stock_quantity' => 30,
                'unit' => 'kg',
                'emoji' => '🍅',
                'cost_price' => 39,
                'reorder_level' => 8,
                'min_stock' => 4,
                'max_stock' => 90,
                'supplier_id' => $supplierIds['Manila Fresh Produce Co.'],
                'status' => 'Active',
            ],
            [
                'sku' => 'VEG-002',
                'name' => 'Carrot',
                'price' => 75,
                'category' => 'Vegetables',
                'stock_quantity' => 20,
                'unit' => 'kg',
                'emoji' => '🥕',
                'cost_price' => 48.75,
                'reorder_level' => 6,
                'min_stock' => 3,
                'max_stock' => 60,
                'supplier_id' => $supplierIds['Manila Fresh Produce Co.'],
                'status' => 'Active',
            ],
            [
                'sku' => 'BAK-001',
                'name' => 'White Bread',
                'price' => 52,
                'category' => 'Bakery',
                'stock_quantity' => 18,
                'unit' => 'loaf',
                'emoji' => '🍞',
                'cost_price' => 34,
                'reorder_level' => 6,
                'min_stock' => 3,
                'max_stock' => 50,
                'supplier_id' => $supplierIds['Luzon Pantry Supplies'],
                'status' => 'Active',
            ],
            [
                'sku' => 'PAN-001',
                'name' => 'Premium Rice',
                'price' => 58,
                'category' => 'Pantry',
                'stock_quantity' => 100,
                'unit' => 'kg',
                'emoji' => '🍚',
                'cost_price' => 45,
                'reorder_level' => 25,
                'min_stock' => 10,
                'max_stock' => 250,
                'supplier_id' => $supplierIds['Luzon Pantry Supplies'],
                'status' => 'Active',
            ],
            [
                'sku' => 'BEV-001',
                'name' => 'Bottled Water',
                'price' => 25,
                'category' => 'Beverages',
                'stock_quantity' => 72,
                'unit' => 'bottle',
                'emoji' => '💧',
                'cost_price' => 14,
                'reorder_level' => 20,
                'min_stock' => 10,
                'max_stock' => 180,
                'supplier_id' => $supplierIds['Metro Beverage Distribution'],
                'status' => 'Active',
            ],
            [
                'sku' => 'BEV-002',
                'name' => 'Orange Juice',
                'price' => 90,
                'category' => 'Beverages',
                'stock_quantity' => 32,
                'unit' => 'bottle',
                'emoji' => '🧃',
                'cost_price' => 58,
                'reorder_level' => 10,
                'min_stock' => 5,
                'max_stock' => 80,
                'supplier_id' => $supplierIds['Metro Beverage Distribution'],
                'status' => 'Active',
            ],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                ['sku' => $product['sku']],
                $product,
            );

            $productId = DB::table('products')
                ->where('sku', $product['sku'])
                ->value('id');
            DB::table('inventory_movements')->updateOrInsert(
                [
                    'product_id' => $productId,
                    'reference_id' => 'DEMO-INITIAL-STOCK',
                ],
                [
                    'sku' => $product['sku'],
                    'movement_type' => 'Stock In',
                    'quantity' => $product['stock_quantity'],
                    'previous_stock' => 0,
                    'new_stock' => $product['stock_quantity'],
                    'performed_by' => 'database-seeder',
                    'notes' => 'Initial stock from repeatable demo data seeder.',
                ],
            );
        }
    }

    private function seedAccounts(): void
    {
        $password = $this->demoPassword();
        $passwordHash = Hash::make($password);
        $roles = DB::table('roles')->pluck('id', 'name');
        $employeeId = DB::table('employees')
            ->where('employee_no', 'EMP-0001')
            ->value('id');
        $accounts = [
            ['username' => 'cashier', 'full_name' => 'Demo Cashier', 'role' => 'Cashier'],
            ['username' => 'hr', 'full_name' => 'Demo HR Manager', 'role' => 'HR Manager'],
            ['username' => 'finance', 'full_name' => 'Demo Finance Manager', 'role' => 'Finance Manager'],
            ['username' => 'operations', 'full_name' => 'Demo Operations Manager', 'role' => 'Operations Manager'],
            ['username' => 'inventory', 'full_name' => 'Demo Inventory Staff', 'role' => 'Inventory Staff'],
            [
                'username' => 'employee',
                'full_name' => 'Maria Santos',
                'role' => 'Employee',
                'employee_id' => $employeeId,
            ],
        ];

        foreach ($accounts as $account) {
            DB::table('admin_users')->updateOrInsert(
                ['username' => $account['username']],
                [
                    'password_hash' => $passwordHash,
                    'full_name' => $account['full_name'],
                    'role_id' => $roles[$account['role']],
                    'employee_id' => $account['employee_id'] ?? null,
                    'status' => 'Active',
                ],
            );
        }
    }

    private function seedAttendance(): void
    {
        $employeeIds = DB::table('employees')->pluck('id', 'employee_no');
        $entries = [
            ['employee_no' => 'EMP-0001', 'log_date' => '2026-07-27', 'time_in' => '08:02', 'time_out' => '17:05', 'status' => 'Present'],
            ['employee_no' => 'EMP-0001', 'log_date' => '2026-07-28', 'time_in' => '08:18', 'time_out' => '17:10', 'status' => 'Late'],
            ['employee_no' => 'EMP-0002', 'log_date' => '2026-07-27', 'time_in' => '07:55', 'time_out' => '17:00', 'status' => 'Present'],
            ['employee_no' => 'EMP-0003', 'log_date' => '2026-07-27', 'time_in' => '08:00', 'time_out' => '17:03', 'status' => 'Present'],
        ];

        foreach ($entries as $entry) {
            $employeeNo = $entry['employee_no'];
            unset($entry['employee_no']);
            $entry['employee_id'] = $employeeIds[$employeeNo];
            DB::table('attendance_logs')->updateOrInsert(
                [
                    'employee_id' => $entry['employee_id'],
                    'log_date' => $entry['log_date'],
                ],
                $entry,
            );
        }
    }

    private function seedRequestsAndPayroll(): void
    {
        $employeeIds = DB::table('employees')->pluck('id', 'employee_no');

        DB::table('hr_requests')->updateOrInsert(
            [
                'employee_id' => $employeeIds['EMP-0001'],
                'request_type' => 'Leave',
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-11',
            ],
            [
                'hours' => null,
                'reason' => 'Family appointment',
                'status' => 'Pending',
            ],
        );

        DB::table('finance_requests')->updateOrInsert(
            [
                'employee_id' => $employeeIds['EMP-0001'],
                'request_type' => 'Reimbursement',
                'description' => 'Local delivery transport',
            ],
            [
                'amount' => 475.50,
                'category' => 'Transportation',
                'status' => 'Pending',
            ],
        );

        DB::table('payroll')->updateOrInsert(
            [
                'employee_id' => $employeeIds['EMP-0002'],
                'pay_period_start' => '2026-07-16',
                'pay_period_end' => '2026-07-31',
            ],
            [
                'basic_salary' => 15000,
                'hourly_rate' => 180,
                'regular_hours' => 80,
                'overtime_hours' => 2,
                'overtime_pay' => 450,
                'allowances' => 500,
                'bonuses' => 0,
                'deductions' => 750,
                'net_pay' => 15200,
                'status' => 'Draft',
                'created_by' => 'database-seeder',
                'pay_frequency' => 'Semi-monthly',
            ],
        );
    }

    private function demoPassword(): string
    {
        $password = env('FRESHMART_DEMO_PASSWORD');

        if (is_string($password) && trim($password) !== '') {
            return $password;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'FRESHMART_DEMO_PASSWORD must be set when demo seeding is enabled outside local or testing.',
            );
        }

        return 'FreshMart-Local-Only-2026!';
    }
}
