<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_admin_event_registrations', 'registration_status')) {
                $table->index(
                    ['admin_event_id', 'registration_status'],
                    'caer_event_status_idx'
                );
            }

            if (Schema::hasColumn('client_admin_event_registrations', 'progress_logged_km')) {
                $table->index(
                    ['admin_event_id', 'progress_logged_km'],
                    'caer_event_progress_idx'
                );
            }
        });

        if (Schema::hasTable('client_admin_event_running_selections')) {
            Schema::table('client_admin_event_running_selections', function (Blueprint $table) {
                $table->index(
                    ['admin_event_id', 'distance_key'],
                    'caers_event_distance_idx'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                $table->dropIndex('caer_event_status_idx');
                $table->dropIndex('caer_event_progress_idx');
            });
        }

        if (Schema::hasTable('client_admin_event_running_selections')) {
            Schema::table('client_admin_event_running_selections', function (Blueprint $table) {
                $table->dropIndex('caers_event_distance_idx');
            });
        }
    }
};
