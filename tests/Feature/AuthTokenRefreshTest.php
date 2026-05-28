<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\RefreshToken;
use App\Support\AuthTokenConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('issues access tokens that expire in one hour and refresh tokens for silent reload', function () {
    $client = Client::factory()->create([
        'password' => bcrypt('password123'),
        'email_verified_at' => now(),
    ]);
    ClientProfile::create([
        'client_id' => $client->id,
        'onboarding_completed' => true,
        'onboarding_step' => 6,
    ]);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $client->email,
        'password' => 'password123',
    ]);

    $login->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.expires_in', AuthTokenConfig::accessTokenSeconds())
        ->assertJsonPath('data.refresh_expires_in', AuthTokenConfig::refreshTokenSeconds());

    $refreshPlain = (string) $login->json('data.refresh_token');
    expect($refreshPlain)->not->toBe('');

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refreshPlain,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.expires_in', AuthTokenConfig::accessTokenSeconds())
        ->assertJsonStructure(['data' => ['token', 'refresh_token']]);
});

it('rejects expired refresh tokens', function () {
    $client = Client::factory()->create();
    $plain = Str::random(80);

    RefreshToken::create([
        'tokenable_type' => Client::class,
        'tokenable_id' => $client->id,
        'token_hash' => hash('sha256', $plain),
        'expires_at' => now()->subMinute(),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
    ]);

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $plain,
    ])->assertUnauthorized();
});

it('rejects expired sanctum access tokens', function () {
    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);

    $token = $client->createToken('auth-token', ['*'], now()->subMinute())->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
