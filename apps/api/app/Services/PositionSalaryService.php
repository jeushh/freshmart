<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PositionSalaryService
{
    private const POSITIONS = [
        // Store Operations
        'Cashier' => ['department' => 'Store Operations', 'basic_salary' => 16000, 'hourly_rate' => 125],
        'Sales Associate' => ['department' => 'Store Operations', 'basic_salary' => 15000, 'hourly_rate' => 125],
        'Store Supervisor' => ['department' => 'Store Operations', 'basic_salary' => 22000, 'hourly_rate' => 165],
        'Store Manager' => ['department' => 'Store Operations', 'basic_salary' => 30000, 'hourly_rate' => 230.77],
        // Inventory & Warehouse
        'Inventory Clerk' => ['department' => 'Inventory & Warehouse', 'basic_salary' => 16000, 'hourly_rate' => 125],
        'Stock Clerk' => ['department' => 'Inventory & Warehouse', 'basic_salary' => 15000, 'hourly_rate' => 125],
        'Warehouse Staff' => ['department' => 'Inventory & Warehouse', 'basic_salary' => 16000, 'hourly_rate' => 125],
        'Warehouse Supervisor' => ['department' => 'Inventory & Warehouse', 'basic_salary' => 23000, 'hourly_rate' => 176.92],
        // Administration & HR
        'HR Assistant' => ['department' => 'Administration & HR', 'basic_salary' => 18000, 'hourly_rate' => 138.46],
        'HR Officer' => ['department' => 'Administration & HR', 'basic_salary' => 25000, 'hourly_rate' => 192.31],
        // Finance & Accounting
        'Accounting Assistant' => ['department' => 'Finance & Accounting', 'basic_salary' => 18000, 'hourly_rate' => 138.46],
        'Accountant' => ['department' => 'Finance & Accounting', 'basic_salary' => 30000, 'hourly_rate' => 230.77],
        // Purchasing
        'Purchasing Assistant' => ['department' => 'Purchasing', 'basic_salary' => 18000, 'hourly_rate' => 138.46],
        'Purchasing Officer' => ['department' => 'Purchasing', 'basic_salary' => 25000, 'hourly_rate' => 192.31],
    ];

    public static function getDepartmentPositions(string $department): array
    {
        return array_filter(self::POSITIONS, fn ($v) => $v['department'] === $department);
    }

    public static function getDepartmentNames(): array
    {
        return array_unique(array_column(self::POSITIONS, 'department'));
    }

    public static function getPositionSalary(string $position): ?float
    {
        return self::POSITIONS[$position]['basic_salary'] ?? null;
    }

    public static function getPositionDepartment(string $position): ?string
    {
        return self::POSITIONS[$position]['department'] ?? null;
    }

    public static function isValidCombination(string $department, string $position): bool
    {
        return isset(self::POSITIONS[$position]) && self::POSITIONS[$position]['department'] === $department;
    }
}