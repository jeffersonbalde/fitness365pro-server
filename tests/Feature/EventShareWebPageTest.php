<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('renders an open graph share page for active published events', function () {
    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'eventshare@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'City Marathon 2026',
        'description' => 'Run with us',
        'image_url' => '/storage/admin-events/marathon.jpg',
        'location' => 'Quezon City',
        'location_type' => 'onsite',
        'venue' => 'Quezon Memorial Circle',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'paid',
        'fee' => 899,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addMonth(),
    ]);

    $response = $this->get("/share/event/{$event->id}");

    $response->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('City Marathon 2026', false)
        ->assertSee('og:image', false)
        ->assertSee('admin-events/marathon.jpg', false)
        ->assertSee('og:image:width', false)
        ->assertSee('Quezon Memorial Circle', false)
        ->assertSee('View event &amp; register', false);
});

it('returns not found share page when event is not active', function () {
    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'eventshare2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Ended Challenge',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
    ]);

    $this->get("/share/event/{$event->id}")
        ->assertOk()
        ->assertSee('Event not found', false);
});

it('renders leaderboard rank-card open graph on event share url with standing query', function () {
    if (! Schema::hasTable('client_admin_event_registrations')) {
        $this->markTestSkipped('Registrations table not available.');
    }

    $admin = Admin::create([
        'name' => 'Event LB Admin',
        'email' => 'eventlb@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Independence Day Run',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/run.jpg',
        'location' => 'Metro Manila',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Jefferson Balde',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 0,
        'progress_goal_km' => 12,
        'progress_percent' => 0,
    ]);

    $this->get("/share/event/{$event->id}?standing={$client->id}")
        ->assertOk()
        ->assertSee('on Independence Day Run', false)
        ->assertSee('#1 on Independence Day Run', false)
        ->assertSee('Jefferson Balde', false)
        ->assertSee('card.png', false)
        ->assertSee('standing=', false);
});
