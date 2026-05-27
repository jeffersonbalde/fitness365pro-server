<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        // Store this in your server .env as GEMINI_API_KEY=...
        'api_key' => env('GEMINI_API_KEY'),
        // For v1 API, use the base model name: 'gemini-1.5-flash' (without version suffix)
        'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        // Default timeout for the HTTP call (seconds)
        'timeout' => (int) env('GEMINI_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maya (PayMaya) Checkout — https://developers.maya.ph
    |--------------------------------------------------------------------------
    */
    'paymaya' => [
        'public_key' => env('PAYMAYA_PUBLIC_KEY', ''),
        'secret_key' => env('PAYMAYA_SECRET_KEY', ''),
        // PAYMAYA_SANDBOX wins if set; else PAYMAYA_ENVIRONMENT=production → live API.
        'sandbox' => env('PAYMAYA_SANDBOX') !== null
            ? filter_var(env('PAYMAYA_SANDBOX'), FILTER_VALIDATE_BOOLEAN)
            : strtolower((string) env('PAYMAYA_ENVIRONMENT', 'sandbox')) !== 'production',
    ],

    'facebook' => [
        'app_id' => env('FACEBOOK_APP_ID', ''),
    ],

];
