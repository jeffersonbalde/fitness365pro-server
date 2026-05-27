<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recipient_client_id');
            $table->uuid('actor_client_id')->nullable();
            $table->string('type', 64);
            $table->string('title', 160);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('recipient_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('actor_client_id')->references('id')->on('clients')->nullOnDelete();

            $table->index(['recipient_client_id', 'read_at']);
            $table->index(['recipient_client_id', 'created_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notifications');
    }
};
