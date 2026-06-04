<?php

return [
    'gemini' => [
        'api_key'    => env('GEMINI_API_KEY'),
        'api_keys'   => array_filter(array_map('trim', explode(',', env('GEMINI_API_KEYS', env('GEMINI_API_KEY', ''))))),
        'api_url'    => env('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model_flash'=> env('GEMINI_MODEL_FLASH', 'gemini-1.5-flash'),
        'model_pro'  => env('GEMINI_MODEL_PRO',   'gemini-1.5-pro'),
        'max_rpm'    => env('AI_MAX_RPM', 14),
    ],

    'ollama' => [
        'url'   => env('OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'gemma4:latest'),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model'   => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],

    'ai' => [
        // Switch provider: "gemini", "ollama", or "groq"
        'provider' => env('AI_PROVIDER', 'gemini'),
    ],
    'midtrans' => [
        'merchant_id'   => env('MIDTRANS_MERCHANT_ID'),
        'server_key'    => env('MIDTRANS_SERVER_KEY'),
        'client_key'    => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'finish_url'    => env('MIDTRANS_FINISH_URL'),
    ],
    'apicoId' => [
        'key' => env('APICOIND_KEY', 'fSTxpl7uEQjulT2stQg4gMKIazsmbqRF2UglGTRJHLoKw3VA8H'),
    ],
    'logoDev' => [
        'token' => env('LOGODEV_TOKEN', 'pk_a1dih9BDRCmE0bDH9EgSUg'),
    ],
];
