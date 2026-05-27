<?php

namespace App\Services;

use App\Models\AdminEvent;
use App\Models\ClientAdminEventRegistration;
use App\Models\ClientAdminEventRunningSelection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EventEnrollmentProgressService
{
    public static function distanceKeyToKm(?string $key, ?string $label): ?float
    {
        $k = strtolower(trim((string) $key));

        $canonical = [
            '3k' => 3.0,
            '5k' => 5.0,
            '10k' => 10.0,
            '21k' => 21.0975,
            '42k' => 42.195,
        ];

        if (isset($canonical[$k])) {
            return $canonical[$k];
        }

        if (preg_match('/^(\d+)k$/', $k, $m)) {
            return (float) $m[1];
        }

        $lab = strtolower((string) $label);
        if (preg_match('/(\d+(?:\.\d+)?)\s*k(?:m)?\b/i', $lab, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    public static function runningTargetDisplay(?string $key, ?string $label): ?string
    {
        $k = strtolower(trim((string) $key));
        if ($k === 'other' && trim((string) $label) !== '') {
            return Str::limit(trim((string) $label), 48, '…');
        }

        $map = [
            '3k' => '3K',
            '5k' => '5K',
            '10k' => '10K',
            '21k' => 'Half marathon (21K)',
            '42k' => 'Marathon (42K)',
        ];

        if (isset($map[$k])) {
            return $map[$k];
        }

        if (preg_match('/^(\d+)k$/', $k, $m)) {
            return $m[1].'K';
        }

        return null;
    }

    /**
     * Copies goal distance label + progress goal from enrollment (and optional event mileage challenge override).
     */
    public static function syncRegistrationGoals(AdminEvent $event, ClientAdminEventRegistration $reg, string $clientId): void
    {
        $table = $reg->getTable();
        if (! Schema::hasColumn($table, 'progress_goal_km')) {
            return;
        }

        $category = strtolower((string) ($event->category ?? ''));
        $eventChallenge = Schema::hasColumn('admin_events', 'mileage_challenge_km')
            ? (float) ($event->mileage_challenge_km ?? 0)
            : 0.0;

        $distanceKm = null;
        $targetLabel = null;

        if ($category === 'running' && Schema::hasTable('client_admin_event_running_selections')) {
            $sel = ClientAdminEventRunningSelection::query()
                ->where('client_id', $clientId)
                ->where('admin_event_id', $event->id)
                ->first();

            if ($sel) {
                $distanceKm = static::distanceKeyToKm((string) $sel->distance_key, $sel->distance_label !== null ? (string) $sel->distance_label : null);
                $targetLabel = static::runningTargetDisplay((string) $sel->distance_key, $sel->distance_label !== null ? (string) $sel->distance_label : null)
                    ?: null;
            }
        } elseif ($category === 'gym') {
            $targetLabel = 'Gym enrolment';
        }

        $effectiveGoal = ($eventChallenge > 0 ? $eventChallenge : null);

        if ($effectiveGoal === null && $distanceKm !== null && $distanceKm > 0) {
            $effectiveGoal = $distanceKm;
        }

        if ($effectiveGoal === null || $effectiveGoal <= 0) {
            $reg->progress_goal_km = null;
        } else {
            $reg->progress_goal_km = round($effectiveGoal, 4);
            if (($targetLabel === null || trim($targetLabel) === '') && $distanceKm !== null && $distanceKm > 0) {
                $targetLabel = round($distanceKm, 2).' km target';
            }
        }

        $reg->progress_target_label = $targetLabel;
    }
}
