<?php

$localOrigins = 'http://localhost:5173,http://127.0.0.1:5173';
$isLocal = env('APP_ENV', 'production') === 'local';
$configuredOrigins = (string) env(
    'CORS_ALLOWED_ORIGINS',
    $isLocal ? $localOrigins : env('FRONTEND_URL', ''),
);
$originList = $isLocal
    ? "{$configuredOrigins},{$localOrigins}"
    : $configuredOrigins;
$allowedOrigins = array_values(array_unique(array_filter(array_map(
    'trim',
    explode(',', $originList),
))));

if (in_array('*', $allowedOrigins, true)) {
    throw new RuntimeException(
        'CORS_ALLOWED_ORIGINS must contain explicit trusted origins when credentials are enabled.',
    );
}

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-Request-ID',
        'X-XSRF-TOKEN',
    ],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 0,
    'supports_credentials' => true,
];
