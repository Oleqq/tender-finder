<?php

return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Comma-separated personal Telegram user IDs allowed to administer
        // the product after a verified Mini App login. Keep owner_id for
        // backward-compatible single-owner deployments.
        'superadmin_ids' => env('TELEGRAM_SUPERADMIN_IDS'),
        'owner_id' => env('TELEGRAM_OWNER_ID'),
        // Used only for a private-chat button in transactional notifications.
        // APP_URL remains the safe default when no override is configured.
        'mini_app_url' => env('TELEGRAM_MINI_APP_URL') ?: env('APP_URL'),
        'init_data_max_age_seconds' => (int) env('TELEGRAM_INIT_DATA_MAX_AGE_SECONDS', 86400),
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
        'pro_active_query_limit' => (int) env('PRO_ACTIVE_QUERY_LIMIT', 10),
    ],

    'payments' => [
        // Reserved integration boundary for a future external web checkout.
        // Do not enable inside Telegram for digital services: Telegram requires XTR there.
        'yookassa' => [
            'enabled' => (bool) env('YOOKASSA_ENABLED', false),
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
        ],
    ],

    'local_mvp_operator' => [
        // This is deliberately off unless the Docker development overlay turns
        // it on. It provides a local-only operator account without weakening
        // production Telegram authentication.
        'enabled' => (bool) env('LOCAL_MVP_OPERATOR_ENABLED', false),
        'active_query_limit' => (int) env('LOCAL_MVP_OPERATOR_ACTIVE_QUERY_LIMIT', 20),
    ],

    'remote_mvp_operator' => [
        // Production testing is opt-in and can only start with an expiring
        // signed link generated in the private Railway console.
        'enabled' => (bool) env('REMOTE_MVP_OPERATOR_ENABLED', false),
    ],

    'local_mvp_subscriber' => [
        // This local-only identity lets us test subscriber ownership and the
        // onboarding flow without accepting a browser-supplied Telegram ID.
        'enabled' => (bool) env('LOCAL_MVP_SUBSCRIBER_ENABLED', false),
    ],

    'local_mvp_full_access' => [
        // This temporary UI-acceptance switch is restricted to local/testing.
        // Never enable it on Railway, VPS, or another public environment.
        'enabled' => (bool) env('LOCAL_MVP_FULL_ACCESS_ENABLED', false),
        'active_query_limit' => (int) env('LOCAL_MVP_FULL_ACCESS_QUERY_LIMIT', 20),
    ],

    'rostender' => [
        // The supplier's API licence must explicitly allow distribution to
        // Tender Finder users. Keep both switches false until that written
        // permission has been received and reviewed.
        'enabled' => (bool) env('ROSTENDER_ENABLED', false),
        'public_distribution_approved' => (bool) env('ROSTENDER_PUBLIC_DISTRIBUTION_APPROVED', false),
        'api_key' => env('ROSTENDER_API_KEY'),
        'base_url' => env('ROSTENDER_BASE_URL', 'https://rostender.info/api/tenders/get'),
        'request_timeout_seconds' => (int) env('ROSTENDER_REQUEST_TIMEOUT_SECONDS', 15),
        'daily_quota_limit' => (int) env('ROSTENDER_DAILY_QUOTA_LIMIT', 200),
        // Kept out of normal polling so a future explicitly approved urgent
        // operation is not starved by the scheduler.
        'daily_quota_reserve' => (int) env('ROSTENDER_DAILY_QUOTA_RESERVE', 20),
        'max_details_per_poll' => (int) env('ROSTENDER_MAX_DETAILS_PER_POLL', 20),
        'basic_poll_interval_seconds' => (int) env('ROSTENDER_BASIC_POLL_INTERVAL_SECONDS', 3600),
        'pro_poll_interval_seconds' => (int) env('ROSTENDER_PRO_POLL_INTERVAL_SECONDS', 3600),
        'basic_manual_checks_per_day' => (int) env('ROSTENDER_BASIC_MANUAL_CHECKS_PER_DAY', 0),
        'pro_manual_checks_per_day' => (int) env('ROSTENDER_PRO_MANUAL_CHECKS_PER_DAY', 0),
        'basic_active_monitor_limit' => (int) env('ROSTENDER_BASIC_ACTIVE_MONITOR_LIMIT', 0),
        'pro_active_monitor_limit' => (int) env('ROSTENDER_PRO_ACTIVE_MONITOR_LIMIT', 0),
    ],

    'sber_ast' => [
        // Public registries do not require supplier credentials. Keep this
        // opt-in so a deployment can verify network access and the current
        // platform terms before scheduled polling starts.
        'enabled' => (bool) env('SBER_AST_ENABLED', false),
        'registry_urls' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SBER_AST_REGISTRY_URLS', 'https://utp.sberbank-ast.ru/VIP/List/PurchaseList')),
        ))),
        'request_timeout_seconds' => (int) env('SBER_AST_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('SBER_AST_POLL_INTERVAL_SECONDS', 3600),
        'user_agent' => env('SBER_AST_USER_AGENT', 'TenderFinder/1.0 (+public procurement monitoring)'),
    ],

    'workspace_ru' => [
        // Workspace publishes a dedicated public RSS feed for tenders.
        'enabled' => (bool) env('WORKSPACE_RU_ENABLED', false),
        'feed_url' => env('WORKSPACE_RU_FEED_URL', 'https://workspace.ru/tenders/rss/'),
        'request_timeout_seconds' => (int) env('WORKSPACE_RU_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('WORKSPACE_RU_POLL_INTERVAL_SECONDS', 3600),
        'user_agent' => env('WORKSPACE_RU_USER_AGENT', 'TenderFinder/1.0 (+public tender RSS monitoring)'),
    ],

    'etp_gpb' => [
        // Official RSS of public, currently active procedures. Enable after a live feed smoke check.
        'enabled' => (bool) env('ETP_GPB_ENABLED', false),
        'feed_url' => 'https://etpgpb.ru/procedures.rss',
        'request_timeout_seconds' => (int) env('ETP_GPB_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('ETP_GPB_POLL_INTERVAL_SECONDS', 3600),
        'user_agent' => env('ETP_GPB_USER_AGENT', 'TenderFinder/1.0 (+public tender RSS monitoring)'),
    ],

    'bidzaar' => [
        // The aggregator integration ID is issued by Bidzaar. This source only
        // reads purchases from the published API and stays off until issued.
        'enabled' => (bool) env('BIDZAAR_ENABLED', false),
        'integrator_id' => env('BIDZAAR_INTEGRATOR_ID'),
        'request_timeout_seconds' => (int) env('BIDZAAR_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('BIDZAAR_POLL_INTERVAL_SECONDS', 900),
        'user_agent' => env('BIDZAAR_USER_AGENT', 'TenderFinder/1.0 (+official tender API monitoring)'),
    ],

    'etp_region' => [
        'enabled' => (bool) env('ETP_REGION_ENABLED', false),
        'request_timeout_seconds' => (int) env('ETP_REGION_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('ETP_REGION_POLL_INTERVAL_SECONDS', 3600),
        'pages_per_poll' => (int) env('ETP_REGION_PAGES_PER_POLL', 10),
        'user_agent' => env('ETP_REGION_USER_AGENT', 'TenderFinder/1.0 (+public procurement search monitoring)'),
    ],

    'b2b_center' => [
        // The public search endpoint used by B2B-Center's website is read
        // without account credentials. This is a bounded catalog sample.
        'enabled' => (bool) env('B2B_CENTER_ENABLED', false),
        'catalog_url' => env('B2B_CENTER_CATALOG_URL', 'https://www.b2b-center.ru/market/'),
        'request_timeout_seconds' => (int) env('B2B_CENTER_REQUEST_TIMEOUT_SECONDS', 15),
        'pages_per_poll' => (int) env('B2B_CENTER_PAGES_PER_POLL', 5),
        'poll_interval_seconds' => (int) env('B2B_CENTER_POLL_INTERVAL_SECONDS', 3600),
        'user_agent' => env('B2B_CENTER_USER_AGENT', 'TenderFinder/1.0 (+public tender catalog monitoring)'),
    ],

    'platform_catalog' => [
        // Platform-specific public search pages operated by B2B-RTS.
        // Coverage is limited to the cards exposed by these pages.
        'roseltorg' => ['enabled' => (bool) env('ROSELTORG_CATALOG_ENABLED', false)],
        'rts_tender' => ['enabled' => (bool) env('RTS_TENDER_CATALOG_ENABLED', false)],
        'sber_ast_catalog' => ['enabled' => (bool) env('SBER_AST_CATALOG_ENABLED', false)],
        'pages_per_poll' => (int) env('PLATFORM_CATALOG_PAGES_PER_POLL', 5),
        'request_timeout_seconds' => (int) env('PLATFORM_CATALOG_REQUEST_TIMEOUT_SECONDS', 15),
        'poll_interval_seconds' => (int) env('PLATFORM_CATALOG_POLL_INTERVAL_SECONDS', 1800),
        'user_agent' => env('PLATFORM_CATALOG_USER_AGENT', 'TenderFinder/1.0 (+public platform catalog monitoring)'),
    ],

];
