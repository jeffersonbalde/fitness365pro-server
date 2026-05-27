<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->json('badges')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->dropColumn('badges');
        });
    }
};
