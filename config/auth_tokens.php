<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API token lifetimes (Sanctum + refresh tokens)
    |--------------------------------------------------------------------------
    |
    | access_token_minutes — short-lived Bearer token (default 1 hour).
    | refresh_token_days — long-lived refresh token for silent re-auth on
    |   page reload without showing the login form again.
    |
    */

    'access_token_minutes' => (int) env('AUTH_ACCESS_TOKEN_MINUTES', 60),
    'refresh_token_days' => (int) env('AUTH_REFRESH_TOKEN_DAYS', 14),

];
