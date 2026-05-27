<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientProfile;
use App\Models\Goal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OnboardingController extends Controller
{
    private function deriveOverallExperienceLevel(?string $running, ?string $gym, ?string $others): ?string
    {
        $rank = [
            'beginner' => 1,
            'intermediate' => 2,
            'advanced' => 3,
            'expert' => 4,
        ];

        $values = array_filter([$running, $gym, $others], fn ($value) => is_string($value) && isset($rank[$value]));
        if (empty($values)) {
            return null;
        }

        usort($values, fn ($a, $b) => $rank[$b] <=> $rank[$a]);
        return $values[0];
    }

    /**
     * Get available goals for selection
     */
    public function getGoals()
    {
        $goals = Goal::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'goals' => $goals,
            ],
        ], 200);
    }

    /**
     * Update onboarding step
     */
    public function updateStep(Request $request, $step)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            $profile = ClientProfile::create(['client_id' => $client->id]);
        }

        $step = (int) $step;

        if ($step < 1 || $step > 6) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid step number',
            ], 400);
        }

        switch ($step) {
            case 1:
                // Goals - initial selection based on high-level objectives
                $validator = Validator::make($request->all(), [
                    'goals' => 'required|array|min:1',
                    'goals.*' => 'required|uuid|exists:goals,id',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $goals = Goal::whereIn('id', $request->goals)->get();
                $client->goals()->sync($goals->pluck('id')->toArray());
                // Move user to step 2 (profile + metrics)
                $profile->onboarding_step = 2;
                $profile->save();

                break;

            case 2:
                // Profile + basic physical metrics
                $validator = Validator::make($request->all(), [
                    'first_name' => 'required|string|max:255',
                    'last_name' => 'required|string|max:255',
                    'gender' => 'required|in:male,female,other,prefer_not_to_say',
                    'date_of_birth' => 'required|date|before:today',
                    'height_cm' => 'required|integer|min:50|max:300',
                    'current_weight_kg' => 'required|numeric|min:20|max:500',
                    'target_weight_kg' => 'nullable|numeric|min:20|max:500',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $profile->first_name = $request->first_name;
                $profile->last_name = $request->last_name;
                $profile->gender = $request->gender;
                $profile->date_of_birth = $request->date_of_birth;
                $profile->height_cm = $request->height_cm;
                $profile->current_weight_kg = $request->current_weight_kg;
                $profile->target_weight_kg = $request->target_weight_kg;
                // Move user to step 3 (location)
                $profile->onboarding_step = 3;
                $profile->save();

                break;

            case 3:
                // Location (city, region, country)
                $validator = Validator::make($request->all(), [
                    'city' => 'nullable|string|max:255',
                    'province' => 'required|string|max:255', // region / state / province
                    'country' => 'required|string|max:255',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $profile->update($request->only([
                    'city',
                    'province',
                    'country',
                ]));
                // Move user to step 4 (preferences)
                $profile->onboarding_step = 4;
                $profile->save();

                break;

            case 4:
                // Preferences (workout + nutrition)
                $validator = Validator::make($request->all(), [
                    'workout_days_per_week' => 'required|in:3-4,4-5,5-6',
                    'workout_location' => 'required|in:home,gym,outdoor',
                    'training_focus' => 'required|in:running,gym,biking',
                    'food_preference' => 'nullable|string|max:255',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $workoutPrefs = $profile->workout_preferences ?? [];
                if (!is_array($workoutPrefs)) {
                    $workoutPrefs = [];
                }
                $workoutPrefs['days_per_week'] = $request->workout_days_per_week;
                $workoutPrefs['location'] = $request->workout_location;
                $workoutPrefs['training_focus'] = $request->training_focus;
                // Keep social/profile niche indicator aligned with onboarding focus choice.
                $profile->primary_niche = $request->training_focus;

                $nutritionPrefs = $profile->nutrition_preferences ?? [];
                if (!is_array($nutritionPrefs)) {
                    $nutritionPrefs = [];
                }
                if ($request->filled('food_preference')) {
                    $nutritionPrefs['primary'] = $request->food_preference;
                }

                $profile->workout_preferences = $workoutPrefs;
                $profile->nutrition_preferences = $nutritionPrefs;
                // Move user to step 5 (experience level)
                $profile->onboarding_step = 5;
                $profile->save();

                break;

            case 5:
                // Experience levels (running, gym, optional other)
                $validator = Validator::make($request->all(), [
                    'experience_running' => 'nullable|in:beginner,intermediate,advanced,expert',
                    'experience_gym' => 'nullable|in:beginner,intermediate,advanced,expert',
                    'experience_biking' => 'nullable|in:beginner,intermediate,advanced,expert',
                    'experience_others_title' => 'nullable|string|max:100',
                    'experience_others' => 'nullable|in:beginner,intermediate,advanced,expert',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $trainingFocus = $profile->workout_preferences['training_focus'] ?? null;
                $focusField = match ($trainingFocus) {
                    'running' => 'experience_running',
                    'gym' => 'experience_gym',
                    'biking' => 'experience_biking',
                    default => null,
                };
                if ($focusField && !$request->filled($focusField)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Validation failed',
                        'errors' => [
                            $focusField => ['Please select your experience level for your training focus.'],
                        ],
                    ], 422);
                }

                $profile->experience_running = $request->experience_running;
                $profile->experience_gym = $request->experience_gym;
                $profile->experience_others_title = $request->experience_others_title;
                $profile->experience_others = $request->experience_others;
                $workoutPrefs = $profile->workout_preferences ?? [];
                if (!is_array($workoutPrefs)) {
                    $workoutPrefs = [];
                }
                if ($request->filled('experience_biking')) {
                    $workoutPrefs['experience_biking'] = $request->experience_biking;
                } else {
                    unset($workoutPrefs['experience_biking']);
                }
                $profile->workout_preferences = $workoutPrefs;
                $profile->experience_level = $this->deriveOverallExperienceLevel(
                    $request->experience_running,
                    $request->experience_gym,
                    $request->experience_biking ?: $request->experience_others
                );

                // Calculate BMI, category, body type, and timeline if we have enough data
                if ($profile->height_cm && $profile->current_weight_kg) {
                    $metrics = app(\App\Services\BodyMetricsService::class);
                    $bmi = $metrics->calculateBmi((float) $profile->height_cm, (float) $profile->current_weight_kg);
                    $profile->bmi = $bmi;
                    $profile->bmi_category = $metrics->getBmiCategory($bmi);

                    $goalSlugs = $client->goals()->pluck('slug')->toArray();
                    $profile->body_type = $metrics->determineBodyType($profile->bmi_category, $goalSlugs);

                    if ($profile->target_weight_kg !== null) {
                        $timeline = $metrics->estimateTargetDays(
                            (float) $profile->current_weight_kg,
                            (float) $profile->target_weight_kg
                        );
                        $profile->target_days = $timeline['target_days'];
                        $profile->target_weight_change_kg = $timeline['target_weight_change_kg'];
                    }
                }

                // Move to final step: no fitness plan generation (social/community product)
                $profile->onboarding_step = 6;
                $profile->fitness_plan_status = 'skipped';
                $profile->fitness_plan_error = null;
                $profile->save();

                break;

            case 6:
                // Mark onboarding as completed
                $profile->onboarding_step = 6;
                $profile->onboarding_completed = true;
                $profile->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Onboarding completed successfully',
                    'data' => [
                        'profile' => $profile->fresh(),
                    ],
                ], 200);
        }

        // Save profile if not already saved in case
        if (!$profile->isDirty()) {
            $profile->save();
        }

        return response()->json([
            'success' => true,
            'message' => "Step {$step} completed",
            'data' => [
                'profile' => $profile->fresh(),
                'current_step' => $step,
            ],
        ], 200);
    }

    /**
     * Get current onboarding status
     */
    public function getStatus(Request $request)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            return response()->json([
                'success' => true,
                'data' => [
                    'onboarding_step' => 0,
                    'onboarding_completed' => false,
                    'goals' => [],
                    'theme_mode' => 'dark',
                ],
            ], 200);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'onboarding_step' => $profile->onboarding_step,
                'onboarding_completed' => $profile->onboarding_completed,
                'goals' => $client->goals,
                'fitness_plan_status' => $profile->fitness_plan_status,
                'bmi' => $profile->bmi,
                'bmi_category' => $profile->bmi_category,
                'body_type' => $profile->body_type,
                'theme_mode' => $profile->theme_mode ?? 'dark',
            ],
        ], 200);
    }

    /**
     * Get AI fitness plan generation status + data (if ready)
     */
    public function getFitnessPlanStatus(Request $request)
    {
        $client = $request->user();
        $profile = $client->profile;

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $profile->fitness_plan_status ?? 'pending',
                'fitness_plan' => $profile->fitness_plan_status === 'completed' ? $profile->fitness_plan : null,
                'greeting' => $profile->fitness_plan_status === 'completed' ? [
                    'message' => $profile->ai_greeting_message,
                    'recommendations' => $profile->ai_recommendations,
                ] : null,
                'error' => $profile->fitness_plan_error,
                'bmi' => $profile->bmi,
                'bmi_category' => $profile->bmi_category,
                'body_type' => $profile->body_type,
            ],
        ], 200);
    }
}
