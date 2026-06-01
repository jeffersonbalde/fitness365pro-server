<?php

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\WorkoutLog;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns workout totals, streak, empty legacy badges, and CMS event badges list', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-29 12:00:00'));

    $viewer = Client::factory()->create();
    Sanctum::actingAs($viewer);

    // 3 consecutive run-days → streak counted in totals.
    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->subDays(2)->toDateString(),
        'workout_type' => 'Easy Run',
        'distance_km' => 4.00,
        'duration_seconds' => 1400,
        'pace_min_per_km' => 5.00,
        'status' => 'completed',
        'entry_type' => 'workout',
    ]);
    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Easy Run',
        'distance_km' => 4.00,
        'duration_seconds' => 1400,
        'pace_min_per_km' => 5.00,
        'status' => 'completed',
        'entry_type' => 'workout',
    ]);
    WorkoutLog::create([
        'client_id' => $viewer->id,
        'workout_date' => now()->toDateString(),
        'workout_type' => 'Easy Run',
        'distance_km' => 4.00,
        'duration_seconds' => 1400,
        'pace_min_per_km' => 5.00,
        'status' => 'completed',
        'entry_type' => 'workout',
    ]);

    $community = Community::create([
        'owner_client_id' => $viewer->id,
        'name' => 'Test Running Community',
        'slug' => 'test-running-community',
        'primary_niche' => 'running',
        'visibility' => 'public',
        'is_active' => true,
    ]);

    CommunityMember::create([
        'community_id' => $community->id,
        'client_id' => $viewer->id,
        'role' => 'member',
        'status' => 'active',
        'joined_at' => now()->subDays(5),
    ]);

    $response = $this->getJson('/api/v1/workouts/stats');
    $response->assertOk()->assertJsonPath('success', true);

    $response->assertJsonStructure([
        'success',
        'data' => [
            'total_workouts',
            'this_week',
            'current_streak',
            'total_distance_km',
            'total_runs',
            'avg_pace_min_per_km',
            'badges',
            'trophies',
            'event_badges',
            'event_trophies',
            'joined_races',
            'joined_challenge_events',
        ],
    ]);

    expect($response->json('data.badges'))->toEqual([]);
    expect($response->json('data.trophies'))->toEqual([]);
    expect($response->json('data.event_badges'))->toBeArray();
    expect($response->json('data.event_trophies'))->toBeArray();

    expect($response->json('data.total_runs'))->toBe(3);
    expect($response->json('data.current_streak'))->toBeGreaterThanOrEqual(3);

    $joinedRaces = collect($response->json('data.joined_races'));
    expect($joinedRaces->count())->toBeGreaterThan(0);
    expect($joinedRaces->first()['id'])->toBe($community->id);

    Carbon::setTestNow();
});

