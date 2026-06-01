<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->string('trophy_award_mode', 32)->default('all_finishers')->after('trophies');
            $table->unsignedSmallInteger('trophy_top_n')->default(10)->after('trophy_award_mode');
        });
    }

    public function down(): void
    {
        Schema::table('admin_events', function (Blueprint $table) {
            $table->dropColumn(['trophy_award_mode', 'trophy_top_n']);
        });
    }
};
