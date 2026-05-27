<?php

namespace App\Services\AI;

use App\Models\Client;

class MockFitnessPlanService
{
    /**
     * Generate greeting + 60-day fitness plan for a client using mock data (no AI).
     *
     * @return array{greeting: array, fitness_plan: array}
     */
    public function generateForClient(Client $client, int $durationDays = 60): array
    {
        $profile = $client->profile;
        if (!$profile) {
            throw new \RuntimeException('Client profile not found');
        }

        $firstName = $profile->first_name ?: 'there';
        $age = $profile->date_of_birth ? now()->diffInYears($profile->date_of_birth) : null;
        $goalSlugs = $client->goals()->pluck('slug')->toArray();
        
        $currentWeight = $profile->current_weight_kg !== null ? (float) $profile->current_weight_kg : null;
        $targetWeight = $profile->target_weight_kg !== null ? (float) $profile->target_weight_kg : null;
        $weightDelta = null;
        if ($currentWeight !== null && $targetWeight !== null) {
            $weightDelta = round(abs($targetWeight - $currentWeight), 2);
        }

        $bmi = $profile->bmi ?? null;
        $bmiCategory = $profile->bmi_category ?? 'normal';
        $bodyType = $profile->body_type ?? 'balanced';
        
        // Handle workout preferences - days_per_week is stored as "3-4", "4-5", "5-6"
        $workoutPrefs = is_array($profile->workout_preferences) ? $profile->workout_preferences : [];
        $workoutDaysStr = $workoutPrefs['days_per_week'] ?? '4-5';
        // Extract the first number from "3-4" -> 3, "4-5" -> 4, "5-6" -> 5
        $workoutDaysParts = explode('-', (string) $workoutDaysStr);
        $workoutDays = isset($workoutDaysParts[0]) ? (int) $workoutDaysParts[0] : 4;
        if ($workoutDays < 3 || $workoutDays > 6) $workoutDays = 4; // Default to 4 if invalid
        
        $workoutLocation = $workoutPrefs['location'] ?? 'gym';
        $trainingFocus = $workoutPrefs['training_focus'] ?? 'gym';
        
        // Handle nutrition preferences
        $nutritionPrefs = is_array($profile->nutrition_preferences) ? $profile->nutrition_preferences : [];
        $foodPreference = $nutritionPrefs['primary'] ?? 'balanced';
        
        $experienceRunning = $profile->experience_running ?? 'beginner';
        $experienceGym = $profile->experience_gym ?? 'beginner';

        // Generate greeting message
        $greeting = $this->generateGreeting($firstName, $weightDelta, $durationDays, $goalSlugs, $bmiCategory);
        
        // Generate recommendations
        $recommendations = $this->generateRecommendations($goalSlugs, $bmiCategory, $bodyType, $workoutDays);
        
        // Generate fitness plan
        $fitnessPlan = $this->generateFitnessPlan([
            'goals' => $goalSlugs,
            'bmi_category' => $bmiCategory,
            'body_type' => $bodyType,
            'workout_days' => $workoutDays,
            'workout_location' => $workoutLocation,
            'training_focus' => $trainingFocus,
            'food_preference' => $foodPreference,
            'experience_running' => $experienceRunning,
            'experience_gym' => $experienceGym,
            'duration_days' => $durationDays,
            'weight_delta' => $weightDelta,
            'age' => $age,
        ]);

        return [
            'greeting' => [
                'message' => $greeting,
                'recommendations' => $recommendations,
            ],
            'fitness_plan' => $fitnessPlan,
        ];
    }

