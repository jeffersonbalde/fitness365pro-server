<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('blocker_client_id');
            $table->uuid('blocked_client_id');
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->foreign('blocker_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('blocked_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->unique(['blocker_client_id', 'blocked_client_id']);
            $table->index('blocker_client_id');
            $table->index('blocked_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_blocks');
    }
};

