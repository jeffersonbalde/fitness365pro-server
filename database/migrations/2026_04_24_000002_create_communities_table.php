<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_client_id');
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->enum('primary_niche', ['running', 'gym', 'hybrid']);
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->enum('visibility', ['public', 'private'])->default('public');
            $table->string('cover_image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('owner_client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->index('owner_client_id');
            $table->index('primary_niche');
            $table->index(['visibility', 'is_active']);
            $table->index(['country', 'province', 'city']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};

