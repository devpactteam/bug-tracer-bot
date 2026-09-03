<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotService
{
    private function client(): PendingRequest
    {
        $token = (string) config('incident.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured.');
        }

        $request = Http::baseUrl(rtrim(config('incident.telegram.api_base_url'), '/')."/bot{$token}/")
            ->acceptJson()->timeout(20);

        $bridge = config('incident.telegram.proxy.http_bridge');
        if (is_string($bridge) && $bridge !== '') {
            $request = $request->withOptions(['proxy' => $bridge]);
        }

        return $request;
    }

    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): array
    {
        return $this->client()->post('sendMessage', array_filter([
            'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        ]))->throw()->json();
    }

    public function editMessage(string|int $chatId, string|int $messageId, string $text, ?array $replyMarkup = null): array
    {
        return $this->client()->post('editMessageText', array_filter([
            'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        ]))->throw()->json();
    }

    public function deleteMessage(string|int $chatId, string|int $messageId): array
    {
        return $this->client()->post('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ])->throw()->json();
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        try {
            return $this->client()
                ->post('answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text])
                ->throw()->json();
        } catch (RequestException $exception) {
            // A replayed/late callback can legitimately be rejected by Telegram.
            if ($exception->response?->status() === 400
                && str_contains((string) $exception->response?->json('description'), 'query is too old')) {
                report($exception);
                return ['ok' => false, 'description' => 'callback expired'];
            }
            throw $exception;
        }
    }

    public function previewKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ تأیید و ایجاد تیکت', 'callback_data' => "incident:approve:{$sessionId}"]],
            [['text' => '👤 تغییر مسئول', 'callback_data' => "incident:assignee:{$sessionId}"]],
            [['text' => '❌ لغو گزارش', 'callback_data' => "incident:cancel:{$sessionId}"]],
        ]];
    }

    public function finalizeKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => '🚀 نهایی‌سازی و تحلیل', 'callback_data' => "incident:finalize:{$sessionId}"]],
        ]];
    }

    public function clarificationCompleteKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ ثبت توضیحات نهایی', 'callback_data' => "incident:clarifications:{$sessionId}"]],
            [['text' => '❌ لغو گزارش', 'callback_data' => "incident:cancel:{$sessionId}"]],
        ]];
    }
}
