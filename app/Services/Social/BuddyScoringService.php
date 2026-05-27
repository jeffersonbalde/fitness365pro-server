<?php

namespace App\Services\Social;

use App\Models\Client;
use App\Models\WorkoutLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuddyScoringService
{
    /**
     * Weight model totals 100.
     */
    private const WEIGHTS = [
        'niche' => 40,
        'goals' => 25,
        'location' => 15,
        'activity_recency' => 10,
        'activity_consistency' => 10,
    ];

    /**
     * Score one candidate against the viewer.
     */
    public function scorePair(Client $viewer, Client $candidate): array
    {
        $results = $this->scoreCandidates($viewer, collect([$candidate]));
        return $results->first() ?? [
            'candidate_id' => $candidate->id,
            'score' => 0.0,
            'breakdown' => [],
            'signals' => [],
        ];
    }

    /**
     * Score many candidates with batched workout aggregation.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function scoreCandidates(Client $viewer, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            return collect();
        }

        $viewer->loadMissing(['profile', 'goals:id,slug']);
        $candidates->each(function (Client $candidate) {
            $candidate->loadMissing(['profile', 'goals:id,slug']);
        });

        $candidateIds = $candidates->pluck('id')->values();
        $activityStats = $this->buildActivityStatsMap($candidateIds);

        return $candidates->map(function (Client $candidate) use ($viewer, $activityStats) {
            $stats = $activityStats[$candidate->id] ?? [
                'last_completed_workout_date' => null,
                'completed_last_30_days' => 0,
            ];

            $niche = $this->scoreNiche($viewer, $candidate);
            $goals = $this->scoreGoals($viewer, $candidate);
            $location = $this->scoreLocation($viewer, $candidate);
            $recency = $this->scoreActivityRecency($stats['last_completed_workout_date']);
            $consistency = $this->scoreActivityConsistency((int) $stats['completed_last_30_days']);

            $weighted = [
                'niche' => $niche * self::WEIGHTS['niche'],
                'goals' => $goals * self::WEIGHTS['goals'],
                'location' => $location * self::WEIGHTS['location'],
                'activity_recency' => $recency * self::WEIGHTS['activity_recency'],
                'activity_consistency' => $consistency * self::WEIGHTS['activity_consistency'],
            ];

            $score = round(array_sum($weighted), 2);
            $viewerPrimary = (string) ($viewer->profile?->primary_niche ?? '');
            $candidatePrimary = (string) ($candidate->profile?->primary_niche ?? '');

            return [
                'candidate_id' => $candidate->id,
                'score' => $score,
                'breakdown' => $weighted,
                'signals' => [
                    'same_primary_niche' => $viewerPrimary !== '' && $viewerPrimary === $candidatePrimary,
                    'goal_overlap_count' => $this->countGoalOverlap($viewer, $candidate),
                    'last_completed_workout_date' => $stats['last_completed_workout_date'],
                    'completed_last_30_days' => (int) $stats['completed_last_30_days'],
                ],
            ];
        })->sortByDesc('score')->values();
    }

    /**
     * @param Collection<int, string> $candidateIds
     * @return array<string, array{last_completed_workout_date: ?string, completed_last_30_days: int}>
     */
    private function buildActivityStatsMap(Collection $candidateIds): array
    {
        $since30 = now()->subDays(30)->toDateString();

        $rows = WorkoutLog::query()
            ->selectRaw('client_id')
            ->selectRaw('MAX(CASE WHEN status = ? THEN workout_date END) as last_completed_workout_date', ['completed'])
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND workout_date >= ? THEN 1 ELSE 0 END) as completed_last_30_days',
                ['completed', $since30]
            )
            ->whereIn('client_id', $candidateIds->all())
            ->groupBy('client_id')
            ->get();

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[$row->client_id] = [
                'last_completed_workout_date' => $row->last_completed_workout_date,
                'completed_last_30_days' => (int) ($row->completed_last_30_days ?? 0),
            ];
        }

        return $mapped;
    }

    private function scoreNiche(Client $viewer, Client $candidate): float
    {
        $viewerPrimary = (string) ($viewer->profile?->primary_niche ?? '');
        $candidatePrimary = (string) ($candidate->profile?->primary_niche ?? '');

        if ($viewerPrimary !== '' && $viewerPrimary === $candidatePrimary) {
            return 1.0;
        }

        $viewerSecondary = collect($viewer->profile?->secondary_niches ?? [])->filter()->values();
        $candidateSecondary = collect($candidate->profile?->secondary_niches ?? [])->filter()->values();

        if ($viewerPrimary !== '' && $candidateSecondary->contains($viewerPrimary)) {
            return 0.7;
        }

        if ($candidatePrimary !== '' && $viewerSecondary->contains($candidatePrimary)) {
            return 0.7;
        }

        $secondaryOverlap = $viewerSecondary->intersect($candidateSecondary)->count();
        if ($secondaryOverlap > 0) {
            return 0.5;
        }

        return 0.0;
    }

    private function scoreGoals(Client $viewer, Client $candidate): float
    {
        $viewerSlugs = collect($viewer->goals)->pluck('slug')->filter()->values();
        $candidateSlugs = collect($candidate->goals)->pluck('slug')->filter()->values();

        if ($viewerSlugs->isEmpty() || $candidateSlugs->isEmpty()) {
            return 0.0;
        }

        $intersection = $viewerSlugs->intersect($candidateSlugs)->count();
        $union = $viewerSlugs->merge($candidateSlugs)->unique()->count();
        if ($union === 0) {
            return 0.0;
        }

        return round($intersection / $union, 4);
    }

    private function scoreLocation(Client $viewer, Client $candidate): float
    {
        $viewerProfile = $viewer->profile;
        $candidateProfile = $candidate->profile;
        if (!$viewerProfile || !$candidateProfile) {
            return 0.0;
        }

        $score = 0.0;
        $viewerCountry = mb_strtolower((string) ($viewerProfile->country ?? ''));
        $viewerProvince = mb_strtolower((string) ($viewerProfile->province ?? ''));
        $viewerCity = mb_strtolower((string) ($viewerProfile->city ?? ''));
        $candidateCountry = mb_strtolower((string) ($candidateProfile->country ?? ''));
        $candidateProvince = mb_strtolower((string) ($candidateProfile->province ?? ''));
        $candidateCity = mb_strtolower((string) ($candidateProfile->city ?? ''));

        if ($viewerCountry !== '' && $viewerCountry === $candidateCountry) {
            $score += 0.4;
        }
        if ($viewerProvince !== '' && $viewerProvince === $candidateProvince) {
            $score += 0.35;
        }
        if ($viewerCity !== '' && $viewerCity === $candidateCity) {
            $score += 0.25;
        }

        return min(1.0, $score);
    }

    private function scoreActivityRecency(?string $lastCompletedWorkoutDate): float
    {
        if (!$lastCompletedWorkoutDate) {
            return 0.0;
        }

        $daysSince = Carbon::parse($lastCompletedWorkoutDate)->diffInDays(now());
        if ($daysSince <= 3) {
            return 1.0;
        }
        if ($daysSince <= 7) {
            return 0.8;
        }
        if ($daysSince <= 14) {
            return 0.55;
        }
        if ($daysSince <= 30) {
            return 0.3;
        }
        if ($daysSince <= 60) {
            return 0.1;
        }

        return 0.0;
    }

    private function scoreActivityConsistency(int $completedLast30Days): float
    {
        if ($completedLast30Days <= 0) {
            return 0.0;
        }

        // 12 completed workouts/month ~= very consistent baseline.
        return min(1.0, $completedLast30Days / 12);
    }

    private function countGoalOverlap(Client $viewer, Client $candidate): int
    {
        $viewerSlugs = collect($viewer->goals)->pluck('slug')->filter()->values();
        $candidateSlugs = collect($candidate->goals)->pluck('slug')->filter()->values();
        return $viewerSlugs->intersect($candidateSlugs)->count();
    }
}

