<?php

namespace App\Services;

use App\Models\SupportUser;
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

    /**
     * Resolve a Telegram file_id to its download path (getFile). Returns null
     * when the file cannot be resolved.
     */
    public function getFile(string $fileId): ?string
    {
        try {
            $filePath = $this->client()
                ->post('getFile', ['file_id' => $fileId])
                ->throw()->json('result.file_path');
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
        $url = rtrim((string) config('incident.telegram.api_base_url'), '/')."/file/bot{$token}/{$filePath}";

        $request = Http::withOptions(['timeout' => 60]);
        $bridge = config('incident.telegram.proxy.http_bridge');
        if (is_string($bridge) && $bridge !== '') {
            $request = $request->withOptions(['proxy' => $bridge]);
        }

        try {
            $response = $request->get($url);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        return $response->successful() ? $response->body() : null;
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
