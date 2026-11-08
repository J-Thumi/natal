<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FastApiService
{
    protected string $baseUrl;
    protected bool $mockMode;
    protected ?string $mockAudioPath;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('telegram.fastapi_url', ''), '/');
        $this->mockMode = (bool) config('telegram.fastapi_mock', false);
        $this->mockAudioPath = config('telegram.fastapi_mock_audio');

        Log::info('FastApiService initialized', [
            'mode' => $this->mockMode ? 'MOCK' : 'LIVE',
            'base_url_configured' => !empty($this->baseUrl),
            'mock_audio_configured' => !empty($this->mockAudioPath),
        ]);

        if (!$this->mockMode && empty($this->baseUrl)) {
            Log::critical('FastAPI URL is missing while mock mode is disabled.');

            throw new \RuntimeException(
                'FASTAPI_URL must be configured when FASTAPI_MOCK is disabled.'
            );
        }
    }

    public function chat(
        string $sessionId,
        string $content,
        string $channel = 'text'
    ): array {

        Log::info('FastAPI: Chat request initiated', [
            'session_id' => $sessionId,
            'channel' => $channel,
            'content_length' => strlen($content),
            'mode' => $this->mockMode ? 'MOCK' : 'LIVE',
        ]);

        // MOCK MODE
        if ($this->mockMode) {

            Log::info('FastAPI MOCK: Simulating chat response', [
                'session_id' => $sessionId,
                'channel' => $channel,
            ]);

            // Special test trigger for escalation.
            // Only available in mock mode.
            $triggerEscalation = str_contains(
                strtolower($content),
                '[mock-high-risk]'
            );

            $response = [
                'reply' => $triggerEscalation
                    ? 'Huu ni ujumbe wa majaribio wa tahadhari. Tafadhali wasiliana na mtaalamu wa afya kwa tathmini.'
                    : 'Asante kwa ujumbe wako. Mimi ni msaidizi wako wa afya wakati wa ujauzito. Naweza kukusaidia kuelewa masuala ya ujauzito, lishe na afya ya mama.',
                'escalate' => $triggerEscalation,
                'risk_level' => $triggerEscalation ? 'high' : 'low',
                'language' => 'sw',
            ];

            Log::info('FastAPI MOCK: Chat response generated', [
                'session_id' => $sessionId,
                'risk_level' => $response['risk_level'],
                'escalate' => $response['escalate'],
            ]);

            return $response;
        }

        // LIVE MODE
        $startTime = microtime(true);

        try {
            $response = Http::post("{$this->baseUrl}/chat", [
                'messages' => [
                    ['role' => 'user', 'content' => $content]
                ],
                'session_id' => $sessionId,
                'channel' => $channel,
            ]);

            Log::info('FastAPI: Chat response received', [
                'session_id' => $sessionId,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'response_keys' => array_keys($response->json() ?? []),
            ]);

            if (!$response->successful()) {
                Log::error('FastAPI: Chat request failed', [
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
            }

            return $response->json() ?? [];

        } catch (\Throwable $e) {
            Log::error('FastAPI: Chat exception', [
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return [];
        }
    }

    public function transcribe(
        string $audioPath,
        string $language = 'sw'
    ): ?string {

        Log::info('FastAPI: Transcription initiated', [
            'audio_path' => $audioPath,
            'language' => $language,
            'mode' => $this->mockMode ? 'MOCK' : 'LIVE',
        ]);

        // MOCK MODE
        if ($this->mockMode) {

            Log::info('FastAPI MOCK: Returning simulated transcription', [
                'audio_exists' => file_exists($audioPath),
            ]);

            return 'Habari, ningependa kujua jinsi ya kutunza afya yangu wakati wa ujauzito.';
        }

        // LIVE MODE
        $startTime = microtime(true);

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

            Log::info('FastAPI: Transcription response received', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
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
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return null;
        }
    }

    public function speak(
        string $text,
        string $language = 'sw'
    ): ?string {

        Log::info('FastAPI: TTS request initiated', [
            'language' => $language,
            'text_length' => strlen($text),
            'mode' => $this->mockMode ? 'MOCK' : 'LIVE',
        ]);

        // MOCK MODE
        if ($this->mockMode) {

            if (
                $this->mockAudioPath &&
                file_exists($this->mockAudioPath)
            ) {
                Log::info('FastAPI MOCK: Using local audio fixture', [
                    'audio_path' => $this->mockAudioPath,
                ]);

                return $this->mockAudioPath;
            }

            Log::warning('FastAPI MOCK: No audio fixture configured.');

            return null;
        }

        // LIVE MODE
        $startTime = microtime(true);

        try {
            $response = Http::post("{$this->baseUrl}/speak", [
                'text' => $text,
                'language' => $language,
            ]);

            Log::info('FastAPI: TTS response received', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'content_type' => $response->header('Content-Type'),
            ]);

            if (!$response->successful()) {
                Log::error('FastAPI: TTS request failed', [
                    'http_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);

                return null;
            }

            $audioPath = storage_path('app/speak_' . uniqid() . '.mp3');

            file_put_contents($audioPath, $response->body());

            Log::info('FastAPI: Audio generated', [
                'audio_path' => $audioPath,
                'file_size' => filesize($audioPath),
            ]);

            return $audioPath;

        } catch (\Throwable $e) {
            Log::error('FastAPI: TTS exception', [
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);

            return null;
        }
    }
}