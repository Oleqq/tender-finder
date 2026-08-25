<?php

return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'owner_id' => env('TELEGRAM_OWNER_ID'),
        'init_data_max_age_seconds' => (int) env('TELEGRAM_INIT_DATA_MAX_AGE_SECONDS', 300),
        'bot_request_timeout_seconds' => (int) env('TELEGRAM_BOT_REQUEST_TIMEOUT_SECONDS', 5),
    ],

    'legal' => [
        'documents_published' => (bool) env('LEGAL_DOCUMENTS_PUBLISHED', false),
        'offer_url' => env('LEGAL_OFFER_URL'),
        'offer_version' => env('LEGAL_OFFER_VERSION'),
        'privacy_url' => env('LEGAL_PRIVACY_URL'),
        'privacy_version' => env('LEGAL_PRIVACY_VERSION'),
    ],

    'access' => [
        'trial_hours' => (int) env('TRIAL_DURATION_HOURS', 72),
        'basic_active_query_limit' => (int) env('BASIC_ACTIVE_QUERY_LIMIT', 3),
    ],

    'rss' => [
        'live_polling_enabled' => (bool) env('RSS_LIVE_POLLING_ENABLED', false),
        'max_active_feeds' => (int) env('RSS_MAX_ACTIVE_FEEDS', 100),
        'poll_interval_seconds' => (int) env('RSS_POLL_INTERVAL_SECONDS', 600),
        'global_min_interval_milliseconds' => (int) env('RSS_GLOBAL_MIN_INTERVAL_MILLISECONDS', 1500),
        'max_response_bytes' => (int) env('RSS_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),
        'request_timeout_seconds' => (int) env('RSS_REQUEST_TIMEOUT_SECONDS', 30),
    ],
];
