<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('returns a verified public badge share payload for earned badges', function () {
    $client = Client::factory()->create(['email' => 'runner@example.com']);
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Alex Runner',
    ]);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'badgeadmin@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Summer 10K Challenge',
        'description' => 'Test event',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee' => 499,
        'fee_type' => 'paid',
        'badges' => [
            [
                'title' => '10K Finisher',
                'image_url' => '/storage/admin-event-badges/finisher.png',
            ],
        ],
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 499,
        'progress_logged_km' => 10,
        'progress_goal_km' => 10,
        'progress_target_label' => '10K',
    ]);

    $response = $this->getJson("/api/v1/public/badges/{$client->id}/{$event->id}/badge_1");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.owner.display_name', 'Alex Runner')
        ->assertJsonPath('data.badge.title', '10K Finisher')
        ->assertJsonPath('data.badge.event_title', 'Summer 10K Challenge')
        ->assertJsonPath('data.badge.badge_key', 'badge_1');
});

it('returns 404 when badge was not earned', function () {
    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'badgeadmin2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Incomplete Challenge',
        'description' => 'Test event',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee' => 499,
        'fee_type' => 'paid',
        'badges' => [
            [
                'title' => 'Finisher',
                'image_url' => '/storage/admin-event-badges/finisher.png',
            ],
        ],
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 499,
        'progress_logged_km' => 4,
        'progress_goal_km' => 10,
    ]);

    $this->getJson("/api/v1/public/badges/{$client->id}/{$event->id}/badge_1")
        ->assertNotFound()
        ->assertJsonPath('success', false);
});
