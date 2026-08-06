<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),
        env('APP_URL'),
    ]),
    'allowed_origins_patterns' => [
        '#^https://[a-z0-9\-]+-[a-z0-9]+-[a-z0-9]+\.vercel\.app$#',
        '#^https://delivery-system-hontal-web\.vercel\.app$#',
        '#^https://[a-z0-9\-]+\.hontal\.id$#',
        '#^https://hontal\.id$#',
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^capacitor://localhost$#',
        '#^http://192\.168\.\d+\.\d+(:\d+)?$#',
        '#^http://10\.\d+\.\d+\.\d+(:\d+)?$#',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
