<?php

namespace App\Support;

final class AuthTokenConfig
{
    public static function accessTokenMinutes(): int
    {
        return max(1, (int) config('auth_tokens.access_token_minutes', 60));
    }

    public static function refreshTokenDays(): int
    {
        return max(1, (int) config('auth_tokens.refresh_token_days', 14));
    }

    public static function accessTokenSeconds(): int
    {
        return self::accessTokenMinutes() * 60;
    }

    public static function refreshTokenSeconds(): int
    {
        return self::refreshTokenDays() * 24 * 60 * 60;
    }
}
