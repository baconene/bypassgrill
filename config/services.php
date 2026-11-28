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

    'store_weather' => [
        'key' => env('VISUAL_CROSSING_API_KEY'),
        'coordinates' => env('STORE_WEATHER_COORDINATES', '14.185367,121.509690'),
        'location' => env('STORE_WEATHER_LOCATION', 'Luisiana, Laguna'),
        'opens_hour' => env('STORE_WEATHER_OPENS_HOUR', 15),
        'closes_hour' => env('STORE_WEATHER_CLOSES_HOUR', 22),
        // level: recent sales level x weekday pattern, used when its back-test is at least as accurate as the
        // weekday average (it follows a rising or falling trend, so it over-forecasts less).
        // baseline: always the plain weekday average. auto: the tuned model when it clearly beats the average.
        'forecast_mode' => env('FORECAST_MODE', 'level'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
