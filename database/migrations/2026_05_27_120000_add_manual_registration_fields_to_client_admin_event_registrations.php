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
            if (! Schema::hasColumn('client_admin_event_registrations', 'payment_method')) {
                $table->string('payment_method', 32)->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('client_admin_event_registrations', 'registered_by_admin_id')) {
                $table->uuid('registered_by_admin_id')->nullable()->after('payment_method');
                $table->foreign('registered_by_admin_id')
                    ->references('id')
                    ->on('admins')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('client_admin_event_registrations', 'manual_payment_reference')) {
                $table->string('manual_payment_reference', 120)->nullable()->after('registered_by_admin_id');
            }
            if (! Schema::hasColumn('client_admin_event_registrations', 'admin_registration_note')) {
                $table->text('admin_registration_note')->nullable()->after('manual_payment_reference');
            }
            if (! Schema::hasColumn('client_admin_event_registrations', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('admin_registration_note');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_admin_event_registrations', 'registered_by_admin_id')) {
                $table->dropForeign(['registered_by_admin_id']);
            }
            foreach ([
                'paid_at',
                'admin_registration_note',
                'manual_payment_reference',
                'registered_by_admin_id',
                'payment_method',
            ] as $col) {
                if (Schema::hasColumn('client_admin_event_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
