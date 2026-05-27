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
        Schema::create('workout_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            
            // Workout details
            $table->date('workout_date');
            $table->string('workout_type', 100); // e.g., "Upper Body Strength", "Cardio", "Easy Run"
            $table->integer('duration_minutes')->nullable();
            
            // For running/cardio
            $table->decimal('distance_km', 8, 2)->nullable();
            $table->integer('duration_seconds')->nullable(); // For precise timing
            $table->decimal('pace_min_per_km', 5, 2)->nullable(); // Calculated
            
            // Status
            $table->enum('status', ['completed', 'skipped', 'partial'])->default('completed');
            
            // Notes
            $table->text('notes')->nullable();
            
            // Plan reference (optional - link to plan day)
            $table->integer('plan_day')->nullable(); // Day in the 60-day plan
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['client_id', 'workout_date']);
            $table->index('workout_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_logs');
    }
};
