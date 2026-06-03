<?php

$allowedOrigins = [
    'http://localhost:5173',
    'http://localhost:3000',
    'https://fitness365pro.com',
    'https://www.fitness365pro.com',
    'https://fitness365pro-wpwkq.ondigitalocean.app',
];

$frontendUrl = env('FRONTEND_URL');
if (is_string($frontendUrl) && $frontendUrl !== '') {
    $allowedOrigins[] = rtrim($frontendUrl, '/');
}

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

    'paths' => [
        'api/*',
        'auth/*',
        'client/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'register',
        'user',
        'admin/*',
        'notifications/*',
        'incidents/*',
        'reports/*',
        'analytics/*',
        'population/*',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [
        '#^https://[a-z0-9-]+\.ondigitalocean\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Requested-With',
        'Content-Type',
        'X-Token-Auth',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 86400,

    'supports_credentials' => true,

];
