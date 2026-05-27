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
        Schema::table('client_profiles', function (Blueprint $table) {
            // Experience fields (new onboarding UX)
            $table->enum('experience_running', ['beginner', 'intermediate', 'advanced', 'expert'])->nullable()->after('experience_level');
            $table->enum('experience_gym', ['beginner', 'intermediate', 'advanced', 'expert'])->nullable()->after('experience_running');
            $table->string('experience_others_title')->nullable()->after('experience_gym');
            $table->enum('experience_others', ['beginner', 'intermediate', 'advanced', 'expert'])->nullable()->after('experience_others_title');

            // Auto-calculated metrics
            $table->decimal('bmi', 5, 2)->nullable()->after('current_weight_kg');
            $table->enum('bmi_category', ['underweight', 'normal', 'overweight', 'obese'])->nullable()->after('bmi');
            $table->enum('body_type', ['ectomorph', 'mesomorph', 'endomorph', 'balanced'])->nullable()->after('bmi_category');

            // AI outputs
            $table->json('fitness_plan')->nullable()->after('body_type');
            $table->text('ai_greeting_message')->nullable()->after('fitness_plan');
            $table->json('ai_recommendations')->nullable()->after('ai_greeting_message');

            // Plan metadata
            $table->integer('target_days')->nullable()->after('ai_recommendations');
            $table->decimal('target_weight_change_kg', 6, 2)->nullable()->after('target_days');
            $table->date('plan_start_date')->nullable()->after('target_weight_change_kg');
            $table->date('plan_end_date')->nullable()->after('plan_start_date');

            // AI generation tracking
            $table->timestamp('fitness_plan_generated_at')->nullable()->after('plan_end_date');
            $table->enum('fitness_plan_status', ['pending', 'generating', 'completed', 'failed'])->default('pending')->after('fitness_plan_generated_at');
            $table->text('fitness_plan_error')->nullable()->after('fitness_plan_status');

            $table->index('fitness_plan_status');
            $table->index('body_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_profiles', function (Blueprint $table) {
            $table->dropIndex(['fitness_plan_status']);
            $table->dropIndex(['body_type']);

            $table->dropColumn([
                'experience_running',
                'experience_gym',
                'experience_others_title',
                'experience_others',
                'bmi',
                'bmi_category',
                'body_type',
                'fitness_plan',
                'ai_greeting_message',
                'ai_recommendations',
                'target_days',
                'target_weight_change_kg',
                'plan_start_date',
                'plan_end_date',
                'fitness_plan_generated_at',
                'fitness_plan_status',
                'fitness_plan_error',
            ]);
        });
    }
};


