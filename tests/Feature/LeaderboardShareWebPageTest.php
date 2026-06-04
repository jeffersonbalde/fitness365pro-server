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

it('renders an open graph share page for a confirmed leaderboard standing', function () {
    if (! Schema::hasTable('client_admin_event_registrations')) {
        $this->markTestSkipped('Registrations table not available.');
    }

    $admin = Admin::create([
        'name' => 'LB Admin',
        'email' => 'lbshare@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Summer Run Challenge',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/summer.jpg',
        'location' => 'Metro Manila',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'mileage_challenge_km' => 50,
    ]);

    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Alex Runner',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 12.5,
        'progress_goal_km' => 50,
        'progress_percent' => 25,
    ]);

    $response = $this->get("/share/leaderboard/{$event->id}/{$client->id}");

    $response->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('Alex Runner', false)
        ->assertSee('Summer Run Challenge', false)
        ->assertSee('og:image', false)
        ->assertSee('card.png', false)
        ->assertSee('View leaderboard', false);
});

it('returns a png rank card image for a confirmed standing', function () {
    if (! Schema::hasTable('client_admin_event_registrations')) {
        $this->markTestSkipped('Registrations table not available.');
    }

    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension not available.');
    }

    $admin = Admin::create([
        'name' => 'LB Card Admin',
        'email' => 'lbcard@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Card Test Run',
        'description' => 'Test',
        'location' => 'Online',
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
        'display_name' => 'Card Tester',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 3,
        'progress_goal_km' => 10,
        'progress_percent' => 30,
    ]);

    $this->get("/share/leaderboard/{$event->id}/{$client->id}/card.png")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('returns not found when member is not on the leaderboard', function () {
    $admin = Admin::create([
        'name' => 'LB Admin 2',
        'email' => 'lbshare2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Empty Board',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $client = Client::factory()->create();

    $this->get("/share/leaderboard/{$event->id}/{$client->id}")
        ->assertOk()
        ->assertSee('Leaderboard standing not found', false)
        ->assertSee('og:image', false)
        ->assertSee('Fitness 365 Pro Leaderboard', false);
});

it('renders open graph for a completed event standing', function () {
    if (! Schema::hasTable('client_admin_event_registrations')) {
        $this->markTestSkipped('Registrations table not available.');
    }

    $admin = Admin::create([
        'name' => 'LB Admin 3',
        'email' => 'lbshare3@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Finished Run',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDay(),
    ]);

    $client = Client::factory()->create();
    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 5,
        'progress_goal_km' => 10,
        'progress_percent' => 50,
    ]);

    $this->get("/share/leaderboard/{$event->id}/{$client->id}")
        ->assertOk()
        ->assertSee('og:title', false)
        ->assertSee('Finished Run', false);
});
