<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reporter_client_id');
            $table->uuid('message_id');
            $table->string('reason', 60);
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'reviewed', 'resolved'])->default('open');
            $table->timestamps();

            $table->foreign('reporter_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('messages')->onDelete('cascade');
            $table->index('reporter_client_id');
            $table->index('message_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
    }
};

