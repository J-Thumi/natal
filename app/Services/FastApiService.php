<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FastApiService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('telegram.fastapi_url');

        Log::info('FastApiService initialized', [
            'base_url' => $this->baseUrl,
            'configured' => !empty($this->baseUrl),
        ]);
    }

    public function chat(string $sessionId, string $content, string $channel = 'text'): array
    {
        $startTime = microtime(true);

        Log::info('FastAPI: Chat request initiated', [
            'session_id' => $sessionId,
            'channel' => $channel,
            'content_length' => strlen($content),
        ]);

        try {
            $response = Http::post("{$this->baseUrl}/chat", [
                'messages' => [
                    ['role' => 'user', 'content' => $content]
                ],
                'session_id' => $sessionId,
                'channel' => $channel,
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('FastAPI: Chat response received', [
                'session_id' => $sessionId,
                'channel' => $channel,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => $duration,
                'response_keys' => array_keys($response->json() ?? []),
                'risk_level' => $response->json('risk_level'),
                'escalate' => $response->json('escalate'),
                'language' => $response->json('language'),
            ]);

            if (!$response->successful()) {
                Log::error('FastAPI: Chat request failed', [
                    'session_id' => $sessionId,
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }

            return $response->json() ?? [];

        } catch (\Throwable $e) {
            Log::error('FastAPI: Chat request exception', [
                'session_id' => $sessionId,
                'channel' => $channel,
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return [];
        }
    }

    public function transcribe(string $audioPath, string $language = 'sw'): ?string
    {
        $startTime = microtime(true);

        Log::info('FastAPI: Transcription request initiated', [
            'audio_path' => $audioPath,
            'file_exists' => file_exists($audioPath),
            'file_size' => file_exists($audioPath) ? filesize($audioPath) : null,
            'language' => $language,
        ]);

        try {
            if (!file_exists($audioPath)) {
                Log::error('FastAPI: Audio file does not exist', [
                    'audio_path' => $audioPath,
                ]);

                return null;
            }

            $response = Http::attach(
                'audio',
                file_get_contents($audioPath),
                'audio.ogg'
            )->post("{$this->baseUrl}/transcribe", [
                'language' => $language,
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('FastAPI: Transcription response received', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => $duration,
                'language' => $language,
                'transcription_length' => strlen($response->json('text') ?? ''),
            ]);

            if (!$response->successful()) {
                Log::error('FastAPI: Transcription failed', [
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('text');

        } catch (\Throwable $e) {
            Log::error('FastAPI: Transcription exception', [
                'audio_path' => $audioPath,
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return null;
        }
    }

    public function speak(string $text, string $language = 'sw'): ?string
    {
        $startTime = microtime(true);

        Log::info('FastAPI: Text-to-speech request initiated', [
            'language' => $language,
            'text_length' => strlen($text),
        ]);

        try {
            $response = Http::post("{$this->baseUrl}/speak", [
                'text' => $text,
                'language' => $language,
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('FastAPI: Text-to-speech response received', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => $duration,
                'content_type' => $response->header('Content-Type'),
                'audio_size' => strlen($response->body()),
            ]);

            if (!$response->successful()) {
                Log::error('FastAPI: Text-to-speech request failed', [
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                return null;
            }

            // Save binary audio response temporarily and return path
            $audioPath = storage_path('app/speak_' . uniqid() . '.mp3');

            file_put_contents($audioPath, $response->body());

            Log::info('FastAPI: Audio generated and saved', [
                'audio_path' => $audioPath,
                'file_size' => filesize($audioPath),
            ]);

            return $audioPath;

        } catch (\Throwable $e) {
            Log::error('FastAPI: Text-to-speech exception', [
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return null;
        }
    }
}