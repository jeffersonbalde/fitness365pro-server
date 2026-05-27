<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_goals', function (Blueprint $table) {
            $table->uuid('client_id');
            $table->uuid('goal_id');
            $table->boolean('is_primary')->default(false);
            $table->json('target_metrics')->nullable(); // Custom target values
            $table->timestamp('started_at')->nullable();
            $table->timestamp('target_date')->nullable();
            $table->timestamps();

            $table->primary(['client_id', 'goal_id']); // Composite primary key
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('goal_id')->references('id')->on('goals')->onDelete('cascade');
            $table->index('client_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_goals');
    }
};
