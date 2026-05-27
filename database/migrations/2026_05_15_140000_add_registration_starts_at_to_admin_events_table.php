<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        Schema::table('admin_events', function (Blueprint $table) {
            if (!Schema::hasColumn('admin_events', 'registration_starts_at')) {
                $table->dateTime('registration_starts_at')->nullable()->after('registration_deadline');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        Schema::table('admin_events', function (Blueprint $table) {
            if (Schema::hasColumn('admin_events', 'registration_starts_at')) {
                $table->dropColumn('registration_starts_at');
            }
        });
    }
};
