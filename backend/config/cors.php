<?php

declare(strict_types=1);

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174'))
)));

$allowedMethods = array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))
)));

$allowedHeaders = array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', (string) env('CORS_ALLOWED_HEADERS', 'Content-Type,X-Requested-With,X-XSRF-TOKEN,X-CSRF-TOKEN,Authorization,Accept,Origin'))
)));

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],
    'allowed_methods' => $allowedMethods,
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => $allowedHeaders,
    'exposed_headers' => [
        'X-Request-ID',
    ],
    'max_age' => (int) env('CORS_MAX_AGE', 600),
    'supports_credentials' => true,
];
