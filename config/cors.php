<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Cross-Origin Resource Sharing (CORS) Configuration
     |--------------------------------------------------------------------------
     |
     | Here you may configure your settings for cross-origin resource sharing
     | or "CORS". This determines what cross-origin operations may execute
     | in web browsers. You are free to adjust these settings as needed.
     |
     | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
     |
     */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => trim($origin),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400, // Cache preflight for 24 hours
    'supports_credentials' => true, // CRITICAL: Must be true for auth tokens
];
