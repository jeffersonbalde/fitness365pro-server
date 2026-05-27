<?php

namespace App\Services\FitnessPlan;

use App\Models\Client;
use App\Services\FitnessPlan\FitnessPlanGeneratorInterface;

/**
 * Template-based fitness plan generator (MVP approach).
 * 
 * Uses hardcoded smart templates based on:
 * - Goal type (lose-weight, build-muscle, improve-cardio, general-fitness)
 * - Experience level (beginner, intermediate, advanced)
 * - Workout days per week
 * - Workout location (home, gym)
 * 
 * Same structure for all goals, only content changes.
 * 
 * Later: AI generator can use same interface, just swap the generator.
 */
class TemplateFitnessPlanGenerator implements FitnessPlanGeneratorInterface
{
    public function getSource(): string
    {
        return 'template';
    }

    public function generateForClient(Client $client, int $durationDays = 60): array
    {
        $profile = $client->profile;
        if (!$profile) {
            throw new \RuntimeException('Client profile not found');
        }

        // Extract user context
        $context = $this->extractContext($client, $profile, $durationDays);
        
        // Get primary goal (first selected goal)
        $primaryGoal = $this->normalizeGoalSlug($context['goals'][0] ?? 'general_fitness');

        // Generate plan using goal-specific template
        $plan = $this->generatePlanFromTemplate($primaryGoal, $context);
        
        // Generate personalized greeting
        $greeting = $this->generateGreeting($context, $primaryGoal);
        
        // Generate recommendations
        $recommendations = $this->generateRecommendations($primaryGoal, $context);

        return [
            'greeting' => [
                'message' => $greeting,
                'recommendations' => $recommendations,
            ],
            'fitness_plan' => $plan,
        ];
    }

    /**
     * Extract all context needed for plan generation.
     */
    private function extractContext(Client $client, $profile, int $durationDays): array
    {
        $goalSlugs = $client->goals()->pluck('slug')->toArray();
        $primaryGoal = $this->normalizeGoalSlug($goalSlugs[0] ?? 'general_fitness');
        
        $currentWeight = $profile->current_weight_kg !== null ? (float) $profile->current_weight_kg : null;
        $targetWeight = $profile->target_weight_kg !== null ? (float) $profile->target_weight_kg : null;
        $weightDelta = null;
        if ($currentWeight !== null && $targetWeight !== null) {
            $weightDelta = round(abs($targetWeight - $currentWeight), 2);
        }

        $workoutPrefs = is_array($profile->workout_preferences) ? $profile->workout_preferences : [];
        $workoutDaysStr = $workoutPrefs['days_per_week'] ?? '4-5';
        $workoutDaysParts = explode('-', (string) $workoutDaysStr);
        $workoutDays = isset($workoutDaysParts[0]) ? (int) $workoutDaysParts[0] : 4;
        if ($workoutDays < 3 || $workoutDays > 6) $workoutDays = 4;

        $nutritionPrefs = is_array($profile->nutrition_preferences) ? $profile->nutrition_preferences : [];
        
        return [
            'first_name' => $profile->first_name ?: 'there',
            'age' => $profile->date_of_birth ? now()->diffInYears($profile->date_of_birth) : null,
            'goals' => $goalSlugs,
            'primary_goal' => $primaryGoal,
            'current_weight' => $currentWeight,
            'target_weight' => $targetWeight,
            'weight_delta' => $weightDelta,
            'bmi' => $profile->bmi ?? null,
            'bmi_category' => $profile->bmi_category ?? 'normal',
            'body_type' => $profile->body_type ?? 'balanced',
            'workout_days' => $workoutDays,
            'workout_location' => $workoutPrefs['location'] ?? 'gym',
            'training_focus' => $workoutPrefs['training_focus'] ?? 'gym',
            'food_preference' => $nutritionPrefs['primary'] ?? 'balanced',
            'experience_running' => $profile->experience_running ?? 'beginner',
            'experience_gym' => $profile->experience_gym ?? 'beginner',
            'duration_days' => $durationDays,
        ];
    }

