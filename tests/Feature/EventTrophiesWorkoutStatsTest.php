<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns earned event trophies when challenge progress is 100 percent', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'trophyadmin@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'City Marathon',
        'description' => 'Test event',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee' => 0,
        'fee_type' => 'free',
        'trophies' => [
            [
                'title' => 'Gold Finisher',
                'image_url' => '/storage/admin-event-trophies/gold.png',
            ],
        ],
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 0,
        'progress_logged_km' => 42,
        'progress_goal_km' => 42,
        'progress_target_label' => '42K',
    ]);

    $response = $this->getJson('/api/v1/workouts/stats');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $trophies = $response->json('data.event_trophies');
    expect($trophies)->toBeArray()->toHaveCount(1);
    expect($trophies[0]['title'])->toBe('Gold Finisher');
    expect($trophies[0]['event_title'])->toBe('City Marathon');
    expect($trophies[0]['trophy_key'])->toBe('trophy_1');
});

it('does not return event trophies when progress is below 100 percent', function () {
    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'trophyadmin2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Half Done',
        'description' => 'Test event',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee' => 0,
        'fee_type' => 'free',
        'trophies' => [
            [
                'title' => 'Silver',
                'image_url' => '/storage/admin-event-trophies/silver.png',
            ],
        ],
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 0,
        'progress_logged_km' => 10,
        'progress_goal_km' => 21,
    ]);

    $response = $this->getJson('/api/v1/workouts/stats');

    $response->assertOk();
    expect($response->json('data.event_trophies'))->toEqual([]);
});

it('returns trophies only for top N finishers when event uses top_n award mode', function () {
    $winner = Client::factory()->create();
    $eleventh = Client::factory()->create();
    Sanctum::actingAs($eleventh);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'trophytop@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Top 10 Virtual Race',
        'description' => 'Test event',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee' => 0,
        'fee_type' => 'free',
        'trophy_award_mode' => 'top_n',
        'trophy_top_n' => 10,
        'trophies' => [
            [
                'title' => 'Virtual Trophy',
                'image_url' => '/storage/admin-event-trophies/virtual.png',
            ],
        ],
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $winner->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 0,
        'progress_logged_km' => 50,
        'progress_goal_km' => 10,
        'progress_pace_min_per_km' => 4.5,
    ]);

    for ($i = 0; $i < 9; $i++) {
        $other = Client::factory()->create();
        ClientAdminEventRegistration::create([
            'client_id' => $other->id,
            'admin_event_id' => $event->id,
            'registration_status' => 'confirmed',
            'payment_status' => 'paid',
            'amount_snapshot' => 0,
            'progress_logged_km' => 10,
            'progress_goal_km' => 10,
            'progress_pace_min_per_km' => 6.0 + $i * 0.1,
        ]);
    }

    ClientAdminEventRegistration::create([
        'client_id' => $eleventh->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 0,
        'progress_logged_km' => 10,
        'progress_goal_km' => 10,
        'progress_pace_min_per_km' => 8.0,
    ]);

    $response = $this->getJson('/api/v1/workouts/stats');

    $response->assertOk();
    expect($response->json('data.event_trophies'))->toEqual([]);

    Sanctum::actingAs($winner);
    $winnerResponse = $this->getJson('/api/v1/workouts/stats');
    $winnerResponse->assertOk();
    expect($winnerResponse->json('data.event_trophies'))->toHaveCount(1);
    expect($winnerResponse->json('data.event_trophies.0.title'))->toBe('Virtual Trophy');
});
