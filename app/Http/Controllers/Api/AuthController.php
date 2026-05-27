<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RefreshToken;
use App\Models\EmailOtp;
use App\Mail\EmailOtpMail;
use App\Services\ClientNotificationService;
use App\Support\FirebaseCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class AuthController extends Controller
{
    private int $accessTokenMinutes = 60; // short-lived access token (1 hour)
    private int $refreshTokenDays = 14;   // refresh token lifetime (2 weeks)
    private int $otpMinutes = 10;

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function sendOtp(string $email): void
    {
        $otp = $this->generateOtp();
        $hash = hash('sha256', $otp);

        EmailOtp::create([
            'email' => $email,
            'code_hash' => $hash,
            'expires_at' => now()->addMinutes($this->otpMinutes),
            'attempts' => 0,
            'last_sent_at' => now(),
        ]);

        Mail::to($email)->send(new EmailOtpMail($otp, $this->otpMinutes));
    }

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

    private function revokeRefreshToken(string $plain): void
    {
        $hash = hash('sha256', $plain);
        RefreshToken::where('token_hash', $hash)->whereNull('revoked_at')->update([
            'revoked_at' => now(),
        ]);
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

    /**
     * Register a new client
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'password' => 'required|min:8|regex:/[a-zA-Z]/|regex:/[0-9]/',
            'password_confirmation' => 'required|same:password',
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
            'password_confirmation.same' => 'Passwords do not match.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $email = (string) $request->input('email');
            $existing = Client::where('email', $email)->first();

            // If account already exists and is verified -> treat as already registered
            if ($existing && $existing->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => [
                        'email' => ['This email is already registered. Please log in instead.'],
                    ],
                ], 422);
            }

            // If account exists but NOT verified -> allow "register again" to resend OTP.
            if ($existing && !$existing->email_verified_at) {
                // Update password to the latest one user entered (matches common UX)
                $existing->forceFill([
                    'password' => Hash::make((string) $request->input('password')),
                ])->save();

                $this->sendOtp($existing->email);

                return response()->json([
                    'success' => true,
                    'message' => 'Verification code sent',
                    'data' => [
                        'client' => [
                            ...$this->clientPayload($existing),
                        ],
                        'requires_verification' => true,
                        'otp_expires_in' => $this->otpMinutes * 60,
                    ],
                ], 200);
            }

            // New account
            $client = Client::create([
                'email' => $email,
                'password' => Hash::make((string) $request->input('password')),
            ]);

            $this->sendOtp($client->email);

            return response()->json([
                'success' => true,
                'message' => 'Verification code sent',
                'data' => [
                    'client' => [
                        ...$this->clientPayload($client),
                    ],
                    'requires_verification' => true,
                    'otp_expires_in' => $this->otpMinutes * 60,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login client
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

        $client = Client::where('email', $request->email)->first();

        // If not found OR not verified, respond like "not found" (match your reference flow)
        if (!$client || !$client->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found',
                'errors' => [
                    'email' => ['Account not found. Please sign up first.'],
                ],
            ], 404);
        }

        // Check if account was created via Google OAuth (no password set)
        if (empty($client->password) || $client->password === null) {
            return response()->json([
                'success' => false,
                'message' => 'This account was created with Google',
                'errors' => [
                    'email' => ['This account was created using Google. Please use "Continue with Google" to log in.'],
                ],
            ], 401);
        }

        // Verify password for email/password accounts
        if (!Hash::check($request->password, $client->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ], 401);
        }

        // Revoke all existing tokens (optional - for single device login)
        // $client->tokens()->delete();

        $expiresAt = now()->addMinutes($this->accessTokenMinutes);
        $token = $client->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        $refreshToken = $this->issueRefreshToken($request, $client);

        ClientNotificationService::login($client);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
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

    /**
     * Logout client
     */
    public function logout(Request $request)
    {
        $client = $request->user();
        ClientNotificationService::logout($client);

        $client->currentAccessToken()->delete();
        if ($request->filled('refresh_token')) {
            $this->revokeRefreshToken((string) $request->input('refresh_token'));
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Get authenticated client
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'client' => [
                    ...$this->clientPayload($request->user()),
                    'created_at' => $request->user()->created_at,
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

        if (!$stored || $stored->expires_at->isPast() || $stored->tokenable_type !== Client::class) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
                'errors' => [
                    'refresh_token' => ['Refresh token is invalid or expired.'],
                ],
            ], 401);
        }

        /** @var Client $client */
        $client = Client::find($stored->tokenable_id);
        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid refresh token',
                'errors' => [
                    'refresh_token' => ['Refresh token is invalid or expired.'],
                ],
            ], 401);
        }

        // Revoke old refresh token (rotate)
        $stored->update(['revoked_at' => now()]);

        $expiresAt = now()->addMinutes($this->accessTokenMinutes);
        $token = $client->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        $newRefresh = $this->issueRefreshToken($request, $client);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully',
            'data' => [
                'token' => $token,
                'expires_in' => $this->accessTokenMinutes * 60,
                'refresh_token' => $newRefresh,
                'refresh_expires_in' => $this->refreshTokenDays * 24 * 60 * 60,
            ],
        ], 200);
    }

    /**
     * Login or register with Google (Firebase ID token)
     */
    public function google(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_token' => 'required|string',
            'intent' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $idToken = (string) $request->input('id_token');
        $intent = strtolower(trim((string) ($request->input('intent') ?? 'login')));
        
        // Validate intent value after normalization
        if (!in_array($intent, ['login', 'signup'], true)) {
            $intent = 'login'; // Default to login if invalid
        }

        // Validate token is not empty
        if (empty($idToken) || trim($idToken) === '') {
            Log::warning('Empty token received in Google auth', [
                'intent' => $intent,
                'has_token' => $request->has('id_token'),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid token provided',
                'errors' => [],
            ], 422);
        }

        try {
            $firebase = (new Factory)->withServiceAccount(FirebaseCredentials::resolve());
            $auth = $firebase->createAuth();
            
            // Verify the Firebase ID token
            $verifiedToken = $auth->verifyIdToken($idToken, false); // false = don't check revocation
            $claims = $verifiedToken->claims();
            
            // Extract email and verification status
            $email = strtolower((string) ($claims->get('email') ?? ''));
            $emailVerified = $claims->get('email_verified', false);
            
            if (empty($email) || !$emailVerified) {
                return response()->json([
                    'success' => false,
                    'message' => 'Google account email is not verified',
                    'errors' => [],
                ], 401);
            }
        } catch (\InvalidArgumentException $e) {
            Log::error('Firebase configuration error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Firebase configuration not found',
                'errors' => [],
            ], 500);
        } catch (FailedToVerifyToken $e) {
            Log::error('Firebase token verification failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify Google token. Please try again.',
                'errors' => [],
            ], 401);
        } catch (\Throwable $e) {
            Log::error('Firebase authentication error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'success' => false,
                'message' => 'Unable to verify Google token. Please try again.',
                'errors' => [],
            ], 401);
        }

        $clientModel = Client::where('email', $email)->first();

        // Handle based on intent
        if ($intent === 'signup') {
            // Sign up flow: create account if doesn't exist, error if exists
            if ($clientModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email is already registered. Please log in instead.',
                    'errors' => [
                        'email' => ['This email is already registered. Please log in instead.'],
                    ],
                ], 409); // 409 Conflict
            }

            // Create new account
            $clientModel = Client::create([
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
            ]);
        } else {
            // Login flow: error if account doesn't exist
            if (!$clientModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'This email is not registered. Please sign up first.',
                    'errors' => [
                        'email' => ['This email is not registered. Please sign up first.'],
                    ],
                ], 404);
            }

            // If account exists but email is not verified, verify it now
            if (!$clientModel->email_verified_at) {
                $clientModel->forceFill(['email_verified_at' => now()])->save();
            }
        }

        $expiresAt = now()->addMinutes($this->accessTokenMinutes);
        $token = $clientModel->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        $refreshToken = $this->issueRefreshToken($request, $clientModel);

        if ($intent !== 'signup') {
            ClientNotificationService::login($clientModel);
        }

        return response()->json([
            'success' => true,
            'message' => $intent === 'signup' ? 'Registration successful' : 'Login successful',
            'data' => [
                'client' => [
                    ...$this->clientPayload($clientModel),
                ],
                'token' => $token,
                'expires_in' => $this->accessTokenMinutes * 60,
                'refresh_token' => $refreshToken,
                'refresh_expires_in' => $this->refreshTokenDays * 24 * 60 * 60,
            ],
        ], 200);
    }
}
