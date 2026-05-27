<?php

use App\Models\Client;
use App\Models\ClientFollow;
use App\Models\ClientProfile;
use App\Models\Goal;
use App\Models\WorkoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns scored suggested buddies with pagination and reason tags', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
        'country' => 'Philippines',
        'province' => 'Zamboanga Peninsula',
        'city' => 'Pagadian City',
    ]);
    Sanctum::actingAs($viewer);

    $goal = Goal::create([
        'name' => 'Improve Cardio',
        'slug' => 'improve-cardio',
        'category' => 'endurance',
        'is_active' => true,
    ]);
    $viewer->goals()->attach([$goal->id]);

    $candidateA = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $candidateA->id,
        'display_name' => 'Runner Buddy',
        'primary_niche' => 'running',
        'country' => 'Philippines',
        'province' => 'Zamboanga Peninsula',
        'city' => 'Pagadian City',
    ]);
    $candidateA->goals()->attach([$goal->id]);
    WorkoutLog::create([
        'client_id' => $candidateA->id,
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 35,
        'status' => 'completed',
    ]);

    $candidateB = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $candidateB->id,
        'display_name' => 'Gym Buddy',
        'primary_niche' => 'gym',
        'country' => 'Philippines',
        'province' => 'Cebu',
        'city' => 'Cebu City',
    ]);

    // Already-followed users should not appear as suggestions.
    ClientFollow::create([
        'follower_client_id' => $viewer->id,
        'followed_client_id' => $candidateB->id,
    ]);

    $response = $this->getJson('/api/v1/social/suggested-buddies?page=1&per_page=10');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.page', 1)
        ->assertJsonPath('data.pagination.per_page', 10);

    $results = collect($response->json('data.results'));
    expect($results->pluck('id')->contains($candidateA->id))->toBeTrue();
    expect($results->pluck('id')->contains($candidateB->id))->toBeFalse();

    $first = $results->firstWhere('id', $candidateA->id);
    expect($first)->not->toBeNull();
    expect($first['score'])->toBeNumeric();
    expect($first['reason_tags'])->toBeArray();
    expect(in_array('same_niche', $first['reason_tags'], true))->toBeTrue();
});

it('validates pagination params for suggested buddies endpoint', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create(['client_id' => $viewer->id]);
    Sanctum::actingAs($viewer);

    $response = $this->getJson('/api/v1/social/suggested-buddies?page=0&per_page=200');
    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

