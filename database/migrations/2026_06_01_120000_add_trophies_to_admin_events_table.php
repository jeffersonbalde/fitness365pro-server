<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->json('trophies')->nullable()->after('badges');
        });
    }

    public function down(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->dropColumn('trophies');
        });
    }
};
