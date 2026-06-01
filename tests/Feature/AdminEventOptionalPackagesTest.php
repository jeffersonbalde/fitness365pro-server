<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows admin to save a running event with no registration packages', function () {
    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'optional-pkg@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    Sanctum::actingAs($admin, [], 'admin');

    $payload = [
        'title' => 'Distance Only Run',
        'description' => str_repeat('Test event description. ', 8),
        'image_url' => '/storage/admin-events/cover.jpg',
        'location' => 'Manila',
        'category' => 'running',
        'location_type' => 'online',
        'registration_starts_at' => now()->subDay()->toIso8601String(),
        'registration_deadline' => now()->addWeek()->toIso8601String(),
        'starts_at' => now()->addDays(2)->toIso8601String(),
        'ends_at' => now()->addMonth()->toIso8601String(),
        'fee_type' => 'free',
        'fee' => 0,
        'status' => 'published',
        'how_it_works' => ['Register and run.'],
        'participant_rules' => ['Be fair.'],
        'running_details' => [
            'distances' => [['key' => '5k']],
            'packages' => [],
            'shirt_sizes' => [],
        ],
    ];

    $response = $this->postJson('/api/v1/admin/events', $payload);

    $response->assertCreated()
        ->assertJsonPath('success', true);

    $event = AdminEvent::query()->where('title', 'Distance Only Run')->first();
    expect($event)->not->toBeNull();
    expect($event->running_details['packages'] ?? null)->toEqual([]);
});

it('lets a user register for a running event without choosing a package when none are offered', function () {
    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'optional-pkg2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'No Package Run',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/cover.jpg',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addMonth(),
        'running_details' => [
            'distances' => [['key' => '10k']],
            'packages' => [],
            'shirt_sizes' => [],
        ],
    ]);

    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $selectionResponse = $this->putJson("/api/v1/cms/events/{$event->id}/running-selection", [
        'distance_key' => '10k',
        'package_key' => '',
    ]);

    $selectionResponse->assertOk()->assertJsonPath('success', true);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'draft',
        'payment_status' => 'unpaid',
    ]);

    $row = ClientAdminEventRunningSelection::query()
        ->where('client_id', $client->id)
        ->where('admin_event_id', $event->id)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->distance_key)->toBe('10k');
    expect($row->package_key)->toBe('');
});

it('confirms registration without kit delivery when no packages are offered', function () {
    $admin = Admin::create([
        'name' => 'CMS Admin',
        'email' => 'optional-pkg3@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Confirm Without Delivery',
        'description' => 'Test',
        'image_url' => '/storage/admin-events/cover.jpg',
        'location' => 'Manila',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'fee' => 0,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addMonth(),
        'running_details' => [
            'distances' => [['key' => '5k']],
            'packages' => [],
            'shirt_sizes' => [],
        ],
    ]);

    $client = Client::factory()->create([
        'email' => 'confirm-no-delivery@example.com',
    ]);
    Sanctum::actingAs($client);

    $participant = [
        'first_name' => 'Test',
        'last_name' => 'Runner',
        'date_of_birth' => '1990-01-15',
        'email' => $client->email,
        'phone' => '09171234567',
        'country' => 'Philippines',
        'street_address' => '123 Sample Street Building',
        'province' => 'Zamboanga del Sur',
        'city' => 'Pagadian City',
        'barangay' => 'Sample',
    ];

    $this->putJson("/api/v1/cms/events/{$event->id}/registration/participant", [
        'participant' => $participant,
        'wizard_running_distance' => [
            'distance_key' => '5k',
        ],
    ])->assertOk();

    $this->putJson("/api/v1/cms/events/{$event->id}/running-selection", [
        'distance_key' => '5k',
        'package_key' => '',
    ])->assertOk();

    $this->getJson("/api/v1/cms/events/{$event->id}/registration")
        ->assertOk()
        ->assertJsonPath('data.requires_kit_delivery', false);

    $confirm = $this->postJson("/api/v1/cms/events/{$event->id}/registration/confirm");

    $confirm->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.confirmed', true)
        ->assertJsonPath('data.delivery_fee_php', 0);
});
