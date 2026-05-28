<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Support\WorkoutImageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('accepts common workout image formats including heic extension', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'onboarding_completed' => true,
        'onboarding_step' => 6,
    ]);
    Sanctum::actingAs($client);

    $png = UploadedFile::fake()->create('workout-a.png', 120, 'image/png');
    $heic = UploadedFile::fake()->create('workout-b.heic', 120, 'application/octet-stream');

    expect(WorkoutImageValidator::isAllowed($png))->toBeTrue();
    expect(WorkoutImageValidator::isAllowed($heic))->toBeTrue();

    $response = $this->post('/api/v1/workouts', [
        'entry_type' => 'workout',
        'workout_type' => 'Strength Training',
        'workout_date' => now()->toDateString(),
        'duration_minutes' => 45,
        'distance_km' => 5,
        'duration_seconds' => 2700,
        'location' => 'Gym',
        'notes' => 'Test entry with multiple image formats.',
        'workout_images' => [$png, $heic],
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true);

    expect($response->json('data.workout.workout_images'))->toHaveCount(2);
});

it('rejects non-image workout uploads', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $pdf = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $response = $this->post('/api/v1/workouts', [
        'entry_type' => 'workout',
        'workout_type' => 'Strength Training',
        'workout_date' => now()->toDateString(),
        'duration_minutes' => 45,
        'distance_km' => 5,
        'duration_seconds' => 2700,
        'location' => 'Gym',
        'notes' => 'Should fail validation.',
        'workout_images' => [$pdf],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['workout_images.0']);
});
