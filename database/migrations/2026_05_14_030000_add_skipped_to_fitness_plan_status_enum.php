<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL ENUM for fitness_plan_status did not include "skipped" (social onboarding, no plan generation).
     */
    public function up(): void
    {
        if (! Schema::hasTable('client_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        DB::statement("ALTER TABLE `client_profiles` MODIFY `fitness_plan_status` ENUM('pending', 'generating', 'completed', 'failed', 'skipped') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        DB::table('client_profiles')->where('fitness_plan_status', 'skipped')->update(['fitness_plan_status' => 'pending']);

        DB::statement("ALTER TABLE `client_profiles` MODIFY `fitness_plan_status` ENUM('pending', 'generating', 'completed', 'failed') NOT NULL DEFAULT 'pending'");
    }
};
