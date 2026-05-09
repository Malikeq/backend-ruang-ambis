<?php

return [
    'gemini' => [
        'api_key'    => env('GEMINI_API_KEY'),
        'api_url'    => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model_flash'=> env('GEMINI_MODEL_FLASH', 'gemini-1.5-flash'),
        'model_pro'  => env('GEMINI_MODEL_PRO',   'gemini-1.5-pro'),
        'max_rpm'    => env('AI_MAX_RPM', 14),
    ],
    'midtrans' => [
        'server_key'    => env('MIDTRANS_SERVER_KEY'),
        'client_key'    => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    ],
    'apicoId' => [
        'key' => env('APICOIND_KEY', 'fSTxpl7uEQjulT2stQg4gMKIazsmbqRF2UglGTRJHLoKw3VA8H'),
    ],
    'logoDev' => [
        'token' => env('LOGODEV_TOKEN', 'pk_a1dih9BDRCmE0bDH9EgSUg'),
    ],
];
