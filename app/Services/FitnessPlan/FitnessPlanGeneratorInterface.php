<?php

namespace App\Services\FitnessPlan;

use App\Models\Client;

/**
 * Unified interface for fitness plan generators.
 * 
 * This allows us to swap between:
 * - Template-based generator (MVP, no AI)
 * - AI generator (future, when ready)
 * 
 * Same structure, different content source.
 */
interface FitnessPlanGeneratorInterface
{
    /**
     * Generate a fitness plan for a client.
     * 
     * @param Client $client
     * @param int $durationDays Default 60 days
     * @return array{greeting: array{message: string, recommendations: array}, fitness_plan: array}
     */
    public function generateForClient(Client $client, int $durationDays = 60): array;

    /**
     * Get the generator type/source name.
     * 
     * @return string e.g., 'template', 'ai', 'mock'
     */
    public function getSource(): string;
}

