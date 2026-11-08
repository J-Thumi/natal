<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TelegramService
{
    protected string $token;
    protected string $baseUrl;

    public function __construct()
    {
        $this->token = config('telegram.token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->token}";
    }

    /**
     * Send a text reply back to the Telegram chat.
     */
    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        Http::post("{$this->baseUrl}/sendMessage", $payload);
    }

    /**
     * Send an audio/voice reply back to the Telegram chat.
     */
    public function sendVoice(string|int $chatId, string $audioPath, ?string $caption = null): void
    {
        Http::attach(
            'voice', file_get_contents($audioPath), 'voice_response.ogg'
        )->post("{$this->baseUrl}/sendVoice", [
            'chat_id' => $chatId,
            'caption' => $caption,
        ]);
    }

    /**
     * Get file path from Telegram and download the file locally.
     */
    public function downloadFile(string $fileId): ?string
    {
        // 1. Get file path from Telegram
        $response = Http::get("{$this->baseUrl}/getFile", ['file_id' => $fileId]);
        if (!$response->successful()) {
            return null;
        }

        $filePath = $response->json('result.file_path');
        $fileUrl = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";

        // 2. Download content and store locally temporarily
        $audioContents = Http::get($fileUrl)->body();
        $localFileName = 'temp_' . uniqid() . '.ogg';
        Storage::disk('local')->put($localFileName, $audioContents);

        return Storage::disk('local')->path($localFileName);
    }

    /**
     * Acknowledge/Answer callback query so the loading spinner on Telegram buttons stops.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        Http::post("{$this->baseUrl}/answerCallbackQuery", [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ]);
    }

    /**
     * Delete a specific message from chat.
     */
    public function deleteMessage(string|int $chatId, int $messageId): void
    {
        Http::post("{$this->baseUrl}/deleteMessage", [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }
}