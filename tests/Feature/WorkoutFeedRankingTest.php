<?php

use App\Models\Client;
use App\Models\ClientFollow;
use App\Models\ClientProfile;
use App\Models\WorkoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns ranked feed favoring same niche and followed users', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);
    Sanctum::actingAs($viewer);

    $followedRunner = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $followedRunner->id,
        'display_name' => 'Followed Runner',
        'primary_niche' => 'running',
    ]);

    $otherGymUser = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $otherGymUser->id,
        'display_name' => 'Gym User',
        'primary_niche' => 'gym',
    ]);

    ClientFollow::create([
        'follower_client_id' => $viewer->id,
        'followed_client_id' => $followedRunner->id,
    ]);

    WorkoutLog::create([
        'client_id' => $followedRunner->id,
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Tempo Run',
        'duration_minutes' => 40,
        'distance_km' => 6,
        'status' => 'completed',
    ]);

    WorkoutLog::create([
        'client_id' => $otherGymUser->id,
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Leg Day',
        'duration_minutes' => 60,
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/v1/workouts/feed?limit=10&sort=ranked&scope=following');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.sort', 'ranked');

    $workouts = collect($response->json('data.workouts'));
    expect($workouts)->not->toBeEmpty();

    $first = $workouts->first();
    expect($first['client_id'])->toBe($followedRunner->id);
    expect($first)->toHaveKey('ranking_score');
});

it('supports chronological fallback mode for feed endpoint', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);
    Sanctum::actingAs($viewer);

    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->subDays(3)->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 30,
        'status' => 'completed',
    ]);

    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Intervals',
        'duration_minutes' => 25,
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/v1/workouts/feed?sort=chronological&limit=5&scope=following');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.sort', 'chronological')
        ->assertJsonPath('data.experiment.applied_sort', 'chronological');

    $workouts = $response->json('data.workouts');
    expect($workouts[0]['workout_type'])->toBe('Intervals');
});

it('falls back to chronological when ranking flag is disabled', function () {
    config()->set('social.feed_ranking.enabled', false);

    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);
    Sanctum::actingAs($viewer);

    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 30,
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/v1/workouts/feed?sort=ranked&limit=5&scope=following');
    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.sort', 'chronological')
        ->assertJsonPath('data.experiment.ranking_enabled', false)
        ->assertJsonPath('data.experiment.applied_sort', 'chronological');
});

it('returns workouts from all users when scope is all', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);
    Sanctum::actingAs($viewer);

    $otherUser = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $otherUser->id,
        'display_name' => 'Community Athlete',
        'primary_niche' => 'gym',
    ]);

    WorkoutLog::create([
        'client_id' => $otherUser->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Community Run',
        'duration_minutes' => 35,
        'distance_km' => 5,
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/v1/workouts/feed?scope=all&sort=chronological&limit=10');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.scope', 'all')
        ->assertJsonPath('data.sort', 'chronological');

    $clientIds = collect($response->json('data.workouts'))->pluck('client_id')->all();
    expect($clientIds)->toContain($otherUser->id);
});

it('limits following scope to self and followed users only', function () {
    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);
    Sanctum::actingAs($viewer);

    $followedUser = Client::factory()->create();
    ClientProfile::create(['client_id' => $followedUser->id, 'display_name' => 'Followed']);

    $stranger = Client::factory()->create();
    ClientProfile::create(['client_id' => $stranger->id, 'display_name' => 'Stranger']);

    ClientFollow::create([
        'follower_client_id' => $viewer->id,
        'followed_client_id' => $followedUser->id,
    ]);

    WorkoutLog::create([
        'client_id' => $followedUser->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Followed Workout',
        'duration_minutes' => 30,
        'status' => 'completed',
    ]);

    WorkoutLog::create([
        'client_id' => $stranger->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Stranger Workout',
        'duration_minutes' => 45,
        'status' => 'completed',
    ]);

    $response = $this->getJson('/api/v1/workouts/feed?scope=following&sort=chronological&limit=10');

    $response->assertOk()
        ->assertJsonPath('data.scope', 'following');

    $clientIds = collect($response->json('data.workouts'))->pluck('client_id')->all();
    expect($clientIds)->toContain($followedUser->id);
    expect($clientIds)->not->toContain($stranger->id);
});

