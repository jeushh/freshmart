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
            'business_contact' => '',
            'receipt_footer' => 'Thank you for shopping at FreshMart.',
            'system_name' => 'FreshMart System Administration Console',
            'currency' => 'PHP',
            'default_tax_rate' => '0',
            'currency_code' => 'PHP',
            'currency_symbol' => '₱',
            'currency_locale' => 'en-PH',
            'timezone' => 'Asia/Manila',
            'tax_rate' => '0',
            'tax_inclusive' => '1',
            'report_max_date_range_days' => '366',
            'backup_retention_count' => '10',
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
