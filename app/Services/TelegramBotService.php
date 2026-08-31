<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
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

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        return $this->client()->post('answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text])->throw()->json();
    }

    public function previewKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => 'Approve & Create Ticket', 'callback_data' => "incident:approve:{$sessionId}"]],
            [['text' => 'Change Assignee', 'callback_data' => "incident:assignee:{$sessionId}"]],
            [['text' => 'Cancel', 'callback_data' => "incident:cancel:{$sessionId}"]],
        ]];
    }

    public function finalizeKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => 'Finalize & Analyze', 'callback_data' => "incident:finalize:{$sessionId}"]],
        ]];
    }
}
