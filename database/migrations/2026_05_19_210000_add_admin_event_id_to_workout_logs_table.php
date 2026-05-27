<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workout_logs') || ! Schema::hasTable('admin_events')) {
            return;
        }

        Schema::table('workout_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('workout_logs', 'admin_event_id')) {
                $table->uuid('admin_event_id')->nullable()->after('client_id');
                $table->foreign('admin_event_id')
                    ->references('id')
                    ->on('admin_events')
                    ->nullOnDelete();
                $table->index(['client_id', 'admin_event_id']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workout_logs')) {
            return;
        }

        Schema::table('workout_logs', function (Blueprint $table) {
            if (Schema::hasColumn('workout_logs', 'admin_event_id')) {
                $table->dropForeign(['admin_event_id']);
                $table->dropColumn('admin_event_id');
            }
        });
    }
};
