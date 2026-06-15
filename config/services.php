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
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'social_auth' => [
        'frontend_url' => env('FRONTEND_URL'),
        'redirect_path' => env('SOCIAL_AUTH_REDIRECT_PATH', '/login'),
    ],

    'vertex_ai' => [
        'project_id' => env('GCP_PROJECT_ID'),
        'location' => env('GCP_LOCATION', 'us-central1'),
        'credentials_path' => env('VERTEX_APPLICATION_CREDENTIALS'),
        'gemini_model' => env('GCP_GEMINI_MODEL', 'gemini-2.5-flash'),
        'image_model' => env('GCP_IMAGE_MODEL', 'imagen-4.0-generate-001'),
        'rate_limit_seconds' => env('ARTICLE_GENERATION_RATE_LIMIT_SECONDS', 3600),
    ],

    'gemini' => [
        'api_key'           => env('GEMINI_API_KEY'),
        'model'             => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        'models'            => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GEMINI_CHAT_MODELS', '')),
        ))),
        'story_model'       => env('GEMINI_STORY_MODEL', 'gemini-2.5-flash'),
        'story_state_model' => env('GEMINI_STORY_STATE_MODEL', 'gemini-2.5-flash'),

        // RAG embedding（Google AI Studio）。gemini-embedding-001 為 Matryoshka，
        // 預設 3072 維，這裡用 outputDimensionality 降到 768 省儲存。
        'embedding_model'      => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'embedding_dimensions' => env('GEMINI_EMBEDDING_DIMENSIONS', 768),
    ],

    /*
    | LLM 抽象層：多 provider（gemini / nvidia / ollama）。
    | providers.*.models 供 System 設定頁的 model 下拉用；
    | uses.* 為各用途的「預設」provider+model，runtime 可由 admin_settings 覆蓋。
    */
    'llm' => [
        'providers' => [
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                'models'  => array_values(array_filter(array_map('trim', explode(
                    ',',
                    (string) env('LLM_GEMINI_MODELS', 'gemini-2.5-flash,gemini-2.5-pro'),
                )))),
            ],
            'nvidia' => [
                'api_key'  => env('NVIDIA_API_KEY'),
                'base_url' => env('NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com/v1'),
                'models'   => array_values(array_filter(array_map('trim', explode(
                    ',',
                    (string) env('LLM_NVIDIA_MODELS', 'meta/llama-3.3-70b-instruct,google/gemma-2-27b-it'),
                )))),
            ],
            'ollama' => [
                'base_url' => env('OLLAMA_HOST', 'http://localhost:11434'),
                'models'   => array_values(array_filter(array_map('trim', explode(
                    ',',
                    (string) env('LLM_OLLAMA_MODELS', 'llama3.1,qwen2.5'),
                )))),
            ],
        ],
        'uses' => [
            'story'       => ['provider' => env('LLM_STORY_PROVIDER', 'gemini'),       'model' => env('LLM_STORY_MODEL', 'gemini-2.5-flash')],
            'story_state' => ['provider' => env('LLM_STORY_STATE_PROVIDER', 'gemini'), 'model' => env('LLM_STORY_STATE_MODEL', 'gemini-2.5-flash')],
            'character'   => ['provider' => env('LLM_CHARACTER_PROVIDER', 'gemini'),   'model' => env('LLM_CHARACTER_MODEL', 'gemini-2.5-flash')],
            'chat'        => ['provider' => env('LLM_CHAT_PROVIDER', 'gemini'),        'model' => env('LLM_CHAT_MODEL', 'gemini-2.5-flash')],
        ],
    ],

    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    'ws' => [
        'allowed_origins' => env('WS_ALLOWED_ORIGINS', 'localhost:*'),
        'ws_addr'         => env('WS_ADDR',      '127.0.0.1:9001'),
        'mgmt_addr'       => env('WS_MGMT_ADDR', '127.0.0.1:9002'),
    ],

    'mcp' => [
        'version' => env('MCP_SERVER_VERSION', '1.0.0'),
    ],

    'mini_orch' => [
        'host' => env('MINI_ORCH_HOST', ''),
    ],

    'line_bot' => [
        'internal_api_key' => env('LINE_BOT_INTERNAL_API_KEY'),
        'article_ready_webhook_url' => env('LINE_BOT_ARTICLE_READY_WEBHOOK_URL'),
        'outbound_webhook_key' => env('LINE_BOT_OUTBOUND_WEBHOOK_KEY'),
        'inbound_hmac_secret' => env('LINE_BOT_INBOUND_HMAC_SECRET'),
        'outbound_hmac_secret' => env('LINE_BOT_OUTBOUND_HMAC_SECRET'),
        'hmac_required' => env('LINE_BOT_HMAC_REQUIRED', false),
        'hmac_max_skew_seconds' => env('LINE_BOT_HMAC_MAX_SKEW_SECONDS', 300),
        'about_token_daily_limit' => env('LINE_BOT_ABOUT_TOKEN_DAILY_LIMIT', 2),
        'about_token_max_uses' => env('LINE_BOT_ABOUT_TOKEN_MAX_USES', 5),
        'about_token_expires_days' => env('LINE_BOT_ABOUT_TOKEN_EXPIRES_DAYS', 7),
    ],

];
