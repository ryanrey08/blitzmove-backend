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

    // Optional integrations. Leave empty until a provider and its credentials are set up.
    'maps' => [
        'provider' => env('MAP_PROVIDER'),
    ],

    'routing' => [
        'provider' => env('ROUTING_PROVIDER'),
    ],

    // Secret token: used only server-side (geocoding, directions, optimization, static map images).
    'mapbox' => [
        'token' => env('MAPBOX_SECRET_TOKEN'),
        'country' => env('MAPBOX_COUNTRY', 'ph'),
        // "lng,lat" that geocoding results are biased towards (default: Metro Manila).
        'proximity' => env('MAPBOX_PROXIMITY', '121.0,14.6'),
    ],

];
