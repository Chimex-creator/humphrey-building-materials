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

    /*
    |--------------------------------------------------------------------------
    | Google OAuth (customer sign-in via Laravel Socialite)
    |--------------------------------------------------------------------------
    |
    | Credentials come from the environment only — never hardcode them.
    | The redirect must match, byte for byte, the Authorized Redirect URI
    | registered in Google Cloud Console.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paystack (payments)
    |--------------------------------------------------------------------------
    | The SECRET key is read from .env here and only ever used on the server.
    | Never expose it in Blade, JavaScript, HTML or error pages.
    */
    'paystack' => [
        'key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'url' => rtrim(env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'), '/'),
    ],

];
