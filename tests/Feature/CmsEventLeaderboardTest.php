<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use App\Models\EventProgressSubmission;
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

it('hides registered athletes until they log workout progress', function () {
    $admin = Admin::create([
        'name' => 'Hidden LB Admin',
        'email' => 'hiddenlb@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Hidden Until Logged',
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

    $registeredOnly = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $registeredOnly->id,
        'display_name' => 'Registered Only',
    ]);

    $loggedAthlete = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $loggedAthlete->id,
        'display_name' => 'Logged Athlete',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $registeredOnly->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 0,
        'progress_goal_km' => 12,
        'progress_submission_status' => 'none',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $loggedAthlete->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 5.2,
        'progress_goal_km' => 12,
        'progress_submission_status' => 'approved',
    ]);

    Sanctum::actingAs($registeredOnly);

    $response = $this->getJson("/api/v1/cms/events/{$event->id}/leaderboard?limit=50&include_viewer_rank=1");

    $response->assertOk()
        ->assertJsonPath('data.leaderboard', fn ($rows) => count($rows) === 1)
        ->assertJsonPath('data.leaderboard.0.user.display_name', 'Logged Athlete')
        ->assertJsonPath('data.event.participants_count', 2)
        ->assertJsonPath('data.event.ranked_participants_count', 1)
        ->assertJsonPath('data.viewer_rank', null)
        ->assertJsonPath('data.viewer_state.registered', true)
        ->assertJsonPath('data.viewer_state.visible_on_leaderboard', false);
});

it('shows athletes with pending workout submissions on the leaderboard', function () {
    $admin = Admin::create([
        'name' => 'Pending LB Admin',
        'email' => 'pendinglb@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Pending Review Run',
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

    $pendingAthlete = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $pendingAthlete->id,
        'display_name' => 'Pending Athlete',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $pendingAthlete->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 0,
        'progress_goal_km' => 12,
        'progress_submission_status' => 'pending_review',
    ]);

    EventProgressSubmission::create([
        'client_id' => $pendingAthlete->id,
        'admin_event_id' => $event->id,
        'source' => EventProgressSubmission::SOURCE_WORKOUT,
        'distance_delta_km' => 4.5,
        'status' => EventProgressSubmission::STATUS_PENDING,
    ]);

    $response = $this->getJson("/api/v1/cms/events/{$event->id}/leaderboard?limit=50&include_viewer_rank=0");

    $response->assertOk()
        ->assertJsonPath('data.leaderboard.0.user.display_name', 'Pending Athlete')
        ->assertJsonPath('data.event.ranked_participants_count', 1);
});
