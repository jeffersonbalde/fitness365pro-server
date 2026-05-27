<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmailOtpMail;
use App\Models\Client;
use App\Models\EmailOtp;
use App\Models\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    private int $otpMinutes = 10;
    private int $resendCooldownSeconds = 60;
    private int $maxAttempts = 5;

    private int $accessTokenMinutes = 60;
    private int $refreshTokenDays = 14;

    private function issueRefreshToken(Request $request, Client $client): string
    {
        $plain = Str::random(80);
        $hash = hash('sha256', $plain);

        RefreshToken::create([
            'tokenable_type' => Client::class,
            'tokenable_id' => $client->id,
            'token_hash' => $hash,
            'expires_at' => now()->addDays($this->refreshTokenDays),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        return $plain;
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function createOtp(string $email): array
    {
        $otp = $this->generateOtp();
        $hash = hash('sha256', $otp);

        $row = EmailOtp::create([
            'email' => $email,
            'code_hash' => $hash,
            'expires_at' => now()->addMinutes($this->otpMinutes),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        return [$row, $otp];
    }

    private function clientPayload(Client $client): array
    {
        $profile = $client->profile;

        return [
            'id' => $client->id,
            'email' => $client->email,
            'email_verified_at' => $client->email_verified_at,
            'onboarding_step' => $profile?->onboarding_step ?? 0,
            'onboarding_completed' => (bool) ($profile?->onboarding_completed ?? false),
        ];
    }

    public function resend(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = (string) $request->input('email');

        $client = Client::where('email', $email)->first();
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'errors' => ['email' => ['No account found for this email.']],
            ], 404);
        }

        if ($client->email_verified_at) {
            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
            ], 200);
        }

        $last = EmailOtp::where('email', $email)
            ->whereNull('verified_at')
            ->orderByDesc('id')
            ->first();

        if ($last && $last->last_sent_at && $last->last_sent_at->diffInSeconds(now()) < $this->resendCooldownSeconds) {
            $wait = $this->resendCooldownSeconds - $last->last_sent_at->diffInSeconds(now());
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting a new code.',
                'errors' => ['email' => ["Please wait {$wait}s before requesting a new code."]],
            ], 429);
        }

        [$row, $otp] = $this->createOtp($email);

        Mail::to($email)->send(new EmailOtpMail($otp, $this->otpMinutes));

        return response()->json([
            'success' => true,
            'message' => 'Verification code sent',
            'data' => [
                'expires_in' => $this->otpMinutes * 60,
                'cooldown' => $this->resendCooldownSeconds,
            ],
        ], 200);
    }

    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|min:4|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = (string) $request->input('email');
        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        $hash = hash('sha256', $code);

        $client = Client::where('email', $email)->first();
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'errors' => ['email' => ['No account found for this email.']],
            ], 404);
        }

        if ($client->email_verified_at) {
            // Already verified: issue tokens to proceed
            $expiresAt = now()->addMinutes($this->accessTokenMinutes);
            $token = $client->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
            $refreshToken = $this->issueRefreshToken($request, $client);

            return response()->json([
                'success' => true,
                'message' => 'Email already verified',
                'data' => [
                    'client' => [
                        ...$this->clientPayload($client),
                    ],
                    'token' => $token,
                    'expires_in' => $this->accessTokenMinutes * 60,
                    'refresh_token' => $refreshToken,
                    'refresh_expires_in' => $this->refreshTokenDays * 24 * 60 * 60,
                ],
            ], 200);
        }

        $otpRow = EmailOtp::where('email', $email)
            ->whereNull('verified_at')
            ->orderByDesc('id')
            ->first();

        if (!$otpRow) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code not found',
                'errors' => ['code' => ['Please request a new verification code.']],
            ], 404);
        }

        if ($otpRow->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code expired',
                'errors' => ['code' => ['Code expired. Please request a new one.']],
            ], 410);
        }

        if ($otpRow->attempts >= $this->maxAttempts) {
            return response()->json([
                'success' => false,
                'message' => 'Too many attempts',
                'errors' => ['code' => ['Too many attempts. Please request a new code.']],
            ], 429);
        }

        if (!hash_equals($otpRow->code_hash, $hash)) {
            $otpRow->increment('attempts');

            return response()->json([
                'success' => false,
                'message' => 'Invalid code',
                'errors' => ['code' => ['The verification code is incorrect.']],
            ], 422);
        }

        // Mark verified
        $otpRow->update(['verified_at' => now()]);
        $client->forceFill(['email_verified_at' => now()])->save();

        // Issue tokens after verification
        $expiresAt = now()->addMinutes($this->accessTokenMinutes);
        $token = $client->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        $refreshToken = $this->issueRefreshToken($request, $client);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully',
            'data' => [
                'client' => [
                    ...$this->clientPayload($client),
                ],
                'token' => $token,
                'expires_in' => $this->accessTokenMinutes * 60,
                'refresh_token' => $refreshToken,
                'refresh_expires_in' => $this->refreshTokenDays * 24 * 60 * 60,
            ],
        ], 200);
    }
}
