<?php

use App\Models\Client;
use App\Models\ClientProfile;
use App\Support\WorkoutImageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('accepts common profile picture formats including heic extension', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'onboarding_completed' => true,
        'onboarding_step' => 6,
    ]);
    Sanctum::actingAs($client);

    $png = UploadedFile::fake()->create('avatar.png', 120, 'image/png');
    $heic = UploadedFile::fake()->create('avatar.heic', 120, 'application/octet-stream');
    $jpeg = UploadedFile::fake()->create('avatar.jpeg', 120, 'image/jpeg');

    expect(WorkoutImageValidator::isAllowed($png))->toBeTrue();
    expect(WorkoutImageValidator::isAllowed($heic))->toBeTrue();
    expect(WorkoutImageValidator::isAllowed($jpeg))->toBeTrue();

    $response = $this->post('/api/v1/profile/picture', [
        'profile_picture' => $jpeg,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['profile_picture_url']]);
});

it('accepts common cover photo formats including bmp extension', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'onboarding_completed' => true,
        'onboarding_step' => 6,
    ]);
    Sanctum::actingAs($client);

    $bmp = UploadedFile::fake()->create('cover.bmp', 120, 'image/bmp');

    $response = $this->post('/api/v1/profile/cover-photo', [
        'cover_photo' => $bmp,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['cover_photo_url']]);
});

it('rejects non-image profile picture uploads', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $pdf = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $response = $this->post('/api/v1/profile/picture', [
        'profile_picture' => $pdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['profile_picture']);
});

it('rejects non-image cover photo uploads', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $pdf = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $response = $this->post('/api/v1/profile/cover-photo', [
        'cover_photo' => $pdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['cover_photo']);
});
