<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'social_auth' => [
        'frontend_url' => env('FRONTEND_URL'),
        'redirect_path' => env('SOCIAL_AUTH_REDIRECT_PATH', '/login'),
    ],

    'vertex_ai' => [
        'project_id' => env('GCP_PROJECT_ID'),
        'location' => env('GCP_LOCATION', 'us-central1'),
        'credentials_path' => env('GCP_APPLICATION_CREDENTIALS'),
        'gemini_model' => env('GCP_GEMINI_MODEL', 'gemini-2.5-flash'),
        'image_model' => env('GCP_IMAGE_MODEL', 'imagen-4.0-generate-001'),
        'rate_limit_seconds' => env('ARTICLE_GENERATION_RATE_LIMIT_SECONDS', 3600),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        'models' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GEMINI_CHAT_MODELS', '')),
        ))),
    ],

];
