<?php

namespace App\Services;

use App\Models\SupportUser;
use App\Services\Concerns\SendsTelegramViaGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotService
{
    use SendsTelegramViaGateway;

    public function __construct(private readonly TelegramWebhookObservability $observability) {}

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
        // Previous direct connection to api.telegram.org:
        // return $this->client()->post('sendMessage', array_filter([
        //     'chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML',
        //     'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        // ]))->throw()->json();

        $this->traceSend('telegram.send.attempt', $chatId, $text, $replyMarkup);
        $result = $this->sendTelegramMessage(
            $text,
            $chatId,
            (string) config('incident.telegram.bot_token'),
            $replyMarkup,
        );
        $this->traceSendResult($chatId, $result);

        return $result;
    }

    private function traceSend(string $checkpoint, string|int $chatId, string $text, ?array $replyMarkup): void
    {
        if (! $trace = $this->observability->current()) {
            return;
        }

        $gatewayUrl = (string) config('incident.telegram.gateway_url');
        $this->observability->record($trace, $checkpoint, [
            'operation' => 'send_message',
            'chat_id' => (string) $chatId,
            'text_length' => mb_strlen($text),
            'has_reply_markup' => $replyMarkup !== null,
            'app_debug' => (bool) config('app.debug'),
            'bot_token_configured' => filled(config('incident.telegram.bot_token')),
            'gateway_host' => parse_url($gatewayUrl, PHP_URL_HOST) ?: 'invalid_or_relative_url',
        ]);
    }

    private function traceSendResult(string|int $chatId, array $result): void
    {
        if (! $trace = $this->observability->current()) {
            return;
        }

        $ok = (bool) ($result['ok'] ?? false);
        $this->observability->record($trace, $ok ? 'telegram.send.succeeded' : 'telegram.send.failed', [
            'operation' => 'send_message',
            'chat_id' => (string) $chatId,
            'telegram_ok' => $ok,
            'message_id' => $result['result']['message_id'] ?? null,
            'failure_reason' => $ok
                ? null
                : (config('app.debug') ? 'suppressed_in_debug' : (filled(config('incident.telegram.bot_token')) ? 'gateway_rejected_or_unreachable' : 'missing_bot_token')),
            'gateway_description' => isset($result['description']) ? str((string) $result['description'])->limit(300)->toString() : null,
        ]);
    }

    private function traceTelegramGatewayResponse(string $url, int $status, array $payload): void
    {
        if (! $trace = $this->observability->current()) {
            return;
        }

        $this->observability->record($trace, 'telegram.gateway.responded', [
            'operation' => 'send_message',
            'http_status' => $status,
            'telegram_ok' => (bool) ($payload['ok'] ?? false),
            'gateway_host' => parse_url($url, PHP_URL_HOST) ?: 'invalid_or_relative_url',
            'gateway_description' => isset($payload['description'])
                ? $this->observability->safeError((string) $payload['description'])
                : null,
        ]);
    }

    private function traceTelegramGatewayException(\Throwable $exception): void
    {
        if (! $trace = $this->observability->current()) {
            return;
        }

        $this->observability->record($trace, 'telegram.gateway.exception', [
            'operation' => 'send_message',
            'exception_class' => $exception::class,
            'exception_message' => $this->observability->safeError($exception->getMessage()),
        ]);
    }

    public function editMessage(string|int $chatId, string|int $messageId, string $text, ?array $replyMarkup = null): array
    {
        // Previous direct connection to api.telegram.org:
        // return $this->client()->post('editMessageText', array_filter([
        //     'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML',
        //     'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        // ]))->throw()->json();

        return $this->editTelegramMessage(
            $chatId,
            $messageId,
            $text,
            $replyMarkup,
            (string) config('incident.telegram.bot_token'),
        );
    }

    public function deleteMessage(string|int $chatId, string|int $messageId): array
    {
        // Previous direct connection to api.telegram.org:
        // return $this->client()->post('deleteMessage', [
        //     'chat_id' => $chatId,
        //     'message_id' => $messageId,
        // ])->throw()->json();

        return $this->deleteTelegramMessageViaGateway(
            $chatId,
            $messageId,
            (string) config('incident.telegram.bot_token'),
        );
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): array
    {
        try {
            // Previous direct connection to api.telegram.org:
            // return $this->client()
            //     ->post('answerCallbackQuery', ['callback_query_id' => $callbackQueryId, 'text' => $text])
            //     ->throw()->json();

            return $this->answerTelegramCallbackQuery(
                $callbackQueryId,
                $text,
                (string) config('incident.telegram.bot_token'),
            );
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

    /**
     * Resolve a Telegram file_id to its download path (getFile). Returns null
     * when the file cannot be resolved.
     */
    public function getFile(string $fileId): ?string
    {
        try {
            // Previous direct connection to api.telegram.org:
            // $filePath = $this->client()
            //     ->post('getFile', ['file_id' => $fileId])
            //     ->throw()->json('result.file_path');

            $filePath = data_get(
                $this->getTelegramFile($fileId, (string) config('incident.telegram.bot_token')),
                'result.file_path',
            );
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        return is_string($filePath) ? $filePath : null;
    }

    /**
     * Download a file previously resolved via getFile() as raw bytes. Returns
     * null when the download fails.
     */
    public function downloadFile(string $filePath): ?string
    {
        $token = (string) config('incident.telegram.bot_token');
        // Previous direct connection to api.telegram.org:
        // $url = rtrim((string) config('incident.telegram.api_base_url'), '/')."/file/bot{$token}/{$filePath}";
        // $request = Http::withOptions(['timeout' => 60]);
        // $bridge = config('incident.telegram.proxy.http_bridge');
        // if (is_string($bridge) && $bridge !== '') {
        //     $request = $request->withOptions(['proxy' => $bridge]);
        // }
        // $response = $request->get($url);
        // return $response->successful() ? $response->body() : null;

        try {
            return $this->downloadTelegramFile($filePath, $token);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function previewKeyboard(string $sessionId): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ تأیید و ایجاد تیکت', 'callback_data' => "incident:approve:{$sessionId}"]],
            [['text' => '📝 ثبت توضیحات بیشتر', 'callback_data' => "incident:clarify:{$sessionId}"]],
            [['text' => '👤 تغییر مسئول', 'callback_data' => "incident:assignee:{$sessionId}"]],
            [['text' => '❌ لغو گزارش', 'callback_data' => "incident:cancel:{$sessionId}"]],
        ]];
    }

    /**
     * Build the preview message text. The assignee is always shown (either the
     * selected one or a notice to pick one before approving).
     */
    public static function previewText(array $result, ?SupportUser $assignee, ?SupportUser $suggested = null): string
    {
        $text = "📋 <b>پیش‌نمایش گزارش</b>\n"
            .'🏷️ <b>'.e($result['title'])."</b>\n"
            .'📝 '.e($result['summary']);

        if ($assignee) {
            $text .= "\n\n👤 مسئول: <b>".e($assignee->name).'</b>'.$assignee->taggingText();
        } elseif ($suggested) {
            $text .= "\n\n👤 مسئول پیشنهادی (تأیید/تغییر): <b>".e($suggested->name).'</b>'.$suggested->taggingText();
        } else {
            $text .= "\n\n⚠️ <b>مسئولی انتخاب نشده است.</b> برای ثبت تیکت، ابتدا «تغییر مسئول» را بزنید.";
        }

        return $text;
    }

    /**
     * Inline keyboard listing the assignable users with their covered
     * categories. Each button dispatches incident:assignee:pick.
     */
    public function assigneeListKeyboard(string $sessionId, iterable $users): array
    {
        $rows = [];
        foreach ($users as $user) {
            $rows[] = [[
                'text' => '👤 '.$user->name.' ('.$user->coveredCategoryLabelText().')',
                'callback_data' => "incident:assignee:pick:{$sessionId}:{$user->id}",
            ]];
        }

        return ['inline_keyboard' => $rows];
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
