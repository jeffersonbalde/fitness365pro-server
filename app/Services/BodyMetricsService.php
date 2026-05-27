<?php

namespace App\Services;

class BodyMetricsService
{
    public function calculateBmi(float $heightCm, float $weightKg): float
    {
        if ($heightCm <= 0 || $weightKg <= 0) {
            throw new \InvalidArgumentException('Height and weight must be greater than 0');
        }

        $heightM = $heightCm / 100.0;
        $bmi = $weightKg / ($heightM * $heightM);

        return round($bmi, 2);
    }

    public function getBmiCategory(float $bmi): string
    {
        if ($bmi < 18.5) {
            return 'underweight';
        }
        if ($bmi < 25) {
            return 'normal';
        }
        if ($bmi < 30) {
            return 'overweight';
        }
        return 'obese';
    }

    /**
     * Simple heuristic. In a future iteration, replace with better signals (body fat %, waist/hip, etc.).
     */
    public function determineBodyType(string $bmiCategory, array $goalSlugs): string
    {
        $goalSlugs = array_values(array_filter(array_map('strval', $goalSlugs)));

        $isWeightLoss = in_array('lose-weight', $goalSlugs, true) || in_array('lose_weight', $goalSlugs, true);
        $isMuscleGain = in_array('build-muscle', $goalSlugs, true) || in_array('gain_muscle', $goalSlugs, true);

        if ($isWeightLoss && in_array($bmiCategory, ['overweight', 'obese'], true)) {
            return 'endomorph';
        }

        if ($isMuscleGain && in_array($bmiCategory, ['underweight', 'normal'], true)) {
            return 'ectomorph';
        }

        if ($isMuscleGain && in_array($bmiCategory, ['normal', 'overweight'], true)) {
            return 'mesomorph';
        }

        return 'balanced';
    }

    /**
     * Returns a safe, user-friendly timeline target in days.
     * - Weight loss: 0.5–1.0 kg/week (we use 0.75)
     * - Weight gain: 0.25–0.5 kg/week (we use 0.375)
     */
    public function estimateTargetDays(float $currentWeightKg, float $targetWeightKg): array
    {
        $deltaKg = round($targetWeightKg - $currentWeightKg, 2);
        $absDelta = abs($deltaKg);

        if ($absDelta < 0.01) {
            return [
                'target_days' => 0,
                'target_weight_change_kg' => 0.0,
            ];
        }

        $isLoss = $deltaKg < 0;
        $kgPerWeek = $isLoss ? 0.75 : 0.375;

        $weeks = (int) ceil($absDelta / $kgPerWeek);
        $days = $weeks * 7;

        return [
            'target_days' => $days,
            'target_weight_change_kg' => $absDelta,
        ];
    }
}


