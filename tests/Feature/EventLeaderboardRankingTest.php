<?php

use App\Models\Admin;
use App\Models\AdminEvent;
use App\Models\Client;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientProfile;
use App\Services\EventLeaderboardRankingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('ranks finishers by goal completion time instead of total logged km', function () {
    if (! Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at')) {
        $this->markTestSkipped('progress_goal_completed_at column not available.');
    }

    $admin = Admin::create([
        'name' => 'Rank Admin',
        'email' => 'rank@example.com',
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

    $firstFinisher = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $firstFinisher->id,
        'display_name' => 'Mae Ann Galvez',
    ]);

    $secondFinisher = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $secondFinisher->id,
        'display_name' => 'Salvador Gamotin',
    ]);

    $nonFinisher = Client::factory()->create();
    ClientProfile::create([
        'client_id' => $nonFinisher->id,
        'display_name' => 'Jojo Galvez',
    ]);

    $earlier = Carbon::parse('2026-06-01 10:00:00');
    $later = Carbon::parse('2026-06-02 15:30:00');

    $maeReg = ClientAdminEventRegistration::create([
        'client_id' => $firstFinisher->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 12.23,
        'progress_goal_km' => 12,
        'progress_percent' => 100,
        'progress_goal_completed_at' => $earlier,
    ]);

    $salvadorReg = ClientAdminEventRegistration::create([
        'client_id' => $secondFinisher->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 21.16,
        'progress_goal_km' => 12,
        'progress_percent' => 100,
        'progress_goal_completed_at' => $later,
    ]);

    $jojoReg = ClientAdminEventRegistration::create([
        'client_id' => $nonFinisher->id,
        'admin_event_id' => $event->id,
        'registration_status' => 'confirmed',
        'payment_status' => 'paid',
        'progress_logged_km' => 4.7,
        'progress_goal_km' => 12,
        'progress_percent' => 39.2,
        'progress_goal_completed_at' => null,
    ]);

    $ranking = app(EventLeaderboardRankingService::class);

    expect($ranking->compareRegistrations($event, $maeReg, $salvadorReg))->toBeLessThan(0)
        ->and($ranking->compareRegistrations($event, $salvadorReg, $maeReg))->toBeGreaterThan(0)
        ->and($ranking->compareRegistrations($event, $maeReg, $jojoReg))->toBeLessThan(0)
        ->and($ranking->compareRegistrations($event, $salvadorReg, $jojoReg))->toBeLessThan(0);

    $baseQuery = ClientAdminEventRegistration::query()
        ->where('admin_event_id', $event->id)
        ->where('registration_status', 'confirmed');

    expect($ranking->rankForRegistration($event, $baseQuery, $maeReg, true))->toBe(1)
        ->and($ranking->rankForRegistration($event, $baseQuery, $salvadorReg, true))->toBe(2)
        ->and($ranking->rankForRegistration($event, $baseQuery, $jojoReg, true))->toBe(3)
        ->and($ranking->countRankedAhead($event, $baseQuery, $maeReg, true))->toBe(0)
        ->and($ranking->countRankedAhead($event, $baseQuery, $salvadorReg, true))->toBe(1);
});