    /**
     * Normalize goal slug to standard format.
     */
    private function normalizeGoalSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        
        // Map variations to standard slugs
        $map = [
            'lose-weight' => 'lose-weight',
            'lose_weight' => 'lose-weight',
            'build-muscle' => 'build-muscle',
            'gain_muscle' => 'build-muscle',
            'improve-cardio' => 'improve-cardio',
            'running_cardio' => 'improve-cardio',
            'general_fitness' => 'general-fitness',
            'stay-active' => 'general-fitness',
        ];
        
        return $map[$slug] ?? 'general-fitness';
    }

    /**
     * Generate plan from goal-specific template.
     * Same structure, different content based on goal.
     */
    private function generatePlanFromTemplate(string $goal, array $context): array
    {
        $templates = $this->getGoalTemplates();
        $template = $templates[$goal] ?? $templates['general-fitness'];
        
        return [
            'plan_name' => $template['plan_name']($context),
            'overview' => $template['overview']($context),
            'duration_days' => $context['duration_days'],
            'phases' => $template['phases']($context),
            'weekly_schedule' => $template['weekly_schedule']($context),
            'milestones' => $template['milestones']($context),
            'nutrition_guidelines' => $template['nutrition']($context),
        ];
    }

    /**
     * Get goal-specific template functions.
     * Each goal has same structure, different content.
     */
    private function getGoalTemplates(): array
    {
        return [
            'lose-weight' => [
                'plan_name' => fn($ctx) => "{$ctx['duration_days']}-Day Weight Loss Transformation",
                'overview' => fn($ctx) => "This {$ctx['duration_days']}-day weight loss program combines strength training, cardio, and proper nutrition to help you achieve sustainable results. The program progresses through structured phases, gradually increasing intensity while maintaining a calorie deficit.",
                'phases' => fn($ctx) => $this->getWeightLossPhases($ctx),
                'weekly_schedule' => fn($ctx) => $this->getWeeklySchedule($ctx, 'weight-loss'),
                'milestones' => fn($ctx) => $this->getMilestones($ctx, 'weight-loss'),
                'nutrition' => fn($ctx) => $this->getNutritionGuidelines($ctx, 'weight-loss'),
            ],
            'build-muscle' => [
                'plan_name' => fn($ctx) => "{$ctx['duration_days']}-Day Muscle Building Program",
                'overview' => fn($ctx) => "This {$ctx['duration_days']}-day muscle building program focuses on progressive overload and optimal nutrition to maximize muscle growth. The program is structured into phases, each designed to target different aspects of muscle development.",
                'phases' => fn($ctx) => $this->getMuscleGainPhases($ctx),
                'weekly_schedule' => fn($ctx) => $this->getWeeklySchedule($ctx, 'muscle-gain'),
                'milestones' => fn($ctx) => $this->getMilestones($ctx, 'muscle-gain'),
                'nutrition' => fn($ctx) => $this->getNutritionGuidelines($ctx, 'muscle-gain'),
            ],
            'improve-cardio' => [
                'plan_name' => fn($ctx) => "{$ctx['duration_days']}-Day Running & Cardio Challenge",
                'overview' => fn($ctx) => "This {$ctx['duration_days']}-day running and cardio program will improve your cardiovascular fitness, endurance, and running performance. The program includes structured phases, progressing from base building to advanced training.",
                'phases' => fn($ctx) => $this->getCardioPhases($ctx),
                'weekly_schedule' => fn($ctx) => $this->getWeeklySchedule($ctx, 'cardio'),
                'milestones' => fn($ctx) => $this->getMilestones($ctx, 'cardio'),
                'nutrition' => fn($ctx) => $this->getNutritionGuidelines($ctx, 'cardio'),
            ],
            'general-fitness' => [
                'plan_name' => fn($ctx) => "{$ctx['duration_days']}-Day Complete Fitness Plan",
                'overview' => fn($ctx) => "This {$ctx['duration_days']}-day comprehensive fitness program combines strength training, cardio, and proper nutrition to help you achieve overall fitness and health. The program progresses through structured phases, ensuring balanced development.",
                'phases' => fn($ctx) => $this->getGeneralFitnessPhases($ctx),
                'weekly_schedule' => fn($ctx) => $this->getWeeklySchedule($ctx, 'general'),
                'milestones' => fn($ctx) => $this->getMilestones($ctx, 'general'),
                'nutrition' => fn($ctx) => $this->getNutritionGuidelines($ctx, 'general'),
            ],
        ];
    }

    // ========== PHASE TEMPLATES ==========

    private function getWeightLossPhases(array $ctx): array
    {
        $daysPerPhase = (int) ($ctx['duration_days'] / 4);
        return [
            ['name' => 'Foundation Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Establish healthy habits and create calorie deficit', 'workout_focus' => 'Full-body strength training + moderate cardio', 'nutrition_focus' => 'Calorie deficit, high protein, whole foods'],
            ['name' => 'Acceleration Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Increase intensity and boost metabolism', 'workout_focus' => 'HIIT workouts + strength training', 'nutrition_focus' => 'Maintain deficit, optimize macros'],
            ['name' => 'Transformation Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Push through plateaus and maximize results', 'workout_focus' => 'Advanced training + increased cardio', 'nutrition_focus' => 'Refined nutrition, strategic refeeds'],
            ['name' => 'Finalization Phase', 'duration_days' => $ctx['duration_days'] - (3 * $daysPerPhase), 'focus' => 'Fine-tune and prepare for maintenance', 'workout_focus' => 'Peak performance training', 'nutrition_focus' => 'Transition to sustainable eating patterns'],
        ];
    }

    private function getMuscleGainPhases(array $ctx): array
    {
        $daysPerPhase = (int) ($ctx['duration_days'] / 4);
        return [
            ['name' => 'Foundation Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Build strength base and perfect form', 'workout_focus' => 'Compound movements, progressive overload', 'nutrition_focus' => 'Calorie surplus, high protein intake'],
            ['name' => 'Hypertrophy Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Maximize muscle growth', 'workout_focus' => 'Volume training, muscle-specific splits', 'nutrition_focus' => 'Optimized macros, pre/post workout nutrition'],
            ['name' => 'Intensification Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Push limits and break plateaus', 'workout_focus' => 'Advanced techniques, increased volume', 'nutrition_focus' => 'Peak nutrition, recovery optimization'],
            ['name' => 'Peak Phase', 'duration_days' => $ctx['duration_days'] - (3 * $daysPerPhase), 'focus' => 'Maximize gains and solidify progress', 'workout_focus' => 'Peak performance, strength peaks', 'nutrition_focus' => 'Fine-tuned nutrition for maximum growth'],
        ];
    }

    private function getCardioPhases(array $ctx): array
    {
        $daysPerPhase = (int) ($ctx['duration_days'] / 4);
        return [
            ['name' => 'Base Building Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Build aerobic base and running form', 'workout_focus' => 'Easy runs, form drills', 'nutrition_focus' => 'Balanced nutrition, hydration focus'],
            ['name' => 'Progression Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Increase distance and pace', 'workout_focus' => 'Interval training, tempo runs', 'nutrition_focus' => 'Optimized energy intake for running'],
            ['name' => 'Performance Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Peak performance and speed', 'workout_focus' => 'Advanced intervals, race pace', 'nutrition_focus' => 'Performance nutrition, carb timing'],
            ['name' => 'Maintenance Phase', 'duration_days' => $ctx['duration_days'] - (3 * $daysPerPhase), 'focus' => 'Maintain fitness and prevent injury', 'workout_focus' => 'Varied routines, active recovery', 'nutrition_focus' => 'Sustainable nutrition habits'],
        ];
    }

    private function getGeneralFitnessPhases(array $ctx): array
    {
        $daysPerPhase = (int) ($ctx['duration_days'] / 4);
        return [
            ['name' => 'Foundation Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Build consistency and establish routine', 'workout_focus' => 'Balanced training, form focus', 'nutrition_focus' => 'Healthy eating habits'],
            ['name' => 'Progression Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Increase intensity and challenge yourself', 'workout_focus' => 'Progressive overload, varied training', 'nutrition_focus' => 'Optimized nutrition for performance'],
            ['name' => 'Optimization Phase', 'duration_days' => $daysPerPhase, 'focus' => 'Fine-tune and maximize results', 'workout_focus' => 'Advanced training methods', 'nutrition_focus' => 'Precision nutrition'],
            ['name' => 'Mastery Phase', 'duration_days' => $ctx['duration_days'] - (3 * $daysPerPhase), 'focus' => 'Achieve peak fitness and maintain', 'workout_focus' => 'Peak performance training', 'nutrition_focus' => 'Sustainable healthy lifestyle'],
        ];
    }

    // ========== WEEKLY SCHEDULE ==========

    private function getWeeklySchedule(array $ctx, string $goalType): array
    {
        $workoutDays = $ctx['workout_days'];
        $location = $ctx['workout_location'];
        
        if ($goalType === 'cardio') {
            return $this->getCardioSchedule($workoutDays);
        }
        
        if ($goalType === 'weight-loss') {
            return $this->getWeightLossSchedule($workoutDays, $location);
        }
        
        if ($goalType === 'muscle-gain') {
            return $this->getMuscleGainSchedule($workoutDays, $location);
        }
        
        return $this->getGeneralSchedule($workoutDays, $location);
    }

    private function getCardioSchedule(int $days): array
    {
        $schedules = [
            3 => [
                ['day' => 'Monday', 'type' => 'Easy Run', 'duration' => '20-30 min'],
                ['day' => 'Wednesday', 'type' => 'Interval Training', 'duration' => '25-35 min'],
                ['day' => 'Friday', 'type' => 'Tempo Run', 'duration' => '25-35 min'],
            ],
            4 => [
                ['day' => 'Monday', 'type' => 'Easy Run', 'duration' => '20-30 min'],
                ['day' => 'Wednesday', 'type' => 'Interval Training', 'duration' => '25-35 min'],
                ['day' => 'Friday', 'type' => 'Tempo Run', 'duration' => '25-35 min'],
                ['day' => 'Sunday', 'type' => 'Long Run', 'duration' => '40-60 min'],
            ],
            5 => [
                ['day' => 'Monday', 'type' => 'Easy Run', 'duration' => '20-30 min'],
                ['day' => 'Tuesday', 'type' => 'Recovery Run', 'duration' => '15-20 min'],
                ['day' => 'Wednesday', 'type' => 'Interval Training', 'duration' => '25-35 min'],
                ['day' => 'Friday', 'type' => 'Tempo Run', 'duration' => '25-35 min'],
                ['day' => 'Sunday', 'type' => 'Long Run', 'duration' => '40-60 min'],
            ],
        ];
        
        return $schedules[$days] ?? $schedules[4];
    }

    private function getWeightLossSchedule(int $days, string $location): array
    {
        $schedules = [
            3 => [
                ['day' => 'Monday', 'type' => 'Full Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Wednesday', 'type' => 'Cardio + Core', 'duration' => '30-45 min'],
                ['day' => 'Friday', 'type' => 'Full Body Strength', 'duration' => '45-60 min'],
            ],
            4 => [
                ['day' => 'Monday', 'type' => 'Upper Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Tuesday', 'type' => 'Cardio', 'duration' => '30-45 min'],
                ['day' => 'Thursday', 'type' => 'Lower Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Saturday', 'type' => 'Full Body + Cardio', 'duration' => '50-70 min'],
            ],
            5 => [
                ['day' => 'Monday', 'type' => 'Upper Body', 'duration' => '45-60 min'],
                ['day' => 'Tuesday', 'type' => 'Lower Body', 'duration' => '45-60 min'],
                ['day' => 'Wednesday', 'type' => 'Cardio', 'duration' => '30-45 min'],
                ['day' => 'Thursday', 'type' => 'Upper Body', 'duration' => '45-60 min'],
                ['day' => 'Friday', 'type' => 'Lower Body', 'duration' => '45-60 min'],
            ],
        ];
        
        return $schedules[$days] ?? $schedules[4];
    }

    private function getMuscleGainSchedule(int $days, string $location): array
    {
        $schedules = [
            3 => [
                ['day' => 'Monday', 'type' => 'Push (Chest, Shoulders, Triceps)', 'duration' => '60-75 min'],
                ['day' => 'Wednesday', 'type' => 'Pull (Back, Biceps)', 'duration' => '60-75 min'],
                ['day' => 'Friday', 'type' => 'Legs', 'duration' => '60-75 min'],
            ],
            4 => [
                ['day' => 'Monday', 'type' => 'Push (Chest, Shoulders, Triceps)', 'duration' => '60-75 min'],
                ['day' => 'Tuesday', 'type' => 'Pull (Back, Biceps)', 'duration' => '60-75 min'],
                ['day' => 'Thursday', 'type' => 'Legs', 'duration' => '60-75 min'],
                ['day' => 'Saturday', 'type' => 'Full Body', 'duration' => '60-75 min'],
            ],
            5 => [
                ['day' => 'Monday', 'type' => 'Push', 'duration' => '60-75 min'],
                ['day' => 'Tuesday', 'type' => 'Pull', 'duration' => '60-75 min'],
                ['day' => 'Wednesday', 'type' => 'Legs', 'duration' => '60-75 min'],
                ['day' => 'Thursday', 'type' => 'Push', 'duration' => '60-75 min'],
                ['day' => 'Friday', 'type' => 'Pull', 'duration' => '60-75 min'],
            ],
        ];
        
        return $schedules[$days] ?? $schedules[4];
    }

    private function getGeneralSchedule(int $days, string $location): array
    {
        return $this->getWeightLossSchedule($days, $location); // Similar structure
    }

    // ========== MILESTONES ==========

    private function getMilestones(array $ctx, string $goalType): array
    {
        $duration = $ctx['duration_days'];
        
        $milestones = [
            ['day' => 7, 'milestone' => 'First week complete - establish routine'],
            ['day' => 14, 'milestone' => 'Two weeks in - notice initial changes'],
            ['day' => 30, 'milestone' => 'One month milestone - significant progress'],
            ['day' => 45, 'milestone' => 'Halfway point - stay motivated'],
            ['day' => $duration, 'milestone' => 'Program complete - celebrate your transformation'],
        ];
        
        if ($goalType === 'muscle-gain') {
            $milestones[0]['milestone'] = 'First week - form and technique established';
            $milestones[1]['milestone'] = 'Two weeks - strength gains begin';
            $milestones[2]['milestone'] = 'One month - visible muscle development';
            $milestones[3]['milestone'] = 'Halfway point - significant strength increase';
            $milestones[4]['milestone'] = 'Program complete - impressive muscle gains';
        } elseif ($goalType === 'cardio') {
            $milestones[0]['milestone'] = 'First week - running form established';
            $milestones[1]['milestone'] = 'Two weeks - improved endurance';
            $milestones[2]['milestone'] = 'One month - faster pace and longer distance';
            $milestones[3]['milestone'] = 'Halfway point - peak performance building';
            $milestones[4]['milestone'] = 'Program complete - cardio goals achieved';
        }
        
        return $milestones;
    }

    // ========== NUTRITION ==========

    private function getNutritionGuidelines(array $ctx, string $goalType): array
    {
        $foodPref = $ctx['food_preference'];
        
        if ($goalType === 'weight-loss') {
            $calories = '1,500-1,800';
            $macros = ['protein' => '120-150g (30-35%)', 'carbs' => '150-200g (35-40%)', 'fats' => '40-60g (25-30%)'];
            $guidelines = [
                'Focus on a calorie deficit of 300-500 calories per day',
                'Prioritize whole foods: lean proteins, complex carbs, healthy fats',
                'Eat protein with every meal to support muscle maintenance',
                'Practice portion control and mindful eating',
            ];
        } elseif ($goalType === 'muscle-gain') {
            $calories = '2,200-2,600';
            $macros = ['protein' => '150-200g (30-35%)', 'carbs' => '250-300g (40-45%)', 'fats' => '60-80g (25-30%)'];
            $guidelines = [
                'Consume 1.6-2.2g of protein per kg of body weight daily',
                'Post-workout: consume protein and carbs within 30 minutes',
                'Ensure adequate calorie surplus to support muscle growth',
                'Time meals around workouts for optimal recovery',
            ];
        } elseif ($goalType === 'cardio') {
            $calories = '1,800-2,200';
            $macros = ['protein' => '120-150g (25-30%)', 'carbs' => '200-250g (45-50%)', 'fats' => '50-70g (25-30%)'];
            $guidelines = [
                'Prioritize carbohydrates for energy during runs',
                'Stay hydrated: aim for 2-3 liters of water daily',
                'Eat 1-2 hours before runs for optimal performance',
                'Include 5-7 servings of fruits and vegetables daily',
            ];
        } else {
            $calories = '1,800-2,200';
            $macros = ['protein' => '120-150g (25-30%)', 'carbs' => '200-250g (40-45%)', 'fats' => '50-70g (25-30%)'];
            $guidelines = [
                'Prioritize whole foods: lean proteins, complex carbs, healthy fats',
                'Eat protein with every meal to support muscle maintenance',
                'Stay hydrated: aim for 2-3 liters of water daily',
                'Include 5-7 servings of fruits and vegetables daily',
            ];
        }
        
        // Add food preference adjustments
        if ($foodPref === 'vegetarian') {
            $guidelines[] = 'Focus on plant-based proteins: legumes, tofu, tempeh, quinoa';
        } elseif ($foodPref === 'vegan') {
            $guidelines[] = 'Ensure adequate B12 and omega-3 through supplements or fortified foods';
        } elseif ($foodPref === 'keto') {
            $guidelines[] = 'Maintain ketosis with <50g carbs per day';
        }
        
        return [
            'daily_calories' => $calories,
            'macros' => $macros,
            'guidelines' => array_slice($guidelines, 0, 4), // Max 4
        ];
    }

    // ========== GREETING & RECOMMENDATIONS ==========

    private function generateGreeting(array $ctx, string $goal): string
    {
        $name = $ctx['first_name'];
        $duration = $ctx['duration_days'];
        $weightDelta = $ctx['weight_delta'];
        
        if ($goal === 'lose-weight') {
            $text = $weightDelta ? "lose {$weightDelta}kg" : "achieve your weight loss goals";
            return "Hi {$name}, based on your profile, you can {$text} in {$duration} days. Let's start Day 1 today!";
        } elseif ($goal === 'build-muscle') {
            $text = $weightDelta ? "gain {$weightDelta}kg" : "build muscle";
            return "Hi {$name}, based on your profile, you can {$text} in {$duration} days. Let's start Day 1 today!";
        } elseif ($goal === 'improve-cardio') {
            return "Hi {$name}, ready to improve your cardiovascular fitness? Let's start your {$duration}-day running and cardio program today!";
        } else {
            return "Hi {$name}, welcome to your personalized {$duration}-day fitness journey! Let's start Day 1 today!";
        }
    }

    private function generateRecommendations(string $goal, array $ctx): array
    {
        $workoutDays = $ctx['workout_days'];
        $bmiCategory = $ctx['bmi_category'];
        $bodyType = $ctx['body_type'];
        
        $recs = [];
        
        if ($goal === 'lose-weight') {
            $recs[] = "Focus on a calorie deficit of 300-500 calories per day for sustainable weight loss";
            $recs[] = "Include strength training {$workoutDays} times per week to preserve muscle mass";
            $recs[] = "Aim for 7-9 hours of quality sleep each night to support recovery and metabolism";
        } elseif ($goal === 'build-muscle') {
            $recs[] = "Consume 1.6-2.2g of protein per kg of body weight daily";
            $recs[] = "Progressive overload: gradually increase weight or reps each week";
            $recs[] = "Allow 48 hours rest between training the same muscle groups";
        } elseif ($goal === 'improve-cardio') {
            $recs[] = "Start with 3-4 runs per week, mixing easy runs with interval training";
            $recs[] = "Follow the 10% rule: don't increase weekly mileage by more than 10%";
            $recs[] = "Include cross-training and strength work to prevent injuries";
        } else {
            $recs[] = "Maintain consistency: aim for {$workoutDays} workout sessions per week";
            $recs[] = "Balance cardio and strength training for overall fitness";
            $recs[] = "Listen to your body and adjust intensity based on how you feel";
        }
        
        if ($bmiCategory === 'overweight' || $bmiCategory === 'obese') {
            $recs[] = "Prioritize walking or low-impact cardio to build endurance";
        }
        
        if ($bodyType === 'ectomorph') {
            $recs[] = "Consider slightly higher calorie intake to support muscle gain";
        } elseif ($bodyType === 'endomorph') {
            $recs[] = "Focus on lean proteins and complex carbohydrates to manage energy";
        }
        
        return array_slice($recs, 0, 3); // Max 3
    }
}

