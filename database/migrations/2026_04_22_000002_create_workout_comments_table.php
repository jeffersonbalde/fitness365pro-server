<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workout_log_id');
            $table->uuid('client_id');
            $table->uuid('parent_comment_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('workout_log_id')->references('id')->on('workout_logs')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('parent_comment_id')->references('id')->on('workout_comments')->onDelete('cascade');

            $table->index(['workout_log_id', 'created_at']);
            $table->index(['parent_comment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_comments');
    }
};
