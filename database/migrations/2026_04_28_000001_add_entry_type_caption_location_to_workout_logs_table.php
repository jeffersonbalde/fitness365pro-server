<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workout_logs', function (Blueprint $table) {
            $table->enum('entry_type', ['workout', 'post'])->default('workout')->after('client_id');
            $table->text('caption')->nullable()->after('notes');
            $table->string('location', 255)->nullable()->after('caption');

            $table->index('entry_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workout_logs', function (Blueprint $table) {
            $table->dropIndex(['entry_type']);
            $table->dropColumn(['entry_type', 'caption', 'location']);
        });
    }
};
