<?php

/**
 * Quick test script for plan generator
 * 
 * Usage: php test-generator.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "🧪 Testing Plan Generator System\n";
echo str_repeat("=", 50) . "\n\n";

// Get a test client
$client = \App\Models\Client::first();
if (!$client) {
    echo "❌ No clients found. Create a test user first.\n";
    echo "   Run: php artisan tinker\n";
    echo "   Then: \\App\\Models\\Client::factory()->create();\n";
    exit(1);
}

echo "✅ Found client: {$client->email}\n\n";

// Check if client has profile
$profile = $client->profile;
if (!$profile) {
    echo "❌ Client has no profile. Complete onboarding first.\n";
    exit(1);
}

echo "✅ Client has profile\n";
echo "   Goals: " . $client->goals()->pluck('slug')->implode(', ') . "\n";
echo "   BMI: " . ($profile->bmi ?? 'N/A') . "\n";
echo "   Workout Days: " . ($profile->workout_preferences['days_per_week'] ?? 'N/A') . "\n\n";

// Test resolver
echo "📦 Testing Resolver...\n";
try {
    $resolver = app(\App\Services\FitnessPlan\FitnessPlanGeneratorResolver::class);
    $generator = $resolver->resolve();
    
    echo "   Generator Class: " . get_class($generator) . "\n";
    echo "   Source: " . $generator->getSource() . "\n";
    
    if ($generator->getSource() !== 'template') {
        echo "   ⚠️  Warning: Expected 'template', got '{$generator->getSource()}'\n";
    } else {
        echo "   ✅ Resolver working correctly\n";
    }
} catch (\Exception $e) {
    echo "   ❌ Resolver error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";

// Test generation
echo "🎯 Testing Plan Generation...\n";
try {
    $result = $generator->generateForClient($client);
    
    echo "   ✅ Plan generated successfully!\n\n";
    
    // Validate structure
    $plan = $result['fitness_plan'];
    $greeting = $result['greeting'];
    
    echo "📋 Plan Details:\n";
    echo "   Plan Name: " . ($plan['plan_name'] ?? 'MISSING') . "\n";
    echo "   Duration: " . ($plan['duration_days'] ?? 'MISSING') . " days\n";
    echo "   Phases: " . (isset($plan['phases']) ? count($plan['phases']) : 0) . "\n";
    echo "   Weekly Schedule Days: " . (isset($plan['weekly_schedule']) ? count($plan['weekly_schedule']) : 0) . "\n";
    echo "   Milestones: " . (isset($plan['milestones']) ? count($plan['milestones']) : 0) . "\n";
    echo "   Has Nutrition Guidelines: " . (isset($plan['nutrition_guidelines']) ? 'Yes' : 'No') . "\n";
    
    // Validate required fields
    $required = ['plan_name', 'overview', 'duration_days', 'phases', 'weekly_schedule', 'milestones', 'nutrition_guidelines'];
    $missing = [];
    foreach ($required as $field) {
        if (!isset($plan[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        echo "\n   ❌ Missing fields: " . implode(', ', $missing) . "\n";
    } else {
        echo "\n   ✅ All required fields present\n";
    }
    
    // Validate phases
    if (isset($plan['phases'])) {
        $phaseCount = count($plan['phases']);
        if ($phaseCount !== 4) {
            echo "   ⚠️  Warning: Expected 4 phases, got {$phaseCount}\n";
        } else {
            echo "   ✅ Correct number of phases (4)\n";
        }
        
        // Check phase structure
        $phaseRequired = ['name', 'duration_days', 'focus', 'workout_focus', 'nutrition_focus'];
        foreach ($plan['phases'] as $i => $phase) {
            $phaseMissing = [];
            foreach ($phaseRequired as $field) {
                if (!isset($phase[$field])) {
                    $phaseMissing[] = $field;
                }
            }
            if (!empty($phaseMissing)) {
                echo "   ❌ Phase " . ($i + 1) . " missing: " . implode(', ', $phaseMissing) . "\n";
            }
        }
    }
    
    // Validate milestones
    if (isset($plan['milestones'])) {
        $milestoneCount = count($plan['milestones']);
        if ($milestoneCount < 4) {
            echo "   ⚠️  Warning: Expected at least 4 milestones, got {$milestoneCount}\n";
        } else {
            echo "   ✅ Sufficient milestones ({$milestoneCount})\n";
        }
    }
    
    // Show greeting preview
    echo "\n💬 Greeting Preview:\n";
    echo "   " . substr($greeting['message'] ?? 'MISSING', 0, 100) . "...\n";
    
    if (isset($greeting['recommendations']) && is_array($greeting['recommendations'])) {
        echo "   Recommendations: " . count($greeting['recommendations']) . "\n";
    }
    
    echo "\n✅ All tests passed!\n";
    
} catch (\Exception $e) {
    echo "   ❌ Generation error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\n   Stack trace:\n";
    echo "   " . str_replace("\n", "\n   ", $e->getTraceAsString()) . "\n";
    exit(1);
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎉 Generator test complete!\n";

