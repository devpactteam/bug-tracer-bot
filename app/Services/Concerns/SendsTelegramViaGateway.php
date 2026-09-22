<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Http;

trait SendsTelegramViaGateway
{
    /**
     * Send a plain Telegram message through the intermediary gateway.
     *
     * The gateway owns the connection to Telegram, so the application server
     * does not call api.telegram.org for this operation.
     */
    private function sendTelegram(string $message, string|int|null $channel = null, ?string $botKey = null): bool
    {
        if (config('app.debug')) {
            return true;
        }

        try {
            $channelId = $channel ?: env('TELE_CHAT_ID_LOG');
            $botKey ??= env('TELEGRAM_KEY_LOG');

            if (! $channelId || ! $botKey) {
                return false;
            }

            return Http::timeout(10)
                ->get((string) config('incident.telegram.gateway_url'), [
                    'token' => $botKey,
                    'chatId' => $channelId,
                    'text' => $message,
                ])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
