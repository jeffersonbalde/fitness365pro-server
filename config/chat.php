<?php

return [
    'send_rate_limit_per_minute' => (int) env('CHAT_SEND_RATE_LIMIT_PER_MINUTE', 30),
    'retention_days' => (int) env('CHAT_RETENTION_DAYS', 365),
    'moderation' => [
        'blocked_terms' => array_values(array_filter(array_map(
            fn ($term) => trim((string) $term),
            explode(',', (string) env('CHAT_BLOCKED_TERMS', ''))
        ))),
    ],
];

