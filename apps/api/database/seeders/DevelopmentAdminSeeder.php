<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'System Administrator')
            ->value('id');

        if (! $roleId) {
            throw new RuntimeException('Run RoleSeeder before DevelopmentAdminSeeder.');
        }

        $username = $this->credential(
            'FRESHMART_ADMIN_USERNAME',
            'admin',
        );
        $password = $this->credential(
            'FRESHMART_ADMIN_PASSWORD',
            'FreshMart-Local-Only-2026!',
        );

        DB::table('admin_users')->updateOrInsert(
            ['username' => $username],
            [
                'password_hash' => Hash::make($password),
                'full_name' => (string) env(
                    'FRESHMART_ADMIN_NAME',
                    'System Administrator',
                ),
                'role_id' => $roleId,
                'employee_id' => null,
                'status' => 'Active',
            ],
        );
    }

    private function credential(string $name, string $localDefault): string
    {
        $value = env($name);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                "{$name} must be set before seeding outside local or testing.",
            );
        }

        return $localDefault;
    }
}
