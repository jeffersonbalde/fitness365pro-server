<?php

namespace App\Services\Social;

use App\Models\Client;
use App\Models\WorkoutLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class FeedRankingService
{
    /**
     * Weighted score out of 100.
     */
    private const WEIGHTS = [
        'niche_affinity' => 35,
        'follow_boost' => 25,
        'freshness' => 25,
        'engagement' => 15,
    ];

    /**
     * @param Collection<int, WorkoutLog> $workouts
     * @param array<string, bool> $followingLookup
     * @return Collection<int, array{workout: WorkoutLog, score: float, breakdown: array<string, float>}>
     */
    public function rank(Client $viewer, Collection $workouts, array $followingLookup): Collection
    {
        $viewerPrimaryNiche = (string) ($viewer->profile?->primary_niche ?? '');
        $viewerSecondary = collect($viewer->profile?->secondary_niches ?? [])->filter()->values();

        return $workouts->map(function (WorkoutLog $workout) use ($viewer, $viewerPrimaryNiche, $viewerSecondary, $followingLookup) {
            $author = $workout->client;
            $authorId = (string) ($author?->id ?? '');
            $authorPrimaryNiche = (string) ($author?->profile?->primary_niche ?? '');
            $authorSecondary = collect($author?->profile?->secondary_niches ?? [])->filter()->values();

            $nicheAffinity = 0.0;
            if ($viewerPrimaryNiche !== '' && $viewerPrimaryNiche === $authorPrimaryNiche) {
                $nicheAffinity = 1.0;
            } elseif ($viewerPrimaryNiche !== '' && $authorSecondary->contains($viewerPrimaryNiche)) {
                $nicheAffinity = 0.7;
            } elseif ($authorPrimaryNiche !== '' && $viewerSecondary->contains($authorPrimaryNiche)) {
                $nicheAffinity = 0.7;
            } elseif ($viewerSecondary->intersect($authorSecondary)->isNotEmpty()) {
                $nicheAffinity = 0.5;
            }

            $isSelf = $authorId !== '' && $authorId === (string) $viewer->id;
            $isFollowing = $authorId !== '' && ($followingLookup[$authorId] ?? false);
            $followBoost = $isSelf ? 0.8 : ($isFollowing ? 1.0 : 0.0);

            $freshness = $this->freshnessScore($workout->workout_date, $workout->created_at);

            $likes = (int) ($workout->likes_count ?? 0);
            $comments = (int) ($workout->comments_count ?? 0);
            $engagementRaw = ($likes * 2) + ($comments * 3);
            $engagement = min(1.0, $engagementRaw / 20);

            $breakdown = [
                'niche_affinity' => $nicheAffinity * self::WEIGHTS['niche_affinity'],
                'follow_boost' => $followBoost * self::WEIGHTS['follow_boost'],
                'freshness' => $freshness * self::WEIGHTS['freshness'],
                'engagement' => $engagement * self::WEIGHTS['engagement'],
            ];

            return [
                'workout' => $workout,
                'score' => round(array_sum($breakdown), 2),
                'breakdown' => $breakdown,
            ];
        })->sort(function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                $aDate = $a['workout']->workout_date?->toDateString() ?? $a['workout']->created_at?->toDateString() ?? '';
                $bDate = $b['workout']->workout_date?->toDateString() ?? $b['workout']->created_at?->toDateString() ?? '';
                return strcmp($bDate, $aDate);
            }

            return $b['score'] <=> $a['score'];
        })->values();
    }

    private function freshnessScore($workoutDate, $createdAt): float
    {
        $date = $workoutDate ?? $createdAt;
        if (!$date) {
            return 0.0;
        }

        $days = Carbon::parse($date)->diffInDays(now());
        if ($days <= 1) return 1.0;
        if ($days <= 3) return 0.85;
        if ($days <= 7) return 0.65;
        if ($days <= 14) return 0.45;
        if ($days <= 30) return 0.25;
        return 0.1;
    }
}

