<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns event leaderboard for an authenticated client', function () {
    $admin = Admin::create([
        'name' => 'LB Admin',
        'email' => 'cmslb@example.com',
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
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'mileage_challenge_km' => 12,
    ]);

    $viewer = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $viewer->id,
        'display_name' => 'Mae Ann Galvez',
    ]);

    $other = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $other->id,
        'display_name' => 'Salvador Gamotin',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $viewer->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 12.23,
        'progress_goal_km' => 12,
        'progress_percent' => 100,
        'progress_goal_completed_at' => Carbon::parse('2026-06-01 10:00:00'),
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $other->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 21.16,
        'progress_goal_km' => 12,
        'progress_percent' => 100,
        'progress_goal_completed_at' => Carbon::parse('2026-06-02 15:30:00'),
    ]);

    Sanctum::actingAs($viewer);

    $response = $this->getJson("/api/v1/cms/events/{$event->id}/leaderboard?limit=50&include_viewer_rank=1");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.leaderboard.0.user.display_name', 'Mae Ann Galvez')
        ->assertJsonPath('data.leaderboard.1.user.display_name', 'Salvador Gamotin')
        ->assertJsonPath('data.viewer_rank.rank', 1);
});

it('returns event leaderboard without authentication when viewer rank is skipped', function () {
    $admin = Admin::create([
        'name' => 'Public LB Admin',
        'email' => 'publiclb@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Public Run',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/run.jpg',
        'location' => 'Metro Manila',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
        'mileage_challenge_km' => 12,
    ]);

    $client = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $client->id,
        'display_name' => 'Public Runner',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 8.5,
        'progress_goal_km' => 12,
        'progress_percent' => 70.8,
    ]);

    $response = $this->getJson("/api/v1/cms/events/{$event->id}/leaderboard?limit=50&include_viewer_rank=0");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.leaderboard.0.user.display_name', 'Public Runner')
        ->assertJsonPath('data.viewer_rank', null);
});
