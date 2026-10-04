<?php

return [

    'paymongo' => [
        'driver' => env('QR_PAYMENTS_DRIVER', 'paymongo'),
        'fake_delay' => (int) env('QR_FAKE_PAID_AFTER', 8),
        'secret' => env('PAYMONGO_SECRET_KEY'),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
        'billing_name' => env('PAYMONGO_BILLING_NAME', 'FreshMart'),
        'billing_email' => env('PAYMONGO_BILLING_EMAIL'),
    ],

];
