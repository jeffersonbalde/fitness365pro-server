<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('community_id');
            $table->uuid('client_id');
            $table->text('body');
            $table->json('media_urls')->nullable();
            $table->enum('status', ['published', 'hidden', 'removed'])->default('published');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->index('community_id');
            $table->index('client_id');
            $table->index(['community_id', 'status', 'created_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_posts');
    }
};

