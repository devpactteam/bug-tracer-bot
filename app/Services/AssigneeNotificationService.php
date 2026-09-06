<?php

namespace App\Services;

use App\Models\IncidentTicket;
use App\Models\SupportUser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the second (assignee) Telegram bot. It connects exactly like the
 * main bot — same API base URL + proxy — and is long-polled through the
 * `telegram:poll-assignee` command. Each assignee must /start the bot so their
 * numeric chat_id can be captured on support_users.assignee_chat_id; finished
 * tickets are then delivered to that chat.
 */
class AssigneeNotificationService
{
    public function client(): PendingRequest
    {
        $token = (string) config('incident.assignee_bot.token');
        if ($token === '') {
            throw new RuntimeException('ASSIGNEE_BOT_TOKEN is not configured.');
        }

        $request = Http::baseUrl(rtrim((string) config('incident.assignee_bot.api_base_url'), '/')."/bot{$token}/")
            ->acceptJson()->timeout(20);

        $bridge = config('incident.assignee_bot.proxy.http_bridge');
        if (is_string($bridge) && $bridge !== '') {
            $request = $request->withOptions(['proxy' => $bridge]);
        }

        return $request;
    }

    public function sendMessage(string|int $chatId, string $text, ?array $replyMarkup = null): array
    {
        return $this->client()->post('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        ]))->throw()->json();
    }

    /**
     * Handle one polled update: match the sender's username against
     * support_users and store their chat_id so tickets can be delivered to
     * them. Non /start messages from unknown users are ignored.
     */
    public function registerFromUpdate(array $update): bool
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (! is_array($message)) {
            return false;
        }
        $chatId = $message['chat']['id'] ?? null;
        $username = $message['from']['username'] ?? null;
        if ($chatId === null || ! is_string($username) || $username === '') {
            return false;
        }

        $user = SupportUser::query()
            ->whereRaw('LOWER(username) = ?', [mb_strtolower(ltrim($username, '@'))])
            ->first();

        if (! $user) {
            if (strcasecmp((string) ($message['text'] ?? ''), '/start') === 0) {
                $this->sendMessage($chatId, 'این ربات مخصوص اعضای تیم پشتیبانی است.');
            }

            return false;
        }

        $user->update(['assignee_chat_id' => (int) $chatId]);

        $this->sendMessage(
            $chatId,
            '✅ ثبت شد، '.e($user->name).'! تیکت‌های بعدی همین‌جا برایتان ارسال می‌شود.'
        );

        return true;
    }

    /**
     * Build the full ticket message (header, analysis, sample data) and send it
     * to the ticket's assignee on the assignee bot.
     */
    public function notifyAssignee(IncidentTicket $ticket, ?SupportUser $assignee): bool
    {
        if (! $assignee || ! $assignee->assignee_chat_id) {
            return false;
        }

        $a = $ticket->sample_data ?? [];
        $sample = is_array($a) && $a !== []
            ? "\n\n🧾 <b>داده‌های نمونه (دیباگ):</b>\n<code>".e(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)).'</code>'
            : '';

        $text = "🎫 <b>تیکت جدید</b>\n\n"
            .'🆔 <b>'.e($ticket->ticket_number)."</b>\n"
            .'🔍 <b>'.e($ticket->title)."</b>\n"
            .'📝 '.e((string) $ticket->description)."\n"
            .'👤 مسئول: <b>'.e($assignee->name).'</b>'.$assignee->taggingText()
            ."\n📂 وضعیت: <b>".e(match ($ticket->status) {
                'open' => 'باز',
                'in_progress' => 'در حال بررسی',
                'resolved' => 'حل شده',
                'closed' => 'بسته شده',
                default => $ticket->status,
            }).'</b>'
            .($ticket->category ? "\n📁 دسته: <b>".e($ticket->category).'</b>' : '')
            .($ticket->priority ? "\n🚦 اولویت: <b>".e($ticket->priority).'</b>' : '')
            .($ticket->scope ? "\n🌐 دامنه: <b>".e($ticket->scope).'</b>' : '')
            .$sample;

        $this->sendMessage($assignee->assignee_chat_id, $text);

        return true;
    }
}
