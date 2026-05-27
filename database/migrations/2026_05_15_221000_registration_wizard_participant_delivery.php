<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_profiles')) {
            Schema::table('client_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('client_profiles', 'phone')) {
                    $table->string('phone', 32)->nullable();
                }
                if (! Schema::hasColumn('client_profiles', 'street_address')) {
                    $table->string('street_address', 240)->nullable();
                }
                if (! Schema::hasColumn('client_profiles', 'barangay')) {
                    $table->string('barangay', 120)->nullable();
                }
            });
        }

        if (Schema::hasTable('admin_events')) {
            Schema::table('admin_events', function (Blueprint $table) {
                if (! Schema::hasColumn('admin_events', 'delivery_areas')) {
                    $table->json('delivery_areas')->nullable();
                }
            });
        }

        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                if (! Schema::hasColumn('client_admin_event_registrations', 'participant_details')) {
                    $table->json('participant_details')->nullable();
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'delivery_details')) {
                    $table->json('delivery_details')->nullable();
                }
                if (! Schema::hasColumn('client_admin_event_registrations', 'delivery_fee_snapshot')) {
                    $table->decimal('delivery_fee_snapshot', 10, 2)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_profiles')) {
            Schema::table('client_profiles', function (Blueprint $table) {
                foreach (['barangay', 'street_address', 'phone'] as $col) {
                    if (Schema::hasColumn('client_profiles', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('admin_events')) {
            Schema::table('admin_events', function (Blueprint $table) {
                if (Schema::hasColumn('admin_events', 'delivery_areas')) {
                    $table->dropColumn('delivery_areas');
                }
            });
        }

        if (Schema::hasTable('client_admin_event_registrations')) {
            Schema::table('client_admin_event_registrations', function (Blueprint $table) {
                foreach (['delivery_fee_snapshot', 'delivery_details', 'participant_details'] as $col) {
                    if (Schema::hasColumn('client_admin_event_registrations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
