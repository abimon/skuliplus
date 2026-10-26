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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'africas_talking' => [
        'username' => env('AFRICASTALKING_USERNAME'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
        'endpoint' => env('AFRICASTALKING_ENDPOINT', 'https://api.africastalking.com/version1/messaging'),
    ],

    'sautikit' => [
        'base_url' => env('SAUTIKIT_BASE_URL', 'https://api.sautikit.com'),
        'api_key' => env('SAUTIKIT_API_KEY'),
        'from_number' => env('SAUTIKIT_FROM_NUMBER'),
        'webhook_secret' => env('SAUTIKIT_WEBHOOK_SECRET'),
    ],

    'schoolms' => [
        'inquiry_email' => env('SCHOOLMS_INQUIRY_EMAIL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
