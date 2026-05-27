<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dark mode is the product default for new profiles (aligned with client ThemeContext).
     */
    public function up(): void
    {
        if (! Schema::hasTable('client_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `client_profiles` MODIFY `theme_mode` ENUM('light', 'dark') NOT NULL DEFAULT 'dark'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_profiles')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE `client_profiles` MODIFY `theme_mode` ENUM('light', 'dark') NOT NULL DEFAULT 'light'");
        }
    }
};
