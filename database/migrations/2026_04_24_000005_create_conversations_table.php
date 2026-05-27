<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['community_channel', 'direct']);
            $table->uuid('community_id')->nullable();
            $table->string('channel_name', 80)->nullable();
            $table->uuid('created_by_client_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('created_by_client_id')->references('id')->on('clients')->onDelete('set null');
            $table->index('type');
            $table->index('community_id');
            $table->index('created_by_client_id');
            $table->index(['type', 'is_active']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};

