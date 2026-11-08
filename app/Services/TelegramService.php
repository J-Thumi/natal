<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $token;
    protected string $baseUrl;

    public function __construct()
    {
        $this->token = config('telegram.token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}";

        Log::info('TelegramService initialized', [
            'token_configured' => !empty($this->token),
        ]);
    }

    /**
     * Send a text reply back to the Telegram chat.
     */
    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): void
    {
        Log::info('Telegram: Sending message', [
            'chat_id' => $chatId,
            'text_length' => strlen($text),
            'has_reply_markup' => !empty($replyMarkup),
        ]);

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = json_encode($replyMarkup);
            }

            $response = Http::post("{$this->baseUrl}/sendMessage", $payload);

            Log::info('Telegram: Message API response', [
                'chat_id' => $chatId,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'telegram_ok' => $response->json('ok'),
                'description' => $response->json('description'),
                'message_id' => $response->json('result.message_id'),
            ]);

            if (!$response->successful()) {
                Log::error('Telegram: Failed to send message', [
                    'chat_id' => $chatId,
                    'response' => $response->json(),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Telegram: Exception while sending message', [
                'chat_id' => $chatId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Send an audio/voice reply back to the Telegram chat.
     */
    public function sendVoice(string|int $chatId, string $audioPath, ?string $caption = null): void
    {
        Log::info('Telegram: Sending voice message', [
            'chat_id' => $chatId,
            'audio_path' => $audioPath,
            'file_exists' => file_exists($audioPath),
            'file_size' => file_exists($audioPath) ? filesize($audioPath) : null,
            'caption_length' => strlen($caption ?? ''),
        ]);

        try {
            if (!file_exists($audioPath)) {
                Log::error('Telegram: Voice file does not exist', [
                    'chat_id' => $chatId,
                    'audio_path' => $audioPath,
                ]);

                return;
            }

            $response = Http::attach(
                'voice',
                file_get_contents($audioPath),
                'voice_response.ogg'
            )->post("{$this->baseUrl}/sendVoice", [
                'chat_id' => $chatId,
                'caption' => $caption,
            ]);

            Log::info('Telegram: Voice API response', [
                'chat_id' => $chatId,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'telegram_ok' => $response->json('ok'),
                'description' => $response->json('description'),
                'message_id' => $response->json('result.message_id'),
            ]);

            if (!$response->successful()) {
                Log::error('Telegram: Failed to send voice message', [
                    'chat_id' => $chatId,
                    'response' => $response->json(),
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('Telegram: Exception while sending voice', [
                'chat_id' => $chatId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Get file path from Telegram and download the file locally.
     */
    public function downloadFile(string $fileId): ?string
    {
        Log::info('Telegram: Starting file download', [
            'file_id' => $fileId,
        ]);

        try {
            // 1. Get file path from Telegram
            $response = Http::get("{$this->baseUrl}/getFile", [
                'file_id' => $fileId,
            ]);

            Log::info('Telegram: getFile response', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'telegram_ok' => $response->json('ok'),
                'description' => $response->json('description'),
            ]);

            if (!$response->successful()) {
                Log::error('Telegram: Unable to retrieve file path', [
                    'file_id' => $fileId,
                    'response' => $response->json(),
                ]);

                return null;
            }

            $filePath = $response->json('result.file_path');

            Log::info('Telegram: File path retrieved', [
                'file_id' => $fileId,
                'file_path' => $filePath,
            ]);

            $fileUrl = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";

            // 2. Download content and store locally temporarily
            $audioResponse = Http::get($fileUrl);

            if (!$audioResponse->successful()) {
                Log::error('Telegram: Audio download failed', [
                    'file_id' => $fileId,
                    'http_status' => $audioResponse->status(),
                ]);

                return null;
            }

            $audioContents = $audioResponse->body();

            $localFileName = 'temp_' . uniqid() . '.ogg';

            Storage::disk('local')->put($localFileName, $audioContents);

            $localPath = Storage::disk('local')->path($localFileName);

            Log::info('Telegram: Audio downloaded successfully', [
                'file_id' => $fileId,
                'local_path' => $localPath,
                'file_size' => strlen($audioContents),
            ]);

            return $localPath;

        } catch (\Throwable $e) {
            Log::error('Telegram: Exception during file download', [
                'file_id' => $fileId,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return null;
        }
    }

    /**
     * Acknowledge/Answer callback query so the loading spinner on Telegram buttons stops.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        Log::info('Telegram: Answering callback query', [
            'callback_query_id' => $callbackQueryId,
            'has_text' => !empty($text),
        ]);

        try {
            $response = Http::post("{$this->baseUrl}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]);

            Log::info('Telegram: Callback acknowledgement response', [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'telegram_ok' => $response->json('ok'),
            ]);

        } catch (\Throwable $e) {
            Log::error('Telegram: Callback acknowledgement failed', [
                'callback_query_id' => $callbackQueryId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delete a specific message from chat.
     */
    public function deleteMessage(string|int $chatId, int $messageId): void
    {
        Log::info('Telegram: Deleting message', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);

        try {
            $response = Http::post("{$this->baseUrl}/deleteMessage", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            Log::info('Telegram: Delete message response', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'telegram_ok' => $response->json('ok'),
            ]);

        } catch (\Throwable $e) {
            Log::error('Telegram: Delete message exception', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}