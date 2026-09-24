<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

trait SendsTelegramViaGateway
{
    private function sendTelegram(
        string $message,
        string|int|null $channel = null,
        ?string $botKey = null,
    ): bool {
        return (bool) ($this->sendTelegramMessage($message, $channel, $botKey)['ok'] ?? false);
    }

    private function sendTelegramMessage(
        string $message,
        string|int|null $channel = null,
        ?string $botKey = null,
        ?array $replyMarkup = null,
    ): array {
        if (config('app.debug')) {
            return ['ok' => true, 'result' => []];
        }

        $channelId = $channel ?: env('TELE_CHAT_ID_LOG');
        $botKey ??= env('TELEGRAM_KEY_LOG');
        if (! $channelId || ! $botKey) {
            return ['ok' => false, 'result' => []];
        }

        try {
            return $this->telegramGatewayJson((string) config('incident.telegram.gateway_url'), [
                'token' => $botKey,
                'chatId' => $channelId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => $replyMarkup
                    ? json_encode($replyMarkup, JSON_THROW_ON_ERROR)
                    : null,
            ], false);
        } catch (Throwable $exception) {
            if (method_exists($this, 'traceTelegramGatewayException')) {
                $this->traceTelegramGatewayException($exception);
            }

            return ['ok' => false, 'result' => []];
        }
    }

    private function editTelegramMessage(
        string|int $chatId,
        string|int $messageId,
        string $text,
        ?array $replyMarkup,
        string $botKey,
    ): array {
        return $this->telegramGatewayJson($this->telegramGatewayEndpoint('edit_message'), [
            'token' => $botKey,
            'chatId' => $chatId,
            'messageId' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup
                ? json_encode($replyMarkup, JSON_THROW_ON_ERROR)
                : null,
        ]);
    }

    private function deleteTelegramMessageViaGateway(
        string|int $chatId,
        string|int $messageId,
        string $botKey,
    ): array {
        return $this->telegramGatewayJson($this->telegramGatewayEndpoint('delete_message'), [
            'token' => $botKey,
            'chatId' => $chatId,
            'messageId' => $messageId,
        ]);
    }

    private function answerTelegramCallbackQuery(string $callbackQueryId, string $text, string $botKey): array
    {
        return $this->telegramGatewayJson($this->telegramGatewayEndpoint('answer_callback_query'), [
            'token' => $botKey,
            'callbackQueryId' => $callbackQueryId,
            'text' => $text !== '' ? $text : null,
        ]);
    }

    private function getTelegramFile(string $fileId, string $botKey): array
    {
        return $this->telegramGatewayJson($this->telegramGatewayEndpoint('get_file'), [
            'token' => $botKey,
            'fileId' => $fileId,
        ]);
    }

    private function downloadTelegramFile(string $filePath, string $botKey): string
    {
        return Http::timeout(60)->get($this->telegramGatewayEndpoint('download_file'), [
            'token' => $botKey,
            'filePath' => $filePath,
        ])->throw()->body();
    }

    private function telegramGatewayJson(string $url, array $parameters, bool $throw = true): array
    {
        $response = Http::acceptJson()
            ->timeout(30)
            ->get($url, array_filter($parameters, static fn (mixed $value): bool => $value !== null));
        if ($throw) {
            $response->throw();
        }
        $payload = $response->json();
        if (! is_array($payload)) {
            if (method_exists($this, 'traceInvalidTelegramGatewayResponse')) {
                $this->traceInvalidTelegramGatewayResponse($url, $response->status(), $response->body(), $response->header('Content-Type'));
            }
            throw new RuntimeException('Telegram gateway returned an invalid JSON response.');
        }
        if (method_exists($this, 'traceTelegramGatewayResponse')) {
            $this->traceTelegramGatewayResponse($url, $response->status(), $payload);
        }

        return $payload;
    }

    private function telegramGatewayEndpoint(string $operation): string
    {
        $url = config("incident.telegram.gateway_endpoints.{$operation}");
        if (! is_string($url) || $url === '') {
            throw new RuntimeException("Telegram gateway endpoint is not configured: {$operation}.");
        }

        return $url;
    }
}
