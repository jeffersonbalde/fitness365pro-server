<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workout_logs')) {
            return;
        }

        Schema::table('workout_logs', function (Blueprint $table) {
            $table->index(['status', 'workout_date', 'created_at'], 'workout_logs_feed_chronological_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workout_logs')) {
            return;
        }

        Schema::table('workout_logs', function (Blueprint $table) {
            $table->dropIndex('workout_logs_feed_chronological_idx');
        });
    }
};
