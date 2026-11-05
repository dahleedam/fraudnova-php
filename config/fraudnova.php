<?php

declare(strict_types=1);

return [
    // Your FraudNova API key (starts with fn_), from the dashboard's API keys page.
    'api_key' => env('FRAUDNOVA_API_KEY'),

    // API base URL; change only to point at a different FraudNova environment.
    'base_url' => env('FRAUDNOVA_BASE_URL', 'https://fraudnova.com/api/v1'),

    // Request timeout in seconds.
    'timeout' => (float) env('FRAUDNOVA_TIMEOUT', 30),

    // 0 or 1 = no retry; 3 = retry a 429 up to twice.
    'retry_max_attempts' => (int) env('FRAUDNOVA_RETRY_MAX_ATTEMPTS', 0),
];
