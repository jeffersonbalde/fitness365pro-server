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

it('accepts mobile-like profile uploads without file extension', function () {
    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'onboarding_completed' => true,
        'onboarding_step' => 6,
    ]);
    Sanctum::actingAs($client);

    $tmp = tempnam(sys_get_temp_dir(), 'mobile-profile');
    file_put_contents($tmp, hex2bin('FFD8FFE000104A4649460000'));
    $jpeg = new UploadedFile($tmp, 'IMG_001', 'application/octet-stream', null, true);

    try {
        $response = $this->post('/api/v1/profile/picture', [
            'profile_picture' => $jpeg,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['profile_picture_url']]);
    } finally {
        @unlink($tmp);
    }
});

it('returns a helpful validation message for profile picture failures', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $pdf = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $response = $this->post('/api/v1/profile/picture', [
        'profile_picture' => $pdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['profile_picture'])
        ->assertJsonPath('message', 'The uploaded file must be a valid image (JPEG, PNG, GIF, WebP, HEIC, BMP, and other common formats).');
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
