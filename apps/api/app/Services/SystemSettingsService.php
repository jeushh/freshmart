<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SystemSettingsService
{
    private const CACHE_KEY = 'freshmart.safe-settings.v1';

    private const DEFAULTS = [
        'business_name' => 'FreshMart',
        'business_address' => '',
        'business_contact' => '',
        'receipt_footer' => '',
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

    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 300, function (): array {
            $stored = DB::table('system_settings')
                ->whereIn('setting_key', array_keys(self::DEFAULTS))
                ->pluck('setting_value', 'setting_key')
                ->all();
            $values = array_merge(self::DEFAULTS, $stored);

            return [
                'business_name' => $values['business_name'],
                'business_address' => $values['business_address'],
                'business_contact' => $values['business_contact'],
                'receipt_footer' => $values['receipt_footer'],
                'currency_code' => $values['currency_code'],
                'currency_symbol' => $values['currency_symbol'],
                'currency_locale' => $values['currency_locale'],
                'timezone' => $values['timezone'],
                'tax_rate' => round((float) $values['tax_rate'], 2),
                'tax_inclusive' => filter_var(
                    $values['tax_inclusive'],
                    FILTER_VALIDATE_BOOL,
                ),
                'report_max_date_range_days' => (int) $values['report_max_date_range_days'],
                'backup_retention_count' => (int) $values['backup_retention_count'],
                'low_stock_alert_enabled' => filter_var(
                    $values['low_stock_alert_enabled'],
                    FILTER_VALIDATE_BOOL,
                ),
            ];
        });
    }

    public function public(): array
    {
        return $this->all();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function calculateTax(float $baseAmount): array
    {
        $settings = $this->all();
        $rate = (float) $settings['tax_rate'];
        $baseCents = (int) round($baseAmount * 100);

        if ($settings['tax_inclusive']) {
            $grossCents = $baseCents;
            $taxCents = $rate > 0
                ? (int) round($grossCents * $rate / (100 + $rate))
                : 0;
            $subtotalCents = $grossCents - $taxCents;
        } else {
            $subtotalCents = $baseCents;
            $taxCents = (int) round($subtotalCents * $rate / 100);
            $grossCents = $subtotalCents + $taxCents;
        }

        return [
            'subtotal' => $subtotalCents / 100,
            'tax' => $taxCents / 100,
            'total' => $grossCents / 100,
            'rate' => $rate,
            'inclusive' => (bool) $settings['tax_inclusive'],
        ];
    }
}
