<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\RefreshToken;
use App\Support\AuthTokenConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminAuthController extends Controller
{
    private function issueRefreshToken(Request $request, Admin $admin): string
    {
        $plain = Str::random(80);
        $hash = hash('sha256', $plain);

        RefreshToken::create([
            'tokenable_type' => Admin::class,
            'tokenable_id' => $admin->id,
            'token_hash' => $hash,
            'expires_at' => now()->addDays(AuthTokenConfig::refreshTokenDays()),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        return $plain;
    }

    /**
     * Login admin
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = Admin::where('email', $request->email)->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 401);
        }

        $expiresAt = now()->addMinutes(AuthTokenConfig::accessTokenMinutes());
        $token = $admin->createToken('admin-token', ['*'], $expiresAt)->plainTextToken;
        $refreshToken = $this->issueRefreshToken($request, $admin);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'email_verified_at' => $admin->email_verified_at,
                ],
                'token' => $token,
                'expires_in' => AuthTokenConfig::accessTokenSeconds(),
                'refresh_token' => $refreshToken,
                'refresh_expires_in' => AuthTokenConfig::refreshTokenSeconds(),
            ],
        ], 200);
    }

    /**
     * Logout admin
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        if ($request->filled('refresh_token')) {
            $hash = hash('sha256', (string) $request->input('refresh_token'));
            RefreshToken::where('token_hash', $hash)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Get authenticated admin
     */
    public function me(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'email_verified_at' => $admin->email_verified_at,
                    'created_at' => $admin->created_at,
                ],
            ],
        ], 200);
    }

    /**
     * Refresh access token using refresh token (rotation)
     */
    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plain = (string) $request->input('refresh_token');
        $hash = hash('sha256', $plain);

        $stored = RefreshToken::where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        if (!$stored || $stored->expires_at->isPast() || $stored->tokenable_type !== Admin::class) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
                'errors' => [
                    'refresh_token' => ['Refresh token is invalid or expired.'],
                ],
            ], 401);
        }

        /** @var Admin $admin */
        $admin = Admin::find($stored->tokenable_id);
        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
                'errors' => [
                    'refresh_token' => ['Refresh token is invalid or expired.'],
                ],
            ], 401);
        }

        $stored->update(['revoked_at' => now()]);

        $expiresAt = now()->addMinutes(AuthTokenConfig::accessTokenMinutes());
        $token = $admin->createToken('admin-token', ['*'], $expiresAt)->plainTextToken;
        $newRefresh = $this->issueRefreshToken($request, $admin);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'expires_in' => AuthTokenConfig::accessTokenSeconds(),
                'refresh_token' => $newRefresh,
                'refresh_expires_in' => AuthTokenConfig::refreshTokenSeconds(),
            ],
        ], 200);
    }
}


