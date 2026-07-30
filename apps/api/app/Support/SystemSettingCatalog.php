<?php

namespace App\Support;

final class SystemSettingCatalog
{
    public const FIELDS = [
        'business_name' => [
            'group' => 'Business',
            'label' => 'Business name',
            'type' => 'text',
            'rules' => ['required', 'string', 'max:120'],
        ],
        'business_address' => [
            'group' => 'Business',
            'label' => 'Business address',
            'type' => 'textarea',
            'rules' => ['nullable', 'string', 'max:500'],
        ],
        'business_phone' => [
            'group' => 'Business',
            'label' => 'Business phone',
            'type' => 'text',
            'rules' => ['nullable', 'string', 'max:40'],
        ],
        'business_email' => [
            'group' => 'Business',
            'label' => 'Business email',
            'type' => 'email',
            'rules' => ['nullable', 'email', 'max:255'],
        ],
        'business_contact' => [
            'group' => 'Business',
            'label' => 'Business contact line',
            'type' => 'text',
            'rules' => ['nullable', 'string', 'max:160'],
        ],
        'receipt_footer' => [
            'group' => 'Business',
            'label' => 'Receipt footer',
            'type' => 'textarea',
            'rules' => ['nullable', 'string', 'max:300'],
        ],
        'system_name' => [
            'group' => 'Application',
            'label' => 'System name',
            'type' => 'text',
            'rules' => ['required', 'string', 'max:120'],
        ],
        'currency' => [
            'group' => 'Compatibility',
            'label' => 'Legacy currency',
            'type' => 'select',
            'options' => ['PHP', 'USD'],
            'rules' => ['required', 'in:PHP,USD'],
            'hidden' => true,
        ],
        'default_tax_rate' => [
            'group' => 'Compatibility',
            'label' => 'Legacy tax rate',
            'type' => 'number',
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
            'hidden' => true,
        ],
        'currency_code' => [
            'group' => 'Localization',
            'label' => 'Currency code',
            'type' => 'select',
            'options' => ['PHP', 'USD'],
            'rules' => ['required', 'in:PHP,USD'],
        ],
        'currency_symbol' => [
            'group' => 'Localization',
            'label' => 'Currency symbol',
            'type' => 'select',
            'options' => ['₱', '$'],
            'rules' => ['required', 'in:₱,$'],
        ],
        'currency_locale' => [
            'group' => 'Localization',
            'label' => 'Currency locale',
            'type' => 'select',
            'options' => ['en-PH', 'en-US'],
            'rules' => ['required', 'in:en-PH,en-US'],
        ],
        'timezone' => [
            'group' => 'Localization',
            'label' => 'Business timezone',
            'type' => 'select',
            'options' => ['Asia/Manila', 'Asia/Singapore', 'UTC'],
            'rules' => ['required', 'in:Asia/Manila,Asia/Singapore,UTC'],
        ],
        'tax_rate' => [
            'group' => 'Tax',
            'label' => 'Tax rate (%)',
            'type' => 'number',
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],
        'tax_inclusive' => [
            'group' => 'Tax',
            'label' => 'Prices include tax',
            'type' => 'boolean',
            'rules' => ['required', 'boolean'],
        ],
        'report_max_date_range_days' => [
            'group' => 'Operations',
            'label' => 'Maximum report date range (days)',
            'type' => 'number',
            'rules' => ['required', 'integer', 'min:31', 'max:3660'],
        ],
        'backup_retention_count' => [
            'group' => 'Operations',
            'label' => 'Backup retention count',
            'type' => 'number',
            'rules' => ['required', 'integer', 'min:1', 'max:100'],
        ],
        'low_stock_alert_enabled' => [
            'group' => 'Inventory',
            'label' => 'Enable low-stock alerts',
            'type' => 'boolean',
            'rules' => ['required', 'boolean'],
        ],
    ];
}
