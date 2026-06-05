<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class OptionalSanctumUser
{
    public static function client(Request $request): ?Client
    {
        $user = $request->user();
        if ($user instanceof Client) {
            return $user;
        }

        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof Client ? $tokenable : null;
    }
}
