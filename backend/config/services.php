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

    // Cloudflare Turnstile untuk formulir daftar (opsional; kosong = nonaktif).
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

    // Gateway WhatsApp untuk OTP & notifikasi (opsional; kosong = hanya dicatat ke log).
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER'), // fonnte | null
        'url' => env('WHATSAPP_URL', 'https://api.fonnte.com/send'),
        'token' => env('WHATSAPP_TOKEN'),
    ],

    // Pemindai virus unggahan (opsional): ClamAV clamd lewat unix socket atau host:port.
    'clamav' => [
        'socket' => env('CLAMAV_SOCKET'), // mis. unix:///var/run/clamav/clamd.ctl atau tcp://127.0.0.1:3310
        'fail_closed' => env('CLAMAV_FAIL_CLOSED', true),
    ],

];
