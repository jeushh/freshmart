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
        'system_name' => [
            'group' => 'Application',
            'label' => 'System name',
            'type' => 'text',
            'rules' => ['required', 'string', 'max:120'],
        ],
        'currency' => [
            'group' => 'Application',
            'label' => 'Currency',
            'type' => 'select',
            'options' => ['PHP', 'USD'],
            'rules' => ['required', 'in:PHP,USD'],
        ],
        'default_tax_rate' => [
            'group' => 'Application',
            'label' => 'Default tax rate',
            'type' => 'number',
            'rules' => ['required', 'numeric', 'min:0', 'max:100'],
        ],
        'low_stock_alert_enabled' => [
            'group' => 'Inventory',
            'label' => 'Enable low-stock alerts',
            'type' => 'boolean',
            'rules' => ['required', 'boolean'],
        ],
    ];
}
