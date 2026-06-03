<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.paymaya.public_key', 'pk-test');
    Config::set('services.paymaya.secret_key', 'sk-test');
    Config::set('services.paymaya.sandbox', true);
});

it('confirms registration when maya webhook reports payment success', function () {
    $client = Client::factory()->create();
    $admin = Admin::create([
        'name' => 'Admin',
        'email' => 'pay-webhook@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Paid Run',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'paid',
        'fee' => 12,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addMonth(),
    ]);

    $reg = ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'pending_payment',
        'payment_status' => 'pending_checkout',
        'amount_snapshot' => 12,
        'paymaya_checkout_id' => 'checkout-abc123',
        'paymaya_rrn' => 'f365-lb6ylkoudtnkcxdqbm2bdjvuop3g',
    ]);

    $response = $this->postJson('/api/v1/paymaya/webhook', [
        'checkoutId' => 'checkout-abc123',
        'requestReferenceNumber' => 'f365-lb6ylkoudtnkcxdqbm2bdjvuop3g',
        'paymentStatus' => 'PAYMENT_SUCCESS',
    ]);

    $response->assertOk()->assertJsonPath('success', true);

    $reg->refresh();
    expect($reg->registration_status)->toBe('confirmed')
        ->and($reg->payment_status)->toBe('paid');
});

it('syncs pending registration via checkout id', function () {
    Http::fake([
        'https://pg-sandbox.paymaya.com/checkout/v1/checkouts/checkout-live-1' => Http::response([
            'checkoutId' => 'checkout-live-1',
            'paymentStatus' => 'PAYMENT_SUCCESS',
        ], 200),
    ]);

    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'Admin',
        'email' => 'pay-sync@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Sync Run',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'paid',
        'fee' => 12,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addMonth(),
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'pending_payment',
        'payment_status' => 'pending_checkout',
        'amount_snapshot' => 12,
        'paymaya_checkout_id' => 'checkout-live-1',
        'paymaya_rrn' => 'f365-testrrn123',
    ]);

    $response = $this->postJson("/api/v1/cms/events/{$event->id}/registration/paymaya/sync");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.paid', true);

    $this->assertDatabaseHas('client_admin_event_registrations', [
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
    ]);
});

it('syncs pending registration via rrn when checkout lookup fails', function () {
    Http::fake([
        'https://pg-sandbox.paymaya.com/checkout/v1/checkouts/*' => Http::response([], 404),
        'https://pg-sandbox.paymaya.com/payments/v1/payment-rrns/*' => Http::response([
            'requestReferenceNumber' => 'f365-qrpaid999',
            'paymentStatus' => 'PAYMENT_SUCCESS',
        ], 200),
    ]);

    $client = Client::factory()->create();
    Sanctum::actingAs($client);

    $admin = Admin::create([
        'name' => 'Admin',
        'email' => 'pay-rrn@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'QR Run',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'paid',
        'fee' => 12,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addMonth(),
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'pending_payment',
        'payment_status' => 'pending_checkout',
        'amount_snapshot' => 12,
        'paymaya_checkout_id' => 'checkout-missing',
        'paymaya_rrn' => 'f365-qrpaid999',
    ]);

    $response = $this->postJson("/api/v1/cms/events/{$event->id}/registration/paymaya/sync");

    $response->assertOk()->assertJsonPath('data.paid', true);

    $this->assertDatabaseHas('client_admin_event_registrations', [
        'client_id' => $client->id,
        'payment_status' => 'paid',
    ]);
});
