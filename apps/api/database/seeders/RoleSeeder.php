<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'System Administrator',
                'description' => 'Full system access.',
                'landing_page' => 'admin',
                'permissions' => [
                    'system.users.manage',
                    'system.roles.manage',
                    'system.audit.view',
                    'system.settings.manage',
                    'system.backups.manage',
                    'pos.access',
                    'pos.refund',
                    'sales.view',
                    'inventory.manage',
                    'restock.request',
                    'restock.approve',
                    'hr.employees.view',
                    'hr.employees.edit',
                    'hr.attendance.view',
                    'hr.attendance.edit',
                    'hr.requests.view',
                    'hr.requests.approve',
                    'payroll.manage',
                    'finance.requests.view',
                    'finance.requests.approve',
                    'finance.manage',
                    'employee.self',
                    'procurement.purchase_orders.view',
                    'procurement.purchase_orders.manage',
                    'procurement.purchase_orders.approve',
                    'procurement.stock.receive',
                    'reports.sales.view',
                    'reports.inventory.view',
                    'reports.procurement.view',
                    'reports.hr.view',
                    'reports.payroll.view',
                    'reports.finance.view',
                    'reports.export',
                ],
            ],
            [
                'name' => 'Cashier',
                'description' => 'Point-of-sale and permitted refund access.',
                'landing_page' => 'pos',
                'permissions' => [
                    'pos.access',
                    'pos.refund',
                ],
            ],
            [
                'name' => 'HR Manager',
                'description' => 'Employee, attendance, HR request, and payroll access.',
                'landing_page' => 'hr',
                'permissions' => [
                    'hr.employees.view',
                    'hr.employees.edit',
                    'hr.attendance.view',
                    'hr.attendance.edit',
                    'hr.requests.view',
                    'hr.requests.approve',
                    'payroll.manage',
                    'reports.hr.view',
                    'reports.payroll.view',
                    'reports.export',
                ],
            ],
            [
                'name' => 'Finance Manager',
                'description' => 'Finance request review and financial reporting access.',
                'landing_page' => 'finance',
                'permissions' => [
                    'finance.requests.view',
                    'finance.requests.approve',
                    'hr.employees.view',
                    'finance.manage',
                    'reports.sales.view',
                    'reports.payroll.view',
                    'reports.finance.view',
                    'reports.export',
                ],
            ],
            [
                'name' => 'Employee',
                'description' => 'Employee self-service access.',
                'landing_page' => 'employee',
                'permissions' => [
                    'employee.self',
                ],
            ],
            [
                'name' => 'Operations Manager',
                'description' => 'Restock approval and sales reporting access.',
                'landing_page' => 'inventory',
                'permissions' => [
                    'restock.approve',
                    'sales.view',
                    'procurement.purchase_orders.view',
                    'procurement.purchase_orders.approve',
                    'reports.inventory.view',
                    'reports.procurement.view',
                    'reports.export',
                ],
            ],
            [
                'name' => 'Inventory Staff',
                'description' => 'Product, stock, restock, purchasing, receiving, and inventory reporting access.',
                'landing_page' => 'inventory',
                'permissions' => [
                    'inventory.manage',
                    'restock.request',
                    'procurement.purchase_orders.view',
                    'procurement.purchase_orders.manage',
                    'procurement.stock.receive',
                    'reports.inventory.view',
                    'reports.procurement.view',
                    'reports.export',
                ],
            ],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                [
                    'description' => $role['description'],
                    'permissions' => json_encode(
                        $role['permissions'],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ),
                    'landing_page' => $role['landing_page'],
                    'is_system' => 1,
                ],
            );
        }
    }
}
