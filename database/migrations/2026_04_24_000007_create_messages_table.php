<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->uuid('sender_client_id');
            $table->enum('message_type', ['text', 'system'])->default('text');
            $table->text('body')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            $table->foreign('sender_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->index('conversation_id');
            $table->index('sender_client_id');
            $table->index(['conversation_id', 'created_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

