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
        'population/*'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://localhost:3000',
        'https://dbest-client-cobe7.ondigitalocean.app',
        'https://dbest-client-cobe7.ondigitalocean.app/',
    ],

    'allowed_origins_patterns' => [
        '#^https://dbest-client-.*\.ondigitalocean\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Authorization',
        'X-Requested-With',
        'Content-Type',
        'X-Token-Auth',
        'X-CSRF-TOKEN',
    ],

    'max_age' => 86400, // 24 hours

    'supports_credentials' => true,

];