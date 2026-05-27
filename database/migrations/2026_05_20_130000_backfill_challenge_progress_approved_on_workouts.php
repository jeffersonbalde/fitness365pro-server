<?php

use App\Models\WorkoutLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Treat historical linked workout distance as already admin-approved so new edits
     * only queue net change (avoids double-counting after moderation ships).
     */
    public function up(): void
    {
        if (! Schema::hasColumn('workout_logs', 'challenge_progress_approved_km')) {
            return;
        }

        WorkoutLog::query()
            ->whereNotNull('admin_event_id')
            ->where('status', 'completed')
            ->where('entry_type', 'workout')
            ->whereNotNull('distance_km')
            ->where('distance_km', '>', 0)
            ->whereNull('challenge_progress_approved_km')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $w) {
                    $w->challenge_progress_approved_km = round((float) $w->distance_km, 4);
                    $w->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data migration
    }
};
