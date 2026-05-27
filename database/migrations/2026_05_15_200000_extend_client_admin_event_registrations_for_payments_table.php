<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_admin_event_registrations', 'registration_status')) {
                $table->string('registration_status', 32)->default('confirmed')->after('admin_event_id');
            }
            if (!Schema::hasColumn('client_admin_event_registrations', 'payment_status')) {
                $table->string('payment_status', 32)->default('free')->after('registration_status');
            }
            if (!Schema::hasColumn('client_admin_event_registrations', 'amount_snapshot')) {
                $table->decimal('amount_snapshot', 10, 2)->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('client_admin_event_registrations', 'paymaya_checkout_id')) {
                $table->string('paymaya_checkout_id', 80)->nullable()->after('amount_snapshot');
            }
            if (!Schema::hasColumn('client_admin_event_registrations', 'paymaya_rrn')) {
                $table->string('paymaya_rrn', 64)->nullable()->index()->after('paymaya_checkout_id');
            }
            if (!Schema::hasColumn('client_admin_event_registrations', 'paymaya_payment_status_snapshot')) {
                $table->string('paymaya_payment_status_snapshot', 64)->nullable()->after('paymaya_rrn');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('client_admin_event_registrations')) {
            return;
        }

        Schema::table('client_admin_event_registrations', function (Blueprint $table) {
            foreach ([
                'paymaya_payment_status_snapshot',
                'paymaya_rrn',
                'paymaya_checkout_id',
                'amount_snapshot',
                'payment_status',
                'registration_status',
            ] as $col) {
                if (Schema::hasColumn('client_admin_event_registrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
