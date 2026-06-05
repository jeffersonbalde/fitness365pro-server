<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                if (Schema::hasColumn('client_admin_event_registrations', 'progress_goal_completed_at')) {
                    $table->index(
                        ['admin_event_id', 'registration_status', 'progress_goal_completed_at', 'progress_logged_km'],
                        'cae_reg_leaderboard_rank_idx'
                    );
                }
                $table->index(['client_id', 'updated_at'], 'cae_reg_client_updated_idx');
            });
        }

        if (Schema::hasTable('workout_logs')) {
            Schema::table('workout_logs', function (Blueprint $table) {
                $table->index(['client_id', 'status', 'workout_date', 'created_at'], 'wl_client_status_dates_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                $table->dropIndex('cae_reg_leaderboard_rank_idx');
                $table->dropIndex('cae_reg_client_updated_idx');
            });
        }

        if (Schema::hasTable('workout_logs')) {
            Schema::table('workout_logs', function (Blueprint $table) {
                $table->dropIndex('wl_client_status_dates_idx');
            });
        }
    }
};
