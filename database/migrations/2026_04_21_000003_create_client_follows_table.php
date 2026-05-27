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
        Schema::create('client_follows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('follower_client_id');
            $table->uuid('followed_client_id');
            $table->timestamps();

            $table->foreign('follower_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('followed_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->unique(['follower_client_id', 'followed_client_id']);
            $table->index('follower_client_id');
            $table->index('followed_client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_follows');
    }
};

