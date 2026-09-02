<?php

declare(strict_types=1);

namespace App\Config;

return [
    /*
    |--------------------------------------------------------------------------
    | Master Feature Switch (Frozen in Web-Managed Subscriptions Mode)
    |--------------------------------------------------------------------------
    |
    | When disabled (default), all native store billing verification, restoration,
    | webhook ingestion, and lifecycle jobs are dormant.
    | Subscriptions and payments are completed exclusively through the Skillso website.
    |
    */
    'enabled' => env('STORE_BILLING_ENABLED', false),
    'apple_enabled' => env('STORE_BILLING_APPLE_ENABLED', false),
    'google_enabled' => env('STORE_BILLING_GOOGLE_ENABLED', false),
    'notifications_enabled' => env('STORE_BILLING_NOTIFICATIONS_ENABLED', false),
    'lifecycle_processing_enabled' => env('STORE_BILLING_LIFECYCLE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Apple App Store Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials and parameters for Apple StoreKit 2 & App Store Server API.
    | Private keys and certificates are kept exclusively on the server.
    | Optional when STORE_BILLING_ENABLED=false.
    |
    */
    'apple' => [
        'bundle_id' => env('APPLE_STORE_BUNDLE_ID', 'com.skillso.app.skillso'),
        'key_id' => env('APPLE_STORE_KEY_ID', ''),
        'issuer_id' => env('APPLE_STORE_ISSUER_ID', ''),
        'private_key' => env('APPLE_STORE_PRIVATE_KEY', ''),
        'private_key_path' => env('APPLE_STORE_PRIVATE_KEY_PATH', ''),
        'environment' => env('APPLE_STORE_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
        'notifications_enabled' => env('APPLE_STORE_NOTIFICATIONS_ENABLED', false),
        'shared_secret' => env('APPLE_STORE_SHARED_SECRET', ''),
        'production_url' => 'https://api.storekit.itunes.apple.com',
        'sandbox_url' => 'https://api.storekit-sandbox.itunes.apple.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Play Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials and parameters for Google Play Developer API (Android Publisher).
    | Optional when STORE_BILLING_ENABLED=false.
    |
    */
    'google' => [
        'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', 'com.skillso.app'),
        'service_account_path' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_PATH', ''),
        'service_account_json' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_JSON', ''),
        'environment' => env('GOOGLE_PLAY_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
        'rtdn_enabled' => env('GOOGLE_PLAY_RTDN_ENABLED', false),
        'pubsub_audience' => env('GOOGLE_PUBSUB_EXPECTED_AUDIENCE', ''),
        'pubsub_service_account' => env('GOOGLE_PUBSUB_SERVICE_ACCOUNT_EMAIL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store Product ID Mapping
    |--------------------------------------------------------------------------
    |
    | Explicit deterministic mapping between Skillso plan slugs/billing cycles
    | and Store Product IDs for Apple and Google Play.
    |
    */
    'product_map' => [
        'monthly' => [
            'plan_slug' => 'monthly',
            'apple_product_id' => env('APPLE_PRODUCT_ID_MONTHLY', 'skillso_monthly_sub'),
            'google_product_id' => env('GOOGLE_PRODUCT_ID_MONTHLY', 'skillso_monthly_sub'),
            'google_base_plan_id' => 'monthly-autorenewing',
            'billing_period' => 'P1M',
        ],
        'quarterly' => [
            'plan_slug' => 'quarterly',
            'apple_product_id' => env('APPLE_PRODUCT_ID_QUARTERLY', 'skillso_quarterly_sub'),
            'google_product_id' => env('GOOGLE_PRODUCT_ID_QUARTERLY', 'skillso_quarterly_sub'),
            'google_base_plan_id' => 'quarterly-autorenewing',
            'billing_period' => 'P3M',
        ],
        'semi_annual' => [
            'plan_slug' => 'semi_annual',
            'apple_product_id' => env('APPLE_PRODUCT_ID_SEMI_ANNUAL', 'skillso_semi_annual_sub'),
            'google_product_id' => env('GOOGLE_PRODUCT_ID_SEMI_ANNUAL', 'skillso_semi_annual_sub'),
            'google_base_plan_id' => 'semi-annual-autorenewing',
            'billing_period' => 'P6M',
        ],
        'yearly' => [
            'plan_slug' => 'yearly',
            'apple_product_id' => env('APPLE_PRODUCT_ID_YEARLY', 'skillso_yearly_sub'),
            'google_product_id' => env('GOOGLE_PRODUCT_ID_YEARLY', 'skillso_yearly_sub'),
            'google_base_plan_id' => 'yearly-autorenewing',
            'billing_period' => 'P1Y',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook & Event Processing Settings
    |--------------------------------------------------------------------------
    */
    'webhook_retry_limit' => (int) env('STORE_WEBHOOK_RETRY_LIMIT', 3),

    /*
    |--------------------------------------------------------------------------
    | Mock Verification Driver (For Testing & Staging without Live Credentials)
    |--------------------------------------------------------------------------
    |
    | When enabled, allows structured testing payloads with valid signatures
    | or mock verification without requiring active Apple / Google live API keys.
    |
    */
    'mock_verification_enabled' => env('STORE_BILLING_MOCK_ENABLED', false),
];
