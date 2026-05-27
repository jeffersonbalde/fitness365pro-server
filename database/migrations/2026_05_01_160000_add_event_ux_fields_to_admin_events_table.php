<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->enum('location_type', ['online', 'global', 'onsite'])->default('online')->after('category');
            $table->string('venue', 180)->nullable()->after('location_type');
            $table->enum('fee_type', ['free', 'paid'])->default('free')->after('fee');

            $table->index('location_type');
            $table->index('fee_type');
        });
    }

    public function down(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->dropIndex(['location_type']);
            $table->dropIndex(['fee_type']);
            $table->dropColumn(['location_type', 'venue', 'fee_type']);
        });
    }
};

