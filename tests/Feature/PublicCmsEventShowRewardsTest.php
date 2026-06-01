<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns badges and trophies on public event details', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'public-rewards@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Rewards Showcase',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/cover.jpg',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addMonth(),
        'badges' => [
            ['title' => 'Finisher Badge', 'image_url' => '/storage/admin-event-badges/badge.png'],
        ],
        'trophies' => [
            ['title' => 'Gold Trophy', 'image_url' => '/storage/admin-event-trophies/gold.png'],
        ],
    ]);

    $response = $this->getJson("/api/v1/cms/events/{$event->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.event.badges.0.title', 'Finisher Badge')
        ->assertJsonPath('data.event.trophies.0.title', 'Gold Trophy');

    expect($response->json('data.event.badges.0.image_url'))->toContain('admin-event-badges');
    expect($response->json('data.event.trophies.0.image_url'))->toContain('admin-event-trophies');
});
