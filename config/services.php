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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'wb_search' => [
        'url' => env('WB_SEARCH_BASE_URL', 'http://127.0.0.1:3001'),
        'token' => env('WB_SEARCH_BASE_TOKEN'),
    ],
    'proxy' => env('PROXY'),
    'gpt' => [
        'key' => env('APP_GPT_KEY'),
        'model' => env('APP_GPT_MODEL', 'gpt-4.1'),
        'base_url' => env('APP_GPT_BASE_URL', 'https://api.openai.com'),
    ],
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'api_version' => env('GEMINI_API_VERSION', 'v1beta'),
        'pro_model' => env('GEMINI_PRO_MODEL', 'gemini-3.1-pro-preview'),
        'image_model' => env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image-preview'),
    ],
    'grok' => [
        'api_key' => env('GROK_API_KEY'),
        'base_url' => env('GROK_BASE_URL', 'https://api.x.ai'),
        'video_model' => env('GROK_VIDEO_MODEL', 'grok-imagine-video'),
        'image_model' => env('GROK_IMAGE_MODEL', 'grok-imagine-image-quality'),
    ],
    'ai_media' => [
        'disk' => env('AI_MEDIA_DISK', 'private'),
        'image_prefix' => env('AI_MEDIA_IMAGE_PREFIX', 'ai/source-images'),
        'video_prefix' => env('AI_MEDIA_VIDEO_PREFIX', 'ai/generated-videos'),
        'max_image_bytes' => (int) env('AI_MEDIA_MAX_IMAGE_BYTES', 10485760),
        'max_video_bytes' => (int) env('AI_MEDIA_MAX_VIDEO_BYTES', 104857600),
    ],
    'blog_media' => [
        'public_base_path' => env('BLOG_MEDIA_PUBLIC_BASE_PATH', '/media'),
        'allowed_prefix' => env('BLOG_MEDIA_ALLOWED_PREFIX', 'blog/images/'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID', null),
        'secret_key' => env('YOOKASSA_SECRET_KEY', null)
    ],

    'vk' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect_uri' => env('VK_REDIRECT_URI'),
    ],

    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect_uri' => env('YANDEX_REDIRECT_URI'),
    ],
];
