<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FastApiService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('telegram.fastapi_url');
    }

    public function chat(string $sessionId, string $content, string $channel = 'text'): array
    {
        $response = Http::post("{$this->baseUrl}/chat", [
            'messages' => [
                ['role' => 'user', 'content' => $content]
            ],
            'session_id' => $sessionId,
            'channel' => $channel,
        ]);

        return $response->json();
    }

    public function transcribe(string $audioPath, string $language = 'sw'): ?string
    {
        $response = Http::attach(
            'audio', file_get_contents($audioPath), 'audio.ogg'
        )->post("{$this->baseUrl}/transcribe", [
            'language' => $language,
        ]);

        return $response->successful() ? $response->json('text') : null;
    }

    public function speak(string $text, string $language = 'sw'): ?string
    {
        $response = Http::post("{$this->baseUrl}/speak", [
            'text' => $text,
            'language' => $language,
        ]);

        if (!$response->successful()) {
            return null;
        }

        // Save binary audio response temporarily and return path
        $audioPath = storage_path('app/speak_' . uniqid() . '.mp3');
        file_put_contents($audioPath, $response->body());

        return $audioPath;
    }
}