<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns the authenticated client transaction history', function () {
    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'eventadmin@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Summer Run Challenge',
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
        'amount_snapshot' => 549,
        'delivery_fee_snapshot' => 50,
        'paymaya_rrn' => 'RRN123456',
    ]);

    $response = $this->getJson('/api/v1/profile/transactions');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.transactions.0.event.title', 'Summer Run Challenge')
        ->assertJsonPath('data.transactions.0.payment_status', 'paid')
        ->assertJsonPath('data.transactions.0.registration_fee', 499)
        ->assertJsonPath('data.transactions.0.delivery_fee', 50)
        ->assertJsonPath('data.transactions.0.total_amount', 549)
        ->assertJsonPath('data.transactions.0.paymaya_rrn', 'RRN123456')
        ->assertJsonPath('data.transactions.0.description', 'Test event');
});

it('does not expose another clients transactions', function () {
    $viewer = Client::factory()->create();
    $other = Client::factory()->create();
    Sanctum::actingAs($viewer);

    $admin = Admin::create([
        'name' => 'Event Admin',
        'email' => 'eventadmin2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Private Event',
        'description' => 'Test event',
        'location' => 'Cebu',
        'category' => 'running',
        'status' => 'published',
        'fee' => 300,
        'fee_type' => 'paid',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $other->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'amount_snapshot' => 300,
    ]);

    $response = $this->getJson('/api/v1/profile/transactions');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonCount(0, 'data.transactions');
});
