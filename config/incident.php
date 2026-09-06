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
        'require_assignee_before_approval' => true,
    ],

    // Second Telegram bot that delivers finished tickets to the assigned user.
    // It connects exactly like the main bot (same API base + proxy) and is
    // long-polled with `php artisan telegram:poll-assignee`; users /start it so
    // their numeric chat_id is captured on support_users.assignee_chat_id.
    'assignee_bot' => [
        'token' => env('ASSIGNEE_BOT_TOKEN'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 25),
        'poll_limit' => (int) env('TELEGRAM_POLL_LIMIT', 100),
        'proxy' => [
            'type' => env('TELEGRAM_PROXY_TYPE'),
            'server' => env('TELEGRAM_PROXY_SERVER'),
            'port' => (int) env('TELEGRAM_PROXY_PORT', 0),
            'secret' => env('TELEGRAM_PROXY_SECRET'),
            'http_bridge' => env('TELEGRAM_HTTP_PROXY'),
        ],
    ],

    'ai' => [
        'driver' => env('INCIDENT_AI_DRIVER', 'fake'),
        'base_url' => rtrim(env('AI_API_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('AI_API_KEY'),
    ],

    // Problem-category taxonomy used to describe which categories each support
    // user covers and to match assignees to incidents.
    'categories' => [
        'client' => ['label' => 'کلاینت / فرانت‌اند'],
        'backend' => ['label' => 'بک‌اند'],
        'system_analysis' => ['label' => 'تحلیل سیستم'],
        'database' => ['label' => 'دیتابیس'],
        'payment' => ['label' => 'پرداخت'],
        'network' => ['label' => 'شبکه'],
        'performance' => ['label' => 'عملکرد'],
        'account' => ['label' => 'اکانت / ورود'],
        'other' => ['label' => 'سایر'],
    ],
];
