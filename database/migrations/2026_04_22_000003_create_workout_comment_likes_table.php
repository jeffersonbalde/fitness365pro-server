<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_comment_likes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workout_comment_id');
            $table->uuid('client_id');
            $table->timestamps();

            $table->foreign('workout_comment_id')->references('id')->on('workout_comments')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');

            $table->unique(['workout_comment_id', 'client_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_comment_likes');
    }
};
