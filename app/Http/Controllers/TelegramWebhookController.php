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
    ) {}

    public function handle(Request $request)
    {
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (config('telegram.webhook_secret') && $secretToken !== config('telegram.webhook_secret')) {
            Log::warning('Unauthorized Telegram webhook attempt.');
            return response()->json(['status' => 'unauthorized'], 403);
        }

        $update = $request->all();
        Log::info('Telegram webhook received', $update);

        // 1. Handle Inline Keyboard Button Clicks (Confirming or Cancelling Transcription)
        if (isset($update['callback_query'])) {
            $callback = $update['callback_query'];
            $chatId = $callback['message']['chat']['id'];
            $messageId = $callback['message']['message_id'];
            $data = $callback['data'];
            $sessionId = "tg-{$chatId}";

            // Answer callback query to remove loading spinner on button
            $this->telegram->answerCallbackQuery($callback['id']);

            if ($data === 'confirm_voice') {
                // Retrieve transcribed text stored temporarily in cache
                $transcribedText = Cache::get("pending_voice_{$chatId}");

                if (!$transcribedText) {
                    $this->telegram->sendMessage($chatId, "Muda wa ujumbe huu ujaisha. Tafadhali rudia kutuma sauti.");
                    return response()->json(['status' => 'success']);
                }

                // Clear cache and delete confirmation prompt message
                Cache::forget("pending_voice_{$chatId}");
                $this->telegram->deleteMessage($chatId, $messageId);

                // Send confirmation back to user and query FastAPI
                $this->telegram->sendMessage($chatId, "✅ *Ujumbe umetumwa:* \"{$transcribedText}\"");
                $apiResponse = $this->fastApi->chat($sessionId, $transcribedText, 'voice');
                $this->processApiResponse($chatId, $apiResponse, replyAsVoice: true);
            } 
            elseif ($data === 'edit_voice') {
                Cache::forget("pending_voice_{$chatId}");
                $this->telegram->deleteMessage($chatId, $messageId);
                
                // Instruct user to type instead
                $this->telegram->sendMessage($chatId, "Tafadhaliandika swali lako moja kwa moja kwenye ujumbe wa maandishi.");
            }
            elseif ($data === 'cancel_voice') {
                Cache::forget("pending_voice_{$chatId}");
                $this->telegram->deleteMessage($chatId, $messageId);
                $this->telegram->sendMessage($chatId, "Ujumbe umeghairiwa.");
            }

            return response()->json(['status' => 'success']);
        }

        // 2. Handle Incoming Text Messages
        if (isset($update['message']['text'])) {
            $chatId = $update['message']['chat']['id'];
            $text = $update['message']['text'];
            $sessionId = "tg-{$chatId}";

            if ($text === '/start') {
                $welcomeText = "Habari! Karibu. Mimi ni msaidizi wako wa afya wakati wa ujauzito. Unaweza kuuliza swali kwa kuandika au kutuma ujumbe wa sauti 🎙️.";
                $this->telegram->sendMessage($chatId, $welcomeText);
                return response()->json(['status' => 'success']);
            }

            // Send standard text to FastAPI chat endpoint
            $apiResponse = $this->fastApi->chat($sessionId, $text, 'text');
            $this->processApiResponse($chatId, $apiResponse);
        }

        // 3. Handle Incoming Voice Notes (.ogg)
        elseif (isset($update['message']['voice'])) {
            $chatId = $update['message']['chat']['id'];
            $fileId = $update['message']['voice']['file_id'];

            // Download audio from Telegram
            $localAudioPath = $this->telegram->downloadFile($fileId);
            if (!$localAudioPath) {
                $this->telegram->sendMessage($chatId, 'Samahani, hatukuweza kusikiliza sauti yako. Tafadhali jaribu tena.');
                return response()->json(['status' => 'error']);
            }

            // Transcribe using FastAPI /transcribe endpoint
            $transcribedText = $this->fastApi->transcribe($localAudioPath, 'sw');
            @unlink($localAudioPath); // Cleanup temp file

            if (!$transcribedText) {
                $this->telegram->sendMessage($chatId, 'Samahani, hatukuweza kuelewa ujumbe wako wa sauti.');
                return response()->json(['status' => 'error']);
            }

            // Save transcribed text temporarily in Cache (valid for 5 minutes)
            Cache::put("pending_voice_{$chatId}", $transcribedText, now()->addMinutes(5));

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

            $this->telegram->sendMessage($chatId, $promptText, $inlineKeyboard);
        }

        return response()->json(['status' => 'success']);
    }

    protected function processApiResponse($chatId, array $apiResponse, bool $replyAsVoice = false): void
    {
        $replyText = $apiResponse['reply'] ?? 'Samahani, kumetokea tatizo la kiufundi.';
        $isEscalated = $apiResponse['escalate'] ?? false;
        $riskLevel = $apiResponse['risk_level'] ?? 'low';
        $language = $apiResponse['language'] ?? 'sw';

        // Send text response
        $this->telegram->sendMessage($chatId, $replyText);

        // Handle risk escalation protocols
        if ($isEscalated || strtolower($riskLevel) === 'high') {
            $emergencyMsg = "⚠️ *Tahadhari ya Kiafya:* Hali uliyoeleza inaonyesha dalili zinazoweza kuhitaji uangalizi wa haraka wa daktari. Tafadhali fika katika kituo cha afya kilicho karibu nawe mara moja.";
            $this->telegram->sendMessage($chatId, $emergencyMsg);
        }

        // If it was a voice conversation, reply back with a synthesized voice note (TTS)
        if ($replyAsVoice) {
            $audioSpeechPath = $this->fastApi->speak($replyText, $language);
            if ($audioSpeechPath && file_exists($audioSpeechPath)) {
                $this->telegram->sendVoice($chatId, $audioSpeechPath);
                @unlink($audioSpeechPath);
            }
        }
    }
}