<?php

return [
    'token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'fastapi_url' => env('FASTAPI_BACKEND_URL', 'http://localhost:8000'),
];