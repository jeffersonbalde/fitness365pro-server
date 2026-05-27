<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\WorkoutLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('preserves workout images when keep_workout_images uses API media URLs', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Tester',
    ]);
    Sanctum::actingAs($client);

    $relativePath = 'workout-photos/abc-123.jpg';
    $storedUrl = '/storage/'.$relativePath;

    $workout = WorkoutLog::create([
        'client_id' => $client->id,
        'entry_type' => 'workout',
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 30,
        'distance_km' => 5,
        'status' => 'completed',
        'workout_images' => [$storedUrl],
    ]);

    $encodedPath = collect(explode('/', $relativePath))
        ->map(fn ($segment) => rawurlencode($segment))
        ->implode('/');
    $apiMediaUrl = URL::to("/api/v1/profile/media/{$encodedPath}");

    $response = $this->post("/api/v1/workouts/{$workout->id}", [
        '_method' => 'PUT',
        'replace_images' => '1',
        'keep_workout_images' => [$apiMediaUrl],
        'workout_type' => 'Tempo Run',
        'workout_date' => $workout->workout_date->format('Y-m-d'),
        'duration_minutes' => 35,
        'distance_km' => 6,
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $workout->refresh();
    expect($workout->workout_images)->toHaveCount(1);
    expect($workout->workout_images[0])->toBe($storedUrl);
    expect($workout->workout_type)->toBe('Tempo Run');
});

it('still preserves images when keep_workout_images uses storage paths', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Tester',
    ]);
    Sanctum::actingAs($client);

    $storedUrl = '/storage/workout-photos/plain-path.jpg';

    $workout = WorkoutLog::create([
        'client_id' => $client->id,
        'entry_type' => 'workout',
        'workout_date' => now()->subDay()->toDateString(),
        'workout_type' => 'Easy Run',
        'duration_minutes' => 30,
        'distance_km' => 5,
        'status' => 'completed',
        'workout_images' => [$storedUrl],
    ]);

    $response = $this->post("/api/v1/workouts/{$workout->id}", [
        '_method' => 'PUT',
        'replace_images' => '1',
        'keep_workout_images' => [$storedUrl],
        'workout_type' => 'Recovery',
        'workout_date' => $workout->workout_date->format('Y-m-d'),
        'duration_minutes' => 20,
        'distance_km' => 4,
    ]);

    $response->assertOk();

    $workout->refresh();
    expect($workout->workout_images)->toHaveCount(1);
    expect($workout->workout_images[0])->toBe($storedUrl);
});
