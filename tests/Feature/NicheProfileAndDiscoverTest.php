<?php

use App\Models\Client;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('updates profile niche fields through the profile endpoint', function () {
    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);
    Sanctum::actingAs($client);

    $response = $this->putJson('/api/v1/profile', [
        'primary_niche' => 'running',
        'secondary_niches' => ['trail_running', 'endurance'],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.profile.primary_niche', 'running')
        ->assertJsonPath('data.profile.secondary_niches.0', 'trail_running')
        ->assertJsonPath('data.profile.secondary_niches.1', 'endurance');

    expect($client->fresh()->profile->primary_niche)->toBe('running');
    expect($client->fresh()->profile->secondary_niches)->toBe(['trail_running', 'endurance']);
});

it('filters discover results by niche when provided', function () {
    $viewer = Client::factory()->create();
    Sanctum::actingAs($viewer);

    $runningClient = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $runningClient->id,
        'display_name' => 'Runner One',
        'primary_niche' => 'running',
    ]);

    $gymClient = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $gymClient->id,
        'display_name' => 'Gym One',
        'primary_niche' => 'gym',
    ]);

    $response = $this->getJson('/api/v1/social/discover?niche=running');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $results = $response->json('data.results');
    expect(collect($results)->pluck('id')->contains($runningClient->id))->toBeTrue();
    expect(collect($results)->pluck('id')->contains($gymClient->id))->toBeFalse();
    expect(collect($results)->pluck('primary_niche')->unique()->values()->all())->toBe(['running']);
});

