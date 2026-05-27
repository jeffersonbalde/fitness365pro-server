<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Goal;
use App\Models\WorkoutLog;
use App\Services\Social\BuddyScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scores higher for candidates with stronger niche and goal alignment', function () {
    $service = new BuddyScoringService();

    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'country' => 'Philippines',
        'province' => 'Zamboanga Peninsula',
        'city' => 'Pagadian City',
        'primary_niche' => 'running',
        'secondary_niches' => ['trail_running'],
    ]);

    $goalRunning = Goal::create([
        'name' => 'Improve Cardio',
        'slug' => 'improve-cardio',
        'category' => 'endurance',
        'is_active' => true,
    ]);
    $goalMuscle = Goal::create([
        'name' => 'Build Muscle',
        'slug' => 'build-muscle',
        'category' => 'strength',
        'is_active' => true,
    ]);
    $viewer->goals()->attach([$goalRunning->id, $goalMuscle->id]);

    $strongCandidate = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $strongCandidate->id,
        'country' => 'Philippines',
        'province' => 'Zamboanga Peninsula',
        'city' => 'Pagadian City',
        'primary_niche' => 'running',
        'secondary_niches' => ['endurance'],
    ]);
    $strongCandidate->goals()->attach([$goalRunning->id]);
    WorkoutLog::create([
        'client_id' => $strongCandidate->id,
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 40,
        'status' => 'completed',
    ]);
    WorkoutLog::create([
        'client_id' => $strongCandidate->id,
        'workout_date' => now()->subDays(4)->toDateString(),
        'workout_type' => 'Tempo Run',
        'duration_minutes' => 50,
        'status' => 'completed',
    ]);

    $weakCandidate = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $weakCandidate->id,
        'country' => 'Philippines',
        'province' => 'Cebu',
        'city' => 'Cebu City',
        'primary_niche' => 'gym',
        'secondary_niches' => [],
    ]);
    WorkoutLog::create([
        'client_id' => $weakCandidate->id,
        'workout_date' => now()->subDays(75)->toDateString(),
        'workout_type' => 'Chest Day',
        'duration_minutes' => 45,
        'status' => 'completed',
    ]);

    $scored = $service->scoreCandidates($viewer, collect([$weakCandidate, $strongCandidate]));

    expect($scored)->toHaveCount(2);
    expect($scored[0]['candidate_id'])->toBe($strongCandidate->id);
    expect($scored[0]['score'])->toBeGreaterThan($scored[1]['score']);
    expect($scored[0]['signals']['same_primary_niche'])->toBeTrue();
});

it('returns deterministic zeroed score when candidate has no signals', function () {
    $service = new BuddyScoringService();

    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'primary_niche' => 'running',
    ]);

    $candidate = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $candidate->id,
        'primary_niche' => 'hybrid',
        'secondary_niches' => [],
    ]);

    $result = $service->scorePair($viewer, $candidate);

    expect($result['candidate_id'])->toBe($candidate->id);
    expect($result['score'])->toBeFloat();
    expect($result['score'])->toBe(0.0);
    expect($result['signals']['goal_overlap_count'])->toBe(0);
});

