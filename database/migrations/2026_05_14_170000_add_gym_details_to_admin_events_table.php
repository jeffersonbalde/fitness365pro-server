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
            if (!Schema::hasColumn('admin_events', 'gym_details')) {
                $table->json('gym_details')->nullable()->after('running_details');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }
        Schema::table('admin_events', function (Blueprint $table) {
            if (Schema::hasColumn('admin_events', 'gym_details')) {
                $table->dropColumn('gym_details');
            }
        });
    }
};
