<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),

    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    'fastapi_url' => env('FASTAPI_BACKEND_URL'),

    // Enable simulated FastAPI responses
    'fastapi_mock' => env('FASTAPI_MOCK', false),

    // Optional audio fixture for testing voice replies
    'fastapi_mock_audio' => env('FASTAPI_MOCK_AUDIO'),
];