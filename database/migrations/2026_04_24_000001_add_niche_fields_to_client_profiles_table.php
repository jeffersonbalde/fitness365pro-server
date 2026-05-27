<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            // Default existing users to hybrid so this change is non-breaking.
            $table->enum('primary_niche', ['running', 'gym', 'hybrid'])
                ->default('hybrid')
                ->after('experience_others');
            $table->json('secondary_niches')->nullable()->after('primary_niche');

            $table->index('primary_niche');
        });
    }

    public function down(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->dropIndex(['primary_niche']);
            $table->dropColumn(['primary_niche', 'secondary_niches']);
        });
    }
};

