<?php

return [
    'feed_ranking' => [
        // Master switch for ranked feed rollout.
        'enabled' => env('WORKOUT_FEED_RANKING_ENABLED', true),

        // Enables lightweight request-level feed telemetry logging.
        'monitoring_enabled' => env('WORKOUT_FEED_MONITORING_ENABLED', true),
    ],
];

