<?php

namespace App\Services\AI;

use App\Models\Client;

class FitnessPlanAIService
{
    public function __construct(
        private readonly GeminiService $gemini
    ) {
    }

    /**
     * Generate greeting + 60-day fitness plan for a client using Gemini.
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

        $prompt = $this->buildPrompt([
            'first_name' => $firstName,
            'age' => $age,
            'gender' => $profile->gender,
            'height_cm' => $profile->height_cm,
            'current_weight_kg' => $currentWeight,
            'target_weight_kg' => $targetWeight,
            'bmi' => $profile->bmi,
            'bmi_category' => $profile->bmi_category,
            'body_type' => $profile->body_type,
            'goals' => $goalSlugs,
            'workout_days_per_week' => $profile->workout_preferences['days_per_week'] ?? null,
            'workout_location' => $profile->workout_preferences['location'] ?? null,
            'training_focus' => $profile->workout_preferences['training_focus'] ?? 'gym',
            'food_preference' => $profile->nutrition_preferences['primary'] ?? null,
            'experience_running' => $profile->experience_running,
            'experience_gym' => $profile->experience_gym,
            'experience_others_title' => $profile->experience_others_title,
            'experience_others' => $profile->experience_others,
            'location' => trim(implode(', ', array_filter([$profile->city, $profile->province, $profile->country]))),
            'duration_days' => $durationDays,
            'target_weight_change_kg' => $weightDelta,
        ]);

        $data = $this->gemini->generateJson($prompt, temperature: 0.65, maxOutputTokens: 2200);

        if (!isset($data['greeting'], $data['fitness_plan']) || !is_array($data['greeting']) || !is_array($data['fitness_plan'])) {
            throw new \RuntimeException('Invalid AI response structure');
        }

        return $data;
    }

    private function buildPrompt(array $ctx): string
    {
        $goals = $ctx['goals'] ?: [];
        $goalsText = count($goals) ? implode(', ', $goals) : 'general-fitness';

        $ageText = $ctx['age'] ? "{$ctx['age']}" : 'unknown';
        $weightChangeText = $ctx['target_weight_change_kg'] !== null ? "{$ctx['target_weight_change_kg']}kg" : 'a healthy amount';

        $otherActivityText = ($ctx['experience_others_title'] || $ctx['experience_others'])
            ? "{$ctx['experience_others_title']} ({$ctx['experience_others']})"
            : 'none';

        return <<<PROMPT
You are a professional fitness coach + nutrition coach for a SaaS product. Create a personalized plan that is safe, realistic, and encouraging.

User profile:
- First name: {$ctx['first_name']}
- Age: {$ageText}
- Gender: {$ctx['gender']}
- Height (cm): {$ctx['height_cm']}
- Current weight (kg): {$ctx['current_weight_kg']}
- Target weight (kg): {$ctx['target_weight_kg']}
- BMI: {$ctx['bmi']} ({$ctx['bmi_category']})
- Body type: {$ctx['body_type']}
- Goals (slugs): {$goalsText}
- Workout days per week: {$ctx['workout_days_per_week']}
- Workout location: {$ctx['workout_location']}
- Training focus: {$ctx['training_focus']}
- Food preference: {$ctx['food_preference']}
- Experience: Running={$ctx['experience_running']}, Gym={$ctx['experience_gym']}, Other={$otherActivityText}
- Location: {$ctx['location']}

Task:
Generate BOTH:
1) A professional greeting message that uses the user's first name and sets expectations:
   Example style: "Hi Jefferson, based on your data, you can lose 4kg in 60 days. Let's start Day 1 today."
   - Keep it confident and realistic (do not promise extreme results)
   - Include 2-3 concise recommendations (bullet-style strings)

2) A 60-day plan overview (duration_days={$ctx['duration_days']}):
   - 3-4 phases with names, duration, focus, workout focus, nutrition focus
   - a simple weekly_schedule template matching workout days per week (if unknown, use 4 days/week)
   - 3-5 milestones (day number + milestone)
   - nutrition_guidelines with calories + macros (reasonable defaults), and 4-6 guideline strings

Return ONLY JSON with this exact structure:
{
  "greeting": {
    "message": "string",
    "target_achievement": "string (e.g., lose {$weightChangeText} in 60 days)",
    "recommendations": ["string", "string", "string"]
  },
  "fitness_plan": {
    "plan_name": "string",
    "overview": "string",
    "duration_days": 60,
    "phases": [
      {
        "phase_number": 1,
        "phase_name": "string",
        "duration_weeks": 2,
        "focus": "string",
        "workout_focus": "string",
        "nutrition_focus": "string"
      }
    ],
    "weekly_schedule": {
      "workouts_per_week": 4,
      "example_week": {
        "day_1": "string",
        "day_2": "string",
        "day_3": "string",
        "day_4": "string",
        "day_5": "string",
        "day_6": "string",
        "day_7": "string"
      }
    },
    "key_milestones": [
      { "day": 7, "milestone": "string" }
    ],
    "nutrition_guidelines": {
      "daily_calories": 1800,
      "protein_g": 130,
      "carbs_g": 180,
      "fat_g": 60,
      "guidelines": ["string", "string", "string", "string"]
    }
  }
}
PROMPT;
    }
}


