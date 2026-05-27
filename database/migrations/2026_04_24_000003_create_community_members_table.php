<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('community_id');
            $table->uuid('client_id');
            $table->enum('role', ['owner', 'admin', 'member'])->default('member');
            $table->enum('status', ['active', 'requested', 'blocked'])->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->foreign('community_id')->references('id')->on('communities')->onDelete('cascade');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->unique(['community_id', 'client_id']);
            $table->index('community_id');
            $table->index('client_id');
            $table->index(['community_id', 'role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_members');
    }
};

