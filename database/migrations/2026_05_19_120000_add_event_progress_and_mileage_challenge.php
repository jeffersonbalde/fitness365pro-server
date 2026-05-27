<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin_events')) {
            Schema::table('admin_events', function (Blueprint $table) {
                if (! Schema::hasColumn('admin_events', 'mileage_challenge_km')) {
                    $table->decimal('mileage_challenge_km', 12, 4)->nullable()->after('fee_type');
                }
            });
        }

        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                if (! Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km')) {
                    $table->decimal('progress_logged_km', 12, 4)->default(0)->after('paymaya_payment_status_snapshot');
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'progress_goal_km')) {
                    $table->decimal('progress_goal_km', 12, 4)->nullable()->after('progress_logged_km');
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'progress_target_label')) {
                    $table->string('progress_target_label', 120)->nullable()->after('progress_goal_km');
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'progress_pace_min_per_km')) {
                    $table->decimal('progress_pace_min_per_km', 8, 4)->nullable()->after('progress_target_label');
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'progress_submission_status')) {
                    $table->string('progress_submission_status', 32)->default('none')->after('progress_pace_min_per_km');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admin_events') && Schema::hasColumn('admin_events', 'mileage_challenge_km')) {
            Schema::table('admin_events', function (Blueprint $table) {
                $table->dropColumn('mileage_challenge_km');
            });
        }

        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                foreach (['progress_logged_km', 'progress_goal_km', 'progress_target_label', 'progress_pace_min_per_km', 'progress_submission_status'] as $col) {
                    if (Schema::hasColumn('client_admin_event_registrations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
