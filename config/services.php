<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Bearer token that consumers (e.g. the id portal, ID-13) present to read
    // the machine-readable status endpoint. Unset = endpoint disabled.
    'status_endpoint' => [
        'token' => env('STATUS_ENDPOINT_TOKEN'),
    ],

    // id SSO (OAuth2 authorization-code). The only way to sign in (STAT-7).
    'id' => [
        'base_url' => rtrim((string) env('ID_BASE_URL', 'https://id.thijssensoftware.nl'), '/'),
        'client_id' => env('ID_CLIENT_ID'),
        'client_secret' => env('ID_CLIENT_SECRET'),
        'redirect_uri' => env('ID_REDIRECT_URI'),
        'slug' => env('ID_APP_SLUG', 'status'),
        'portal_cache_ttl' => (int) env('ID_PORTAL_TTL', 300),
    ],

];
