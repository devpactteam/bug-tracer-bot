<?php

return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'proxy' => [
            'type' => env('TELEGRAM_PROXY_TYPE'),
            'server' => env('TELEGRAM_PROXY_SERVER'),
            'port' => (int) env('TELEGRAM_PROXY_PORT', 0),
            'secret' => env('TELEGRAM_PROXY_SECRET'),
            'http_bridge' => env('TELEGRAM_HTTP_PROXY'),
        ],
        'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 25),
        'poll_limit' => (int) env('TELEGRAM_POLL_LIMIT', 100),
        'allowed_operator_ids' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('TELEGRAM_ALLOWED_OPERATOR_IDS', ''))
        ))),
    ],

    'intake' => [
        'debounce_seconds' => (int) env('INCIDENT_DEBOUNCE_SECONDS', 45),
        'clarification_timeout_minutes' => (int) env('INCIDENT_CLARIFICATION_TIMEOUT_MINUTES', 30),
        'max_clarification_rounds' => (int) env('INCIDENT_MAX_CLARIFICATION_ROUNDS', 3),
        'queue' => env('INCIDENT_QUEUE', 'default'),
    ],

    'ai' => [
        'driver' => env('INCIDENT_AI_DRIVER', 'fake'),
        'base_url' => rtrim(env('AI_API_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('AI_API_KEY'),
    ],
];
