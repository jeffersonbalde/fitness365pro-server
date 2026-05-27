<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\AI\MockFitnessPlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateFitnessPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60; // 1 minute (mock service is fast)

    public function __construct(
        private readonly string $clientId
    ) {
    }

    public function handle(MockFitnessPlanService $planService): void
    {
        $client = Client::find($this->clientId);
        if (!$client || !$client->profile) {
            Log::warning("GenerateFitnessPlanJob: client/profile not found for ID {$this->clientId}");
            return;
        }

        $profile = $client->profile;

        // Mark as generating
        $profile->fitness_plan_status = 'generating';
        $profile->fitness_plan_error = null;
        $profile->save();

        try {
            $result = $planService->generateForClient($client);

            $profile->fitness_plan = $result['fitness_plan'];
            $profile->ai_greeting_message = $result['greeting']['message'] ?? null;
            $profile->ai_recommendations = $result['greeting']['recommendations'] ?? null;
            $profile->fitness_plan_status = 'completed';
            $profile->fitness_plan_generated_at = now();
            $profile->plan_start_date = now();

            if ($profile->target_days) {
                $profile->plan_end_date = now()->addDays((int) $profile->target_days);
            }

            $profile->save();
        } catch (\Throwable $e) {
            Log::error("GenerateFitnessPlanJob failed for client {$this->clientId}: {$e->getMessage()}");

            $profile->fitness_plan_status = 'failed';
            $profile->fitness_plan_error = $e->getMessage();
            $profile->save();

            throw $e;
        }
    }
}


