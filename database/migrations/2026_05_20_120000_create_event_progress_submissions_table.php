<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_progress_submissions')) {
            Schema::create('event_progress_submissions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('client_id');
                $table->uuid('admin_event_id');
                $table->uuid('workout_log_id')->nullable();
                $table->string('source', 16);
                $table->decimal('distance_delta_km', 12, 4);
                $table->decimal('pace_min_per_km', 10, 4)->nullable();
                $table->string('status', 16)->default('pending');
                $table->uuid('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 600)->nullable();
                $table->timestamps();

                $table->index(['admin_event_id', 'status']);
                $table->index(['client_id', 'status']);
                $table->index('workout_log_id');
            });
        }

        if (Schema::hasTable('workout_logs') && ! Schema::hasColumn('workout_logs', 'challenge_progress_approved_km')) {
            Schema::table('workout_logs', function (Blueprint $table) {
                $table->decimal('challenge_progress_approved_km', 12, 4)->nullable()->after('admin_event_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workout_logs') && Schema::hasColumn('workout_logs', 'challenge_progress_approved_km')) {
            Schema::table('workout_logs', function (Blueprint $table) {
                $table->dropColumn('challenge_progress_approved_km');
            });
        }

        Schema::dropIfExists('event_progress_submissions');
    }
};
