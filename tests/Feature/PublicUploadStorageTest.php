<?php

use App\Support\PublicUploadStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

it('stores workout photos on the configured upload disk', function () {
    Storage::fake('public');
    Config::set('filesystems.upload_disk', 'public');

    $file = UploadedFile::fake()->create('workout.jpg', 120, 'image/jpeg');
    $reference = PublicUploadStorage::storePublicReference($file, 'workout-photos');

    expect($reference)->toStartWith('/api/v1/profile/media/workout-photos/');
    expect(Storage::disk('public')->allFiles('workout-photos'))->toHaveCount(1);
});

it('normalizes legacy storage paths to api media urls', function () {
    Config::set('filesystems.upload_disk', 'public');

    $normalized = PublicUploadStorage::normalizePublicUrl('/storage/workout-photos/abc.jpg');

    expect($normalized)->toBe('/api/v1/profile/media/workout-photos/abc.jpg');
});

it('extracts relative paths from api media urls and storage paths', function () {
    expect(PublicUploadStorage::extractRelativePath('/storage/workout-photos/a.jpg'))
        ->toBe('workout-photos/a.jpg');
    expect(PublicUploadStorage::extractRelativePath('/api/v1/profile/media/workout-photos/a.jpg'))
        ->toBe('workout-photos/a.jpg');
});

it('builds remote cdn urls when upload disk is s3', function () {
    Config::set('filesystems.upload_disk', 's3');
    Config::set('filesystems.disks.s3.url', 'https://cdn.example.com');

    expect(PublicUploadStorage::isRemote())->toBeTrue();
    expect(PublicUploadStorage::publicUrl('workout-photos/sample.jpg'))
        ->toBe('https://cdn.example.com/workout-photos/sample.jpg');
});
