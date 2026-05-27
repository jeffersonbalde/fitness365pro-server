<?php

namespace Database\Seeders;

use App\Models\Goal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GoalSeeder extends Seeder
{
    public function run(): void
    {
        $goals = [
            // Weight goals
            ['name' => 'Lose Weight', 'slug' => 'lose-weight', 'category' => 'weight', 'description' => 'Achieve and maintain a healthy weight', 'icon' => 'weight-scale', 'sort_order' => 1],
            ['name' => 'Gain Weight', 'slug' => 'gain-weight', 'category' => 'weight', 'description' => 'Build muscle and increase body mass', 'icon' => 'muscle', 'sort_order' => 2],
            ['name' => 'Maintain Weight', 'slug' => 'maintain-weight', 'category' => 'weight', 'description' => 'Keep your current weight stable', 'icon' => 'balance', 'sort_order' => 3],

            // Strength goals
            ['name' => 'Build Muscle', 'slug' => 'build-muscle', 'category' => 'strength', 'description' => 'Increase muscle mass and strength', 'icon' => 'dumbbell', 'sort_order' => 10],
            ['name' => 'Increase Strength', 'slug' => 'increase-strength', 'category' => 'strength', 'description' => 'Lift heavier and get stronger', 'icon' => 'barbell', 'sort_order' => 11],
            ['name' => 'Tone Body', 'slug' => 'tone-body', 'category' => 'strength', 'description' => 'Define and sculpt your physique', 'icon' => 'sculpture', 'sort_order' => 12],

            // Endurance goals
            ['name' => 'Improve Cardio', 'slug' => 'improve-cardio', 'category' => 'endurance', 'description' => 'Enhance cardiovascular fitness', 'icon' => 'heart', 'sort_order' => 20],
            ['name' => 'Run Faster', 'slug' => 'run-faster', 'category' => 'endurance', 'description' => 'Increase running speed and pace', 'icon' => 'running', 'sort_order' => 21],
            ['name' => 'Run Longer', 'slug' => 'run-longer', 'category' => 'endurance', 'description' => 'Build endurance for longer runs', 'icon' => 'marathon', 'sort_order' => 22],

            // Flexibility goals
            ['name' => 'Improve Flexibility', 'slug' => 'improve-flexibility', 'category' => 'flexibility', 'description' => 'Increase range of motion and flexibility', 'icon' => 'yoga', 'sort_order' => 30],
            ['name' => 'Reduce Stress', 'slug' => 'reduce-stress', 'category' => 'flexibility', 'description' => 'Find balance through mindful movement', 'icon' => 'meditation', 'sort_order' => 31],

            // Nutrition goals
            ['name' => 'Eat Healthier', 'slug' => 'eat-healthier', 'category' => 'nutrition', 'description' => 'Make better food choices', 'icon' => 'apple', 'sort_order' => 40],
            ['name' => 'Track Nutrition', 'slug' => 'track-nutrition', 'category' => 'nutrition', 'description' => 'Monitor daily nutrition intake', 'icon' => 'nutrition', 'sort_order' => 41],

            // General goals
            ['name' => 'Stay Active', 'slug' => 'stay-active', 'category' => 'general', 'description' => 'Maintain an active lifestyle', 'icon' => 'activity', 'sort_order' => 50],
            ['name' => 'Build Habits', 'slug' => 'build-habits', 'category' => 'general', 'description' => 'Create consistent workout routines', 'icon' => 'calendar', 'sort_order' => 51],
        ];

        foreach ($goals as $goal) {
            Goal::updateOrCreate(
                ['slug' => $goal['slug']],
                array_merge($goal, ['is_active' => true])
            );
        }
    }
}
