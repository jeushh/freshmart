<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

$localDomains = 'localhost:5173,127.0.0.1:5173,localhost,127.0.0.1';
$isLocal = env('APP_ENV', 'production') === 'local';
$configuredDomains = (string) env(
    'SANCTUM_STATEFUL_DOMAINS',
    $isLocal ? $localDomains : '',
);
$statefulDomains = $isLocal
    ? "{$configuredDomains},{$localDomains}"
    : $configuredDomains;

return [
    'stateful' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', $statefulDomains),
    )))),
    'guard' => ['web'],
    'expiration' => null,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
