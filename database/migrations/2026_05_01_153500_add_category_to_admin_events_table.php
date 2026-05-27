<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->string('category', 60)->nullable()->after('location');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};