    private function generateGreeting(string $firstName, ?float $weightDelta, int $durationDays, array $goals, string $bmiCategory): string
    {
        $primaryGoal = $goals[0] ?? 'general_fitness';
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $weightText = $weightDelta ? "lose {$weightDelta}kg" : "achieve your weight loss goals";
            return "Hi {$firstName}, based on your profile, you can {$weightText} in {$durationDays} days. Let's start Day 1 today!";
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $weightText = $weightDelta ? "gain {$weightDelta}kg" : "build muscle";
            return "Hi {$firstName}, based on your profile, you can {$weightText} in {$durationDays} days. Let's start Day 1 today!";
        } elseif ($primaryGoal === 'running_cardio' || $primaryGoal === 'improve-cardio') {
            return "Hi {$firstName}, ready to improve your cardiovascular fitness? Let's start your {$durationDays}-day running and cardio program today!";
        } else {
            return "Hi {$firstName}, welcome to your personalized {$durationDays}-day fitness journey! Let's start Day 1 today!";
        }
    }

    private function generateRecommendations(array $goals, string $bmiCategory, string $bodyType, int $workoutDays): array
    {
        $recommendations = [];
        
        $primaryGoal = $goals[0] ?? 'general_fitness';
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $recommendations[] = "Focus on a calorie deficit of 300-500 calories per day for sustainable weight loss";
            $recommendations[] = "Include strength training {$workoutDays} times per week to preserve muscle mass";
            $recommendations[] = "Aim for 7-9 hours of quality sleep each night to support recovery and metabolism";
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $recommendations[] = "Consume 1.6-2.2g of protein per kg of body weight daily";
            $recommendations[] = "Progressive overload: gradually increase weight or reps each week";
            $recommendations[] = "Allow 48 hours rest between training the same muscle groups";
        } elseif ($primaryGoal === 'running_cardio' || $primaryGoal === 'improve-cardio') {
            $recommendations[] = "Start with 3-4 runs per week, mixing easy runs with interval training";
            $recommendations[] = "Follow the 10% rule: don't increase weekly mileage by more than 10%";
            $recommendations[] = "Include cross-training and strength work to prevent injuries";
        } else {
            $recommendations[] = "Maintain consistency: aim for {$workoutDays} workout sessions per week";
            $recommendations[] = "Balance cardio and strength training for overall fitness";
            $recommendations[] = "Listen to your body and adjust intensity based on how you feel";
        }
        
        return $recommendations;
    }

    private function generateFitnessPlan(array $ctx): array
    {
        $goals = $ctx['goals'] ?? [];
        $primaryGoal = $goals[0] ?? 'general_fitness';
        $durationDays = $ctx['duration_days'] ?? 60;
        $workoutDays = $ctx['workout_days'] ?? 4;
        $bodyType = $ctx['body_type'] ?? 'balanced';
        
        // Determine plan name based on primary goal
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $planName = '60-Day Weight Loss Transformation';
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $planName = '60-Day Muscle Building Program';
        } elseif ($primaryGoal === 'running_cardio' || $primaryGoal === 'improve-cardio') {
            $planName = '60-Day Running & Cardio Challenge';
        } else {
            $planName = '60-Day Complete Fitness Plan';
        }
        
        // Generate phases
        $phases = $this->generatePhases($primaryGoal, $durationDays, $bodyType);
        
        // Generate weekly schedule
        $weeklySchedule = $this->generateWeeklySchedule($workoutDays, $primaryGoal, $ctx['workout_location']);
        
        // Generate milestones
        $milestones = $this->generateMilestones($durationDays, $primaryGoal);
        
        // Generate nutrition guidelines
        $nutritionGuidelines = $this->generateNutritionGuidelines($primaryGoal, $ctx['food_preference'], $ctx['weight_delta']);
        
        // Generate overview
        $overview = $this->generateOverview($primaryGoal, $durationDays, $phases);
        
        return [
            'plan_name' => $planName,
            'overview' => $overview,
            'duration_days' => $durationDays,
            'phases' => $phases,
            'weekly_schedule' => $weeklySchedule,
            'milestones' => $milestones,
            'nutrition_guidelines' => $nutritionGuidelines,
        ];
    }

    private function generatePhases(string $primaryGoal, int $durationDays, string $bodyType): array
    {
        $daysPerPhase = (int) ($durationDays / 4);
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            return [
                [
                    'name' => 'Foundation Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Establish healthy habits and create calorie deficit',
                    'workout_focus' => 'Full-body strength training + moderate cardio',
                    'nutrition_focus' => 'Calorie deficit, high protein, whole foods',
                ],
                [
                    'name' => 'Acceleration Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Increase intensity and boost metabolism',
                    'workout_focus' => 'HIIT workouts + strength training',
                    'nutrition_focus' => 'Maintain deficit, optimize macros',
                ],
                [
                    'name' => 'Transformation Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Push through plateaus and maximize results',
                    'workout_focus' => 'Advanced training + increased cardio',
                    'nutrition_focus' => 'Refined nutrition, strategic refeeds',
                ],
                [
                    'name' => 'Finalization Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Fine-tune and prepare for maintenance',
                    'workout_focus' => 'Peak performance training',
                    'nutrition_focus' => 'Transition to sustainable eating patterns',
                ],
            ];
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            return [
                [
                    'name' => 'Foundation Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Build strength base and perfect form',
                    'workout_focus' => 'Compound movements, progressive overload',
                    'nutrition_focus' => 'Calorie surplus, high protein intake',
                ],
                [
                    'name' => 'Hypertrophy Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Maximize muscle growth',
                    'workout_focus' => 'Volume training, muscle-specific splits',
                    'nutrition_focus' => 'Optimized macros, pre/post workout nutrition',
                ],
                [
                    'name' => 'Intensification Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Push limits and break plateaus',
                    'workout_focus' => 'Advanced techniques, increased volume',
                    'nutrition_focus' => 'Peak nutrition, recovery optimization',
                ],
                [
                    'name' => 'Peak Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Maximize gains and solidify progress',
                    'workout_focus' => 'Peak performance, strength peaks',
                    'nutrition_focus' => 'Fine-tuned nutrition for maximum growth',
                ],
            ];
        } else {
            return [
                [
                    'name' => 'Foundation Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Build consistency and establish routine',
                    'workout_focus' => 'Balanced training, form focus',
                    'nutrition_focus' => 'Healthy eating habits',
                ],
                [
                    'name' => 'Progression Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Increase intensity and challenge yourself',
                    'workout_focus' => 'Progressive overload, varied training',
                    'nutrition_focus' => 'Optimized nutrition for performance',
                ],
                [
                    'name' => 'Optimization Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Fine-tune and maximize results',
                    'workout_focus' => 'Advanced training methods',
                    'nutrition_focus' => 'Precision nutrition',
                ],
                [
                    'name' => 'Mastery Phase',
                    'duration_days' => $daysPerPhase,
                    'focus' => 'Achieve peak fitness and maintain',
                    'workout_focus' => 'Peak performance training',
                    'nutrition_focus' => 'Sustainable healthy lifestyle',
                ],
            ];
        }
    }

    private function generateWeeklySchedule(int $workoutDays, string $primaryGoal, string $location): array
    {
        $schedule = [];
        
        if ($workoutDays === 3) {
            $schedule = [
                ['day' => 'Monday', 'type' => 'Full Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Wednesday', 'type' => 'Cardio + Core', 'duration' => '30-45 min'],
                ['day' => 'Friday', 'type' => 'Full Body Strength', 'duration' => '45-60 min'],
            ];
        } elseif ($workoutDays === 4) {
            $schedule = [
                ['day' => 'Monday', 'type' => 'Upper Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Tuesday', 'type' => 'Cardio', 'duration' => '30-45 min'],
                ['day' => 'Thursday', 'type' => 'Lower Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Saturday', 'type' => 'Full Body + Cardio', 'duration' => '50-70 min'],
            ];
        } elseif ($workoutDays === 5) {
            $schedule = [
                ['day' => 'Monday', 'type' => 'Upper Body', 'duration' => '45-60 min'],
                ['day' => 'Tuesday', 'type' => 'Lower Body', 'duration' => '45-60 min'],
                ['day' => 'Wednesday', 'type' => 'Cardio', 'duration' => '30-45 min'],
                ['day' => 'Thursday', 'type' => 'Upper Body', 'duration' => '45-60 min'],
                ['day' => 'Friday', 'type' => 'Lower Body', 'duration' => '45-60 min'],
            ];
        } else {
            // Default 4-day schedule
            $schedule = [
                ['day' => 'Monday', 'type' => 'Upper Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Tuesday', 'type' => 'Cardio', 'duration' => '30-45 min'],
                ['day' => 'Thursday', 'type' => 'Lower Body Strength', 'duration' => '45-60 min'],
                ['day' => 'Saturday', 'type' => 'Full Body + Cardio', 'duration' => '50-70 min'],
            ];
        }
        
        // Adjust for running/cardio goals
        if ($primaryGoal === 'running_cardio' || $primaryGoal === 'improve-cardio') {
            $schedule = [
                ['day' => 'Monday', 'type' => 'Easy Run', 'duration' => '20-30 min'],
                ['day' => 'Wednesday', 'type' => 'Interval Training', 'duration' => '25-35 min'],
                ['day' => 'Friday', 'type' => 'Tempo Run', 'duration' => '25-35 min'],
                ['day' => 'Sunday', 'type' => 'Long Run', 'duration' => '40-60 min'],
            ];
        }
        
        return $schedule;
    }

    private function generateMilestones(int $durationDays, string $primaryGoal): array
    {
        $milestones = [];
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $milestones = [
                ['day' => 7, 'milestone' => 'First week complete - establish routine'],
                ['day' => 14, 'milestone' => 'Two weeks in - notice initial changes'],
                ['day' => 30, 'milestone' => 'One month milestone - significant progress'],
                ['day' => 45, 'milestone' => 'Halfway point - stay motivated'],
                ['day' => 60, 'milestone' => 'Program complete - celebrate your transformation'],
            ];
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $milestones = [
                ['day' => 7, 'milestone' => 'First week - form and technique established'],
                ['day' => 14, 'milestone' => 'Two weeks - strength gains begin'],
                ['day' => 30, 'milestone' => 'One month - visible muscle development'],
                ['day' => 45, 'milestone' => 'Halfway point - significant strength increase'],
                ['day' => 60, 'milestone' => 'Program complete - impressive muscle gains'],
            ];
        } else {
            $milestones = [
                ['day' => 7, 'milestone' => 'First week complete - habit formation'],
                ['day' => 14, 'milestone' => 'Two weeks - improved energy and fitness'],
                ['day' => 30, 'milestone' => 'One month - noticeable improvements'],
                ['day' => 45, 'milestone' => 'Halfway point - strong progress'],
                ['day' => 60, 'milestone' => 'Program complete - fitness goals achieved'],
            ];
        }
        
        return $milestones;
    }

    private function generateNutritionGuidelines(string $primaryGoal, string $foodPreference, ?float $weightDelta): array
    {
        $guidelines = [];
        
        // Base calories (adjust based on goal)
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $guidelines['calories'] = '1,500-1,800 calories per day';
            $guidelines['macros'] = [
                'protein' => '120-150g (30-35%)',
                'carbs' => '150-200g (35-40%)',
                'fats' => '40-60g (25-30%)',
            ];
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $guidelines['calories'] = '2,200-2,600 calories per day';
            $guidelines['macros'] = [
                'protein' => '150-200g (30-35%)',
                'carbs' => '250-300g (40-45%)',
                'fats' => '60-80g (25-30%)',
            ];
        } else {
            $guidelines['calories'] = '1,800-2,200 calories per day';
            $guidelines['macros'] = [
                'protein' => '120-150g (25-30%)',
                'carbs' => '200-250g (40-45%)',
                'fats' => '50-70g (25-30%)',
            ];
        }
        
        // Generate guideline strings
        $guidelineStrings = [];
        
        if ($foodPreference === 'vegetarian') {
            $guidelineStrings[] = 'Focus on plant-based proteins: legumes, tofu, tempeh, quinoa';
            $guidelineStrings[] = 'Include iron-rich foods: spinach, lentils, fortified cereals';
        } elseif ($foodPreference === 'vegan') {
            $guidelineStrings[] = 'Ensure adequate B12 and omega-3 through supplements or fortified foods';
            $guidelineStrings[] = 'Combine plant proteins for complete amino acid profiles';
        } elseif ($foodPreference === 'keto') {
            $guidelineStrings[] = 'Maintain ketosis with <50g carbs per day';
            $guidelineStrings[] = 'Focus on healthy fats: avocados, nuts, olive oil';
        } else {
            $guidelineStrings[] = 'Prioritize whole foods: lean proteins, complex carbs, healthy fats';
        }
        
        $guidelineStrings[] = 'Eat protein with every meal to support muscle maintenance';
        $guidelineStrings[] = 'Stay hydrated: aim for 2-3 liters of water daily';
        $guidelineStrings[] = 'Time meals around workouts: eat 1-2 hours before and after';
        $guidelineStrings[] = 'Include 5-7 servings of fruits and vegetables daily';
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            $guidelineStrings[] = 'Practice portion control and mindful eating';
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            $guidelineStrings[] = 'Post-workout: consume protein and carbs within 30 minutes';
        }
        
        $guidelines['guidelines'] = $guidelineStrings;
        
        return $guidelines;
    }

    private function generateOverview(string $primaryGoal, int $durationDays, array $phases): string
    {
        $phaseNames = implode(', ', array_column($phases, 'name'));
        
        if ($primaryGoal === 'lose-weight' || $primaryGoal === 'lose_weight') {
            return "This {$durationDays}-day weight loss program is designed to help you achieve sustainable results through a combination of strength training, cardio, and proper nutrition. The program progresses through {$phaseNames}, gradually increasing intensity while maintaining a calorie deficit. Each phase builds on the previous one, ensuring continuous progress and preventing plateaus.";
        } elseif ($primaryGoal === 'gain_muscle' || $primaryGoal === 'build-muscle') {
            return "This {$durationDays}-day muscle building program focuses on progressive overload and optimal nutrition to maximize muscle growth. The program is structured into {$phaseNames}, each designed to target different aspects of muscle development. You'll build strength, increase muscle mass, and develop a solid foundation for continued growth.";
        } elseif ($primaryGoal === 'running_cardio' || $primaryGoal === 'improve-cardio') {
            return "This {$durationDays}-day running and cardio program will improve your cardiovascular fitness, endurance, and running performance. The program includes {$phaseNames}, progressing from base building to advanced training. You'll develop better running form, increase your stamina, and achieve your cardio goals.";
        } else {
            return "This {$durationDays}-day comprehensive fitness program combines strength training, cardio, and proper nutrition to help you achieve overall fitness and health. The program progresses through {$phaseNames}, ensuring balanced development across all fitness components. You'll build strength, improve cardiovascular health, and develop sustainable healthy habits.";
        }
    }
}

