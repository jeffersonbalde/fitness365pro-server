<?php

namespace App\Services\FitnessPlan;

use App\Services\FitnessPlan\FitnessPlanGeneratorInterface;
use App\Services\FitnessPlan\TemplateFitnessPlanGenerator;

/**
 * Resolves which plan generator to use.
 * 
 * For MVP: Always uses template generator (no AI).
 * 
 * Later: Can check config/env to decide:
 * - If AI enabled + API key exists → use AI generator
 * - Else → use template generator
 * - If AI fails → fallback to template
 */
class FitnessPlanGeneratorResolver
{
    /**
     * Get the appropriate plan generator.
     * 
     * @return FitnessPlanGeneratorInterface
     */
    public function resolve(): FitnessPlanGeneratorInterface
    {
        // MVP: Always use template generator (no AI yet)
        // Later: Check config for AI_PLAN_ENABLED, AI_API_KEY, etc.
        
        $aiEnabled = config('services.ai.enabled', false);
        $aiApiKey = config('services.gemini.api_key', null);
        
        // For now, always use template (MVP approach)
        // When ready for AI: uncomment below and implement AiFitnessPlanGenerator
        /*
        if ($aiEnabled && $aiApiKey) {
            try {
                return app(AiFitnessPlanGenerator::class);
            } catch (\Exception $e) {
                \Log::warning('AI generator unavailable, falling back to template', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        */
        
        return app(TemplateFitnessPlanGenerator::class);
    }
}

