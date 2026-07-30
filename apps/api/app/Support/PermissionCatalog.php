<?php

namespace App\Support;

final class PermissionCatalog
{
    public const GROUPS = [
        'System administration' => [
            'system.users.manage' => 'Manage user accounts',
            'system.roles.manage' => 'Manage roles and permissions',
            'system.audit.view' => 'View audit activity',
            'system.settings.manage' => 'Manage system settings',
        ],
        'Point of sale and sales' => [
            'pos.access' => 'Use point of sale',
            'pos.refund' => 'Process refunds',
            'sales.view' => 'View sales reporting',
        ],
        'Inventory and procurement' => [
            'inventory.manage' => 'Manage products and inventory',
            'restock.request' => 'Create and view restock requests',
            'restock.approve' => 'Approve or reject restock requests',
            'procurement.purchase_orders.view' => 'View purchase orders',
            'procurement.purchase_orders.manage' => 'Create and edit purchase orders',
            'procurement.purchase_orders.approve' => 'Approve or reject purchase orders',
            'procurement.stock.receive' => 'Receive purchase-order stock',
        ],
        'Human resources' => [
            'hr.employees.view' => 'View employee records',
            'hr.employees.edit' => 'Manage employee records',
            'hr.attendance.view' => 'View attendance',
            'hr.attendance.edit' => 'Manage attendance',
            'hr.requests.view' => 'View HR requests',
            'hr.requests.approve' => 'Approve or reject HR requests',
            'payroll.manage' => 'Manage payroll',
        ],
        'Finance' => [
            'finance.requests.view' => 'View finance requests',
            'finance.requests.approve' => 'Approve, reject, or pay finance requests',
            'finance.manage' => 'View finance ledgers and expenses',
        ],
        'Employee' => [
            'employee.self' => 'Use employee self-service',
        ],
    ];

    public const LANDING_PAGES = [
        'dashboard',
        'admin',
        'pos',
        'hr',
        'finance',
        'employee',
        'inventory',
    ];

    public static function all(): array
    {
        $permissions = [];

        foreach (self::GROUPS as $group) {
            $permissions = array_merge($permissions, array_keys($group));
        }

        return $permissions;
    }
}
