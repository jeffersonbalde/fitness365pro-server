<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Mail\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    private int $tokenExpiryMinutes = 60;

    /**
     * Send password reset link
     */
    public function forgotPassword(Request $request)
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

        $email = strtolower((string) $request->input('email'));
        
        // Only find verified, non-deleted clients
        $client = Client::where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();

        // Always return success to prevent email enumeration
        // But only send email if client exists AND is verified
        if (!$client) {
            // Client doesn't exist, is not verified, or is soft-deleted
            // Return success message but don't send email
            return response()->json([
                'success' => true,
                'message' => 'If that email exists, we sent a password reset link.',
            ], 200);
        }

        // Check if account was created via Google OAuth (no password set)
        if (empty($client->password) || $client->password === null) {
            return response()->json([
                'success' => false,
                'message' => 'This account was created with Google',
                'errors' => [
                    'email' => ['This account was created using Google. Please use "Continue with Google" to log in. Password reset is not available for Google accounts.'],
                ],
            ], 400);
        }

        // Client exists, is verified, and has a password - proceed with password reset
        // Generate token
        $token = Str::random(64);
        $hashedToken = hash('sha256', $token);

        // Store or update token in password_reset_tokens table
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $hashedToken,
                'created_at' => now(),
            ]
        );

        // Build reset URL
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $resetUrl = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($email);

        // Send email only to verified accounts
        Mail::to($email)->send(new PasswordResetMail($resetUrl, $this->tokenExpiryMinutes));

        return response()->json([
            'success' => true,
            'message' => 'If that email exists, we sent a password reset link.',
        ], 200);
    }

    /**
     * Verify reset token
     */
    public function verifyToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $email = strtolower((string) $request->input('email'));
        $token = (string) $request->input('token');
        $hashedToken = hash('sha256', $token);

        // First verify the client exists and is verified
        $client = Client::where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'token' => ['This password reset link is invalid or has expired.'],
                ],
            ], 400);
        }

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $hashedToken)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'token' => ['This password reset link is invalid or has expired.'],
                ],
            ], 400);
        }

        // Check if token is expired (60 minutes)
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes($this->tokenExpiryMinutes)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'token' => ['This password reset link has expired. Please request a new one.'],
                ],
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token is valid',
        ], 200);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
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

        $email = strtolower((string) $request->input('email'));
        $token = (string) $request->input('token');
        $hashedToken = hash('sha256', $token);

        $record = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->where('token', $hashedToken)
            ->first();

        if (!$record) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'token' => ['This password reset link is invalid or has expired.'],
                ],
            ], 400);
        }

        // Check if token is expired
        $createdAt = \Carbon\Carbon::parse($record->created_at);
        if ($createdAt->addMinutes($this->tokenExpiryMinutes)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired reset token',
                'errors' => [
                    'token' => ['This password reset link has expired. Please request a new one.'],
                ],
            ], 400);
        }

        // Update password - ensure client exists and is verified
        $client = Client::where('email', $email)
            ->whereNotNull('email_verified_at')
            ->first();
            
        if (!$client) {
            // Delete the token since account is invalid
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'Account not found or not verified',
                'errors' => [
                    'email' => ['Account not found or email not verified.'],
                ],
            ], 404);
        }

        // Check if account was created via Google OAuth (no password set)
        if (empty($client->password) || $client->password === null) {
            // Delete the token since this account can't reset password
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'success' => false,
                'message' => 'This account was created with Google',
                'errors' => [
                    'email' => ['This account was created using Google. Please use "Continue with Google" to log in. Password reset is not available for Google accounts.'],
                ],
            ], 400);
        }

        $client->forceFill([
            'password' => Hash::make((string) $request->input('password')),
        ])->save();

        // Delete used token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
        ], 200);
    }
}
