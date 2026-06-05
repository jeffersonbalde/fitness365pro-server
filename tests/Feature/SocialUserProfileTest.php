<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use App\Models\WorkoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns another user profile quickly without workout stats in the main payload', function () {
    $viewer = Client::factory()->create();
    Sanctum::actingAs($viewer);

    $target = Client::factory()->create(['email' => 'target@example.com']);
    ClientProfile::create([
        'client_id' => $target->id,
        'display_name' => 'Target Athlete',
        'city' => 'Manila',
    ]);

    WorkoutLog::create([
        'client_id' => $target->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Run',
        'status' => 'completed',
        'entry_type' => 'workout',
        'distance_km' => 5,
    ]);

    $started = microtime(true);
    $response = $this->getJson("/api/v1/social/profile/{$target->id}");
    $elapsedMs = (microtime(true) - $started) * 1000;

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.display_name', 'Target Athlete')
        ->assertJsonMissingPath('data.workout_stats');

    expect($elapsedMs)->toBeLessThan(3000);
});

it('returns workout stats from a separate endpoint', function () {
    $viewer = Client::factory()->create();
    Sanctum::actingAs($viewer);

    $target = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $target->id,
        'display_name' => 'Stats Athlete',
    ]);

    $response = $this->getJson("/api/v1/social/profile/{$target->id}/workout-stats");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'total_workouts',
                'total_distance_km',
                'event_badges',
                'event_trophies',
                'joined_challenge_events',
            ],
        ]);
});
