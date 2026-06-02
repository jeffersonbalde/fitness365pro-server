<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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
        ->assertSee('/storage/admin-events/marathon.jpg', false)
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
