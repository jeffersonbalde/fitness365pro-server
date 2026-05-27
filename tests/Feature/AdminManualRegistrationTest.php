<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientNotification;
use App\Models\ClientProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('allows admin to manually register a member for an event', function () {
    $admin = Admin::create([
        'name' => 'Office Admin',
        'email' => 'officeadmin@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $client = Client::factory()->create(['email' => 'member@example.com']);
    ClientProfile::create([
        'client_id' => $client->id,
        'first_name' => 'Maria',
        'last_name' => 'Santos',
        'display_name' => 'Maria Santos',
        'phone' => '09171234567',
        'city' => 'Pagadian City',
    ]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Virtual Run 5K',
        'description' => 'Office registration test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee' => 500,
        'fee_type' => 'paid',
        'mileage_challenge_km' => 5,
        'registration_starts_at' => now()->subDay(),
        'registration_deadline' => now()->addWeek(),
    ]);

    Sanctum::actingAs($admin, ['*'], 'admin');

    $response = $this->postJson("/api/v1/admin/events/{$event->id}/registrations/manual", [
        'client_id' => $client->id,
        'payment_method' => 'cash',
        'amount_received' => 500,
        'manual_payment_reference' => 'OR-1001',
        'admin_registration_note' => 'Paid at main office',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.registration.registration_status', 'confirmed')
        ->assertJsonPath('data.registration.payment_status', 'paid')
        ->assertJsonPath('data.registration.payment_method', 'cash')
        ->assertJsonPath('data.registration.manual_payment_reference', 'OR-1001');

    $reg = ClientAdminEventRegistration::query()->where('client_id', $client->id)->first();
    expect($reg)->not->toBeNull();
    expect($reg->registration_status)->toBe('confirmed');
    expect($reg->payment_status)->toBe('paid');
    expect($reg->payment_method)->toBe('cash');
    expect($reg->registered_by_admin_id)->toBe($admin->id);
    expect((float) $reg->progress_goal_km)->toBe(5.0);

    expect(ClientNotification::query()->where('recipient_client_id', $client->id)->count())->toBe(1);

    Sanctum::actingAs($client);
    $stats = $this->getJson('/api/v1/workouts/stats');
    $stats->assertOk();
    $joined = collect($stats->json('data.joined_challenge_events'));
    expect($joined->pluck('event_id'))->toContain((string) $event->id);
});

it('rejects duplicate manual registration for the same member and event', function () {
    $admin = Admin::create([
        'name' => 'Office Admin',
        'email' => 'officeadmin2@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Gym Challenge',
        'description' => 'Test',
        'location' => 'Gym',
        'category' => 'gym',
        'status' => 'published',
        'fee_type' => 'free',
    ]);

    ClientAdminEventRegistration::create([
        'client_id' => $client->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'free',
    ]);

    Sanctum::actingAs($admin, ['*'], 'admin');

    $response = $this->postJson("/api/v1/admin/events/{$event->id}/registrations/manual", [
        'client_id' => $client->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false);
});

it('allows manual registration when registration window is closed if override is enabled', function () {
    $admin = Admin::create([
        'name' => 'Office Admin',
        'email' => 'officeadmin3@example.com',
        'password' => Hash::make('Password123!'),
    ]);

    $client = Client::factory()->create();
    ClientProfile::create(['client_id' => $client->id]);

    $event = AdminEvent::create([
        'admin_id' => $admin->id,
        'title' => 'Late Registration Event',
        'description' => 'Test',
        'location' => 'Online',
        'category' => 'running',
        'status' => 'published',
        'fee_type' => 'free',
        'registration_deadline' => now()->subDay(),
    ]);

    Sanctum::actingAs($admin, ['*'], 'admin');

    $blocked = $this->postJson("/api/v1/admin/events/{$event->id}/registrations/manual", [
        'client_id' => $client->id,
    ]);
    $blocked->assertStatus(422);

    $allowed = $this->postJson("/api/v1/admin/events/{$event->id}/registrations/manual", [
        'client_id' => $client->id,
        'ignore_registration_window' => true,
    ]);
    $allowed->assertCreated()->assertJsonPath('success', true);
});
