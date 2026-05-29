<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

it('serves admin event cover images through the public media route', function () {
    Storage::fake('public');
    Config::set('filesystems.upload_disk', 'public');
    Storage::disk('public')->put('admin-events/cover.jpg', 'fake-image-bytes');

    $this->get('/api/v1/profile/media/admin-events/cover.jpg')
        ->assertOk();
});

it('serves admin event badge images through the public media route', function () {
    Storage::fake('public');
    Config::set('filesystems.upload_disk', 'public');
    Storage::disk('public')->put('admin-event-badges/badge.png', 'fake-badge-bytes');

    $this->get('/api/v1/profile/media/admin-event-badges/badge.png')
        ->assertOk();
});

it('rejects unknown directories on the public media route', function () {
    $this->getJson('/api/v1/profile/media/private/secret.jpg')
        ->assertForbidden()
        ->assertJsonPath('message', 'Media path not allowed.');
});
