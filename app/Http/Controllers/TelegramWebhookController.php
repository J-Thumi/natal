<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\TelegramService;
use App\Services\FastApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegram,
        protected FastApiService $fastApi
    ) {
        Log::info('TelegramWebhookController initialized');
    }

    public function handle(Request $request)
    {
        $webhookStartTime = microtime(true);

        Log::info('Telegram webhook: Incoming request', [
            'ip' => $request->ip(),
            'method' => $request->method(),
            'content_length' => $request->header('Content-Length'),
            'user_agent' => $request->userAgent(),
            'has_secret_header' => $request->hasHeader('X-Telegram-Bot-Api-Secret-Token'),
        ]);

        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (config('telegram.webhook_secret') && $secretToken !== config('telegram.webhook_secret')) {
            Log::warning('Telegram webhook: Unauthorized request rejected', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'unauthorized'], 403);
        }

        Log::info('Telegram webhook: Authentication successful');

        $update = $request->all();

        Log::info('Telegram webhook: Update received', [
            'update_id' => $update['update_id'] ?? null,
            'update_type' => isset($update['callback_query'])
                ? 'callback_query'
                : (isset($update['message']['voice'])
                    ? 'voice'
                    : (isset($update['message']['text']) ? 'text' : 'unknown')),
        ]);

        // 1. Handle Inline Keyboard Button Clicks
        if (isset($update['callback_query'])) {
            $callback = $update['callback_query'];
            $chatId = $callback['message']['chat']['id'];
            $messageId = $callback['message']['message_id'];
            $data = $callback['data'];
            $sessionId = "tg-{$chatId}";

            Log::info('Telegram webhook: Callback query received', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'callback_query_id' => $callback['id'],
                'callback_data' => $data,
                'session_id' => $sessionId,
            ]);

            // Answer callback query to remove loading spinner
            $this->telegram->answerCallbackQuery($callback['id']);

            if ($data === 'confirm_voice') {
                Log::info('Telegram webhook: Voice confirmation requested', [
                    'chat_id' => $chatId,
                ]);

                // Retrieve transcribed text stored temporarily in cache
                $transcribedText = Cache::get("pending_voice_{$chatId}");

                Log::info('Telegram webhook: Pending voice cache lookup', [
                    'chat_id' => $chatId,
                    'found' => !empty($transcribedText),
                ]);

                if (!$transcribedText) {
                    Log::warning('Telegram webhook: Pending voice transcription expired', [
                        'chat_id' => $chatId,
                    ]);

                    $this->telegram->sendMessage(
                        $chatId,
                        "Muda wa ujumbe huu ujaisha. Tafadhali rudia kutuma sauti."
                    );

                    return response()->json(['status' => 'success']);
                }

                // Clear cache and delete confirmation prompt message
                Cache::forget("pending_voice_{$chatId}");

                Log::info('Telegram webhook: Pending voice cache cleared', [
                    'chat_id' => $chatId,
                ]);

                $this->telegram->deleteMessage($chatId, $messageId);

                // Send confirmation back to user and query FastAPI
                Log::info('Telegram webhook: Sending confirmed transcription to FastAPI', [
                    'chat_id' => $chatId,
                    'transcription_length' => strlen($transcribedText),
                ]);

                $this->telegram->sendMessage(
                    $chatId,
                    "✅ *Ujumbe umetumwa:* \"{$transcribedText}\""
                );

                $apiResponse = $this->fastApi->chat($sessionId, $transcribedText, 'voice');

                Log::info('Telegram webhook: FastAPI voice chat completed', [
                    'chat_id' => $chatId,
                    'response_keys' => array_keys($apiResponse),
                ]);

                $this->processApiResponse($chatId, $apiResponse, replyAsVoice: true);
            }
            elseif ($data === 'edit_voice') {
                Log::info('Telegram webhook: Voice transcription edit requested', [
                    'chat_id' => $chatId,
                ]);

                Cache::forget("pending_voice_{$chatId}");

                $this->telegram->deleteMessage($chatId, $messageId);

                $this->telegram->sendMessage(
                    $chatId,
                    "Tafadhaliandika swali lako moja kwa moja kwenye ujumbe wa maandishi."
                );
            }
            elseif ($data === 'cancel_voice') {
                Log::info('Telegram webhook: Voice transcription cancellation requested', [
                    'chat_id' => $chatId,
                ]);

                Cache::forget("pending_voice_{$chatId}");

                $this->telegram->deleteMessage($chatId, $messageId);

                $this->telegram->sendMessage($chatId, "Ujumbe umeghairiwa.");
            }

            Log::info('Telegram webhook: Callback processing completed', [
                'chat_id' => $chatId,
                'callback_data' => $data,
                'duration_ms' => round((microtime(true) - $webhookStartTime) * 1000, 2),
            ]);

            return response()->json(['status' => 'success']);
        }

        // 2. Handle Incoming Text Messages
        if (isset($update['message']['text'])) {
            $chatId = $update['message']['chat']['id'];
            $text = $update['message']['text'];
            $sessionId = "tg-{$chatId}";

            Log::info('Telegram webhook: Text message received', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
                'text_length' => strlen($text),
                'is_start_command' => $text === '/start',
            ]);

            if ($text === '/start') {
                Log::info('Telegram webhook: Start command received', [
                    'chat_id' => $chatId,
                ]);

                $welcomeText = "Habari! Karibu. Mimi ni msaidizi wako wa afya wakati wa ujauzito. Unaweza kuuliza swali kwa kuandika au kutuma ujumbe wa sauti 🎙️.";

                $this->telegram->sendMessage($chatId, $welcomeText);

                return response()->json(['status' => 'success']);
            }

            // Send standard text to FastAPI chat endpoint
            Log::info('Telegram webhook: Forwarding text message to FastAPI', [
                'chat_id' => $chatId,
                'session_id' => $sessionId,
            ]);

            $apiResponse = $this->fastApi->chat($sessionId, $text, 'text');

            Log::info('Telegram webhook: FastAPI text chat completed', [
                'chat_id' => $chatId,
                'response_keys' => array_keys($apiResponse),
            ]);

            $this->processApiResponse($chatId, $apiResponse);
        }

        // 3. Handle Incoming Voice Notes
        elseif (isset($update['message']['voice'])) {
            $chatId = $update['message']['chat']['id'];
            $fileId = $update['message']['voice']['file_id'];

            Log::info('Telegram webhook: Voice message received', [
                'chat_id' => $chatId,
                'file_id' => $fileId,
                'duration' => $update['message']['voice']['duration'] ?? null,
                'file_size' => $update['message']['voice']['file_size'] ?? null,
                'mime_type' => $update['message']['voice']['mime_type'] ?? null,
            ]);

            // Download audio from Telegram
            Log::info('Telegram webhook: Starting voice download', [
                'chat_id' => $chatId,
                'file_id' => $fileId,
            ]);

            $localAudioPath = $this->telegram->downloadFile($fileId);

            if (!$localAudioPath) {
                Log::error('Telegram webhook: Voice download failed', [
                    'chat_id' => $chatId,
                    'file_id' => $fileId,
                ]);

                $this->telegram->sendMessage(
                    $chatId,
                    'Samahani, hatukuweza kusikiliza sauti yako. Tafadhali jaribu tena.'
                );

                return response()->json(['status' => 'error']);
            }

            Log::info('Telegram webhook: Voice downloaded successfully', [
                'chat_id' => $chatId,
                'local_path' => $localAudioPath,
            ]);

            // Transcribe using FastAPI /transcribe endpoint
            $transcribedText = $this->fastApi->transcribe($localAudioPath, 'sw');

            // Cleanup temp file
            $fileDeleted = @unlink($localAudioPath);

            Log::info('Telegram webhook: Temporary audio cleanup completed', [
                'chat_id' => $chatId,
                'local_path' => $localAudioPath,
                'deleted' => $fileDeleted,
            ]);

            if (!$transcribedText) {
                Log::error('Telegram webhook: Transcription returned empty result', [
                    'chat_id' => $chatId,
                ]);

                $this->telegram->sendMessage(
                    $chatId,
                    'Samahani, hatukuweza kuelewa ujumbe wako wa sauti.'
                );

                return response()->json(['status' => 'error']);
            }

            Log::info('Telegram webhook: Voice transcription successful', [
                'chat_id' => $chatId,
                'transcription_length' => strlen($transcribedText),
            ]);

            // Save transcribed text temporarily in Cache (valid for 5 minutes)
            Cache::put(
                "pending_voice_{$chatId}",
                $transcribedText,
                now()->addMinutes(5)
            );

            Log::info('Telegram webhook: Transcription stored in cache', [
                'chat_id' => $chatId,
                'expires_in_minutes' => 5,
            ]);

            // Send confirmation prompt with Inline Keyboards
            $promptText = "🗣️ *Ulichosema:* \n\"_{$transcribedText}_\"\n\nJe, ungependa kutuma ujumbe huu au kuandika upya?";

            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Tuma', 'callback_data' => 'confirm_voice'],
                        ['text' => '✏️ Andika Badala Yake', 'callback_data' => 'edit_voice']
                    ],
                    [
                        ['text' => '❌ Futa', 'callback_data' => 'cancel_voice']
                    ]
                ]
            ];

            Log::info('Telegram webhook: Sending voice confirmation prompt', [
                'chat_id' => $chatId,
            ]);

            $this->telegram->sendMessage($chatId, $promptText, $inlineKeyboard);
        }

        Log::info('Telegram webhook: Request processing completed', [
            'update_id' => $update['update_id'] ?? null,
            'duration_ms' => round((microtime(true) - $webhookStartTime) * 1000, 2),
        ]);

        return response()->json(['status' => 'success']);
    }

    protected function processApiResponse($chatId, array $apiResponse, bool $replyAsVoice = false): void
    {
        Log::info('Telegram webhook: Processing FastAPI response', [
            'chat_id' => $chatId,
            'response_keys' => array_keys($apiResponse),
            'reply_as_voice' => $replyAsVoice,
        ]);

        $replyText = $apiResponse['reply'] ?? 'Samahani, kumetokea tatizo la kiufundi.';
        $isEscalated = $apiResponse['escalate'] ?? false;
        $riskLevel = $apiResponse['risk_level'] ?? 'low';
        $language = $apiResponse['language'] ?? 'sw';

        Log::info('Telegram webhook: FastAPI response details', [
            'chat_id' => $chatId,
            'reply_length' => strlen($replyText),
            'escalate' => $isEscalated,
            'risk_level' => $riskLevel,
            'language' => $language,
        ]);

        // Send text response
        Log::info('Telegram webhook: Sending text response to user', [
            'chat_id' => $chatId,
        ]);

        $this->telegram->sendMessage($chatId, $replyText);

        // Handle risk escalation protocols
        if ($isEscalated || strtolower($riskLevel) === 'high') {
            Log::warning('Telegram webhook: High-risk response detected; triggering escalation', [
                'chat_id' => $chatId,
                'escalate' => $isEscalated,
                'risk_level' => $riskLevel,
            ]);

            $emergencyMsg = "⚠️ *Tahadhari ya Kiafya:* Hali uliyoeleza inaonyesha dalili zinazoweza kuhitaji uangalizi wa haraka wa daktari. Tafadhali fika katika kituo cha afya kilicho karibu nawe mara moja.";

            $this->telegram->sendMessage($chatId, $emergencyMsg);
        }

        // If it was a voice conversation, reply back with synthesized voice
        if ($replyAsVoice) {
            Log::info('Telegram webhook: Generating voice response', [
                'chat_id' => $chatId,
                'language' => $language,
                'reply_length' => strlen($replyText),
            ]);

            $audioSpeechPath = $this->fastApi->speak($replyText, $language);

            if ($audioSpeechPath && file_exists($audioSpeechPath)) {
                Log::info('Telegram webhook: Generated audio available; sending voice response', [
                    'chat_id' => $chatId,
                    'audio_path' => $audioSpeechPath,
                    'file_size' => filesize($audioSpeechPath),
                ]);

                $this->telegram->sendVoice($chatId, $audioSpeechPath);

                $fileDeleted = @unlink($audioSpeechPath);

                Log::info('Telegram webhook: Generated audio cleanup completed', [
                    'chat_id' => $chatId,
                    'audio_path' => $audioSpeechPath,
                    'deleted' => $fileDeleted,
                ]);
            } else {
                Log::warning('Telegram webhook: Voice response generation failed or audio file missing', [
                    'chat_id' => $chatId,
                    'audio_path' => $audioSpeechPath,
                ]);
            }
        }

        Log::info('Telegram webhook: FastAPI response processing completed', [
            'chat_id' => $chatId,
            'reply_as_voice' => $replyAsVoice,
            'escalated' => $isEscalated || strtolower($riskLevel) === 'high',
        ]);
    }
}