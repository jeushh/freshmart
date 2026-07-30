<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'business_name' => 'FreshMart',
            'business_address' => '',
            'business_phone' => '',
            'business_email' => '',
            'system_name' => 'FreshMart System Administration Console',
            'currency' => 'PHP',
            'default_tax_rate' => '0',
            'low_stock_alert_enabled' => '1',
        ];

        foreach ($settings as $key => $value) {
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => $key],
                [
                    'setting_value' => $value,
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ],
            );
        }
    }
}
