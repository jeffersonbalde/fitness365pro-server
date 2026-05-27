<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        DB::table('admin_events')->where('status', 'archived')->update(['status' => 'draft']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE admin_events MODIFY COLUMN status ENUM('draft', 'published') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_events')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE admin_events MODIFY COLUMN status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'draft'");
        }
    }
};
