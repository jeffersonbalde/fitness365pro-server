<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('renders an open graph share page for earned badges', function () {
    $client = Client::factory()->create(['email' => 'runner@example.com']);
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Alex Runner',
    ]);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'badgeadmin-web@example.com',
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

    $response = $this->get("/share/badge/{$client->id}/{$event->id}/badge_1");

    $response->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('10K Finisher', false)
        ->assertSee('Alex Runner', false)
        ->assertSee('og:image', false)
        ->assertSee('/storage/admin-event-badges/finisher.png', false);
});

it('returns not found share page when badge is not earned', function () {
    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'badgeadmin-web2@example.com',
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
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 499,
        'progress_logged_km' => 2,
        'progress_goal_km' => 10,
    ]);

    $this->get("/share/badge/{$client->id}/{$event->id}/badge_1")
        ->assertOk()
        ->assertSee('Badge not found', false);
});
