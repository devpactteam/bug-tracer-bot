<?php

namespace App\Services;

use App\Models\IncidentTicket;
use App\Models\SupportUser;
use App\Services\Concerns\SendsTelegramViaGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
    use SendsTelegramViaGateway;

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
        // Previous direct connection to api.telegram.org:
        // return $this->client()->post('sendMessage', array_filter([
        //     'chat_id' => $chatId,
        //     'text' => $text,
        //     'parse_mode' => 'HTML',
        //     'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : null,
        // ]))->throw()->json();

        return [
            'ok' => $this->sendTelegram($text, $chatId, (string) config('incident.assignee_bot.token')),
            // The supplied gateway contract only returns a success status; it
            // does not provide Telegram's message_id or inline-keyboard API.
            'result' => [],
        ];
    }

    /**
     * Send a local photo (public-disk relative path) as a photo message.
     */
    public function sendPhoto(string|int $chatId, string $publicPath, string $caption = ''): array
    {
        $absolute = Storage::disk('public')->path($publicPath);
        if (! is_file($absolute)) {
            throw new RuntimeException('Photo file not found: '.$publicPath);
        }

        return $this->client()
            ->attach('photo', fopen($absolute, 'rb'), basename($publicPath))
            ->post('sendPhoto', array_filter([
                'chat_id' => $chatId,
                'caption' => $caption !== '' ? $caption : null,
                'parse_mode' => 'HTML',
            ]))
            ->throw()->json();
    }

    /**
     * Forward every photo stored with the ticket's intake session to the
     * assignee's chat. Each photo is sent best-effort: a failure is reported
     * and never blocks the rest of the flow.
     */
    private function sendTicketPhotos(IncidentTicket $ticket, SupportUser $assignee): void
    {
        foreach ($ticket->photoPaths() as $path) {
            try {
                $this->sendPhoto(
                    $assignee->assignee_chat_id,
                    $path,
                    '📎 تصویر پیوست تیکت '.$ticket->ticket_number
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
    }

    public function editMessageReplyMarkup(string|int $chatId, string|int $messageId, ?array $replyMarkup = null): array
    {
        return $this->client()->post('editMessageReplyMarkup', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ? json_encode($replyMarkup, JSON_THROW_ON_ERROR) : json_encode(['inline_keyboard' => []]),
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
            // Telegram callback queries expire quickly. The requested ticket
            // action can already have been applied, so do not fail polling for
            // this expected late/replayed callback response.
            if ($exception->response?->status() === 400
                && str_contains((string) $exception->response?->json('description'), 'query is too old')) {
                report($exception);

                return ['ok' => false, 'description' => 'callback expired'];
            }

            throw $exception;
        }
    }

    /**
     * Route one polled update: /start registration, inline-button callbacks on
     * delivered tickets, or the resolution note the assignee sends.
     */
    public function handleUpdate(array $update): void
    {
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $this->handleTicketCallback($callback);

            return;
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (! is_array($message)) {
            return;
        }
        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null) {
            return;
        }
        $text = $message['text'] ?? null;

        if (is_string($text) && strcasecmp(trim($text), '/start') === 0) {
            $this->registerFromUpdate($update);

            return;
        }

        if (is_string($text) && trim($text) !== '') {
            $this->handleResolutionNote($chatId, trim($text));
        }
    }

    /**
     * Process an inline button on a delivered ticket:
     *   ticket:progress:{id} – mark the ticket in_progress, show "fix done".
     *   ticket:resolve:{id}  – start the three-question questionnaire.
     *   ticket:close:{id}    – close the ticket after the answers are recorded.
     *   ticket:transfer:{id} – show the assignee transfer list.
     *   ticket:canceltransfer:{id} – dismiss the transfer list.
     *   ticket:pick:{id}:{userId} – reassign the ticket, remove the old chat
     *       message and deliver it to the new assignee.
     */
    private function handleTicketCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = $message['chat']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $data = $callback['data'] ?? '';
        if ($chatId === null || ! is_string($data)) {
            return;
        }

        $parts = explode(':', $data);
        $action = $parts[1] ?? '';
        $ticketId = (int) ($parts[2] ?? 0);
        $ticket = IncidentTicket::query()->with('assignee')->find($ticketId);
        if (! $ticket || ! $ticket->assignee || (int) $ticket->assignee->assignee_chat_id !== (int) $chatId) {
            $this->answerCallbackQuery($callbackId, 'این دکمه متعلق به شما نیست.');

            return;
        }

        match ($action) {
            'progress' => $this->markInProgress($callbackId, $ticket, $messageId),
            'resolve' => $this->requestResolutionNote($callbackId, $ticket, $messageId),
            'close' => $this->closeTicket($callbackId, $ticket, $messageId),
            'transfer' => $this->showTransferList($callbackId, $ticket),
            'canceltransfer' => $this->cancelTransfer($callbackId, $chatId, $messageId),
            'pick' => $this->reassignTicket($callbackId, $ticket, $chatId, $messageId, (int) ($parts[3] ?? 0)),
            default => null,
        };
    }

    private function markInProgress(string $callbackId, IncidentTicket $ticket, string|int $messageId): void
    {
        $this->logChange($ticket, 'status_changed', performer: (string) $ticket->assignee?->name, from: $ticket->status, to: 'in_progress');
        $ticket->update(['status' => 'in_progress']);
        $this->answerCallbackQuery($callbackId, '✅ وضعیت: در حال بررسی');
        $this->editMessageReplyMarkup($ticket->assignee->assignee_chat_id, $messageId, $this->ticketResolveKeyboard($ticket->id));
    }

    /**
     * Start the three-question resolution questionnaire. The assignee answers
     * the questions one by one and each answer is stored on its own column.
     */
    private function requestResolutionNote(string $callbackId, IncidentTicket $ticket, string|int $messageId): void
    {
        $ticket->update(['resolution_pending' => true, 'resolution_step' => 1]);
        $this->logChange($ticket, 'resolution_started', performer: (string) $ticket->assignee?->name);
        $this->answerCallbackQuery($callbackId, '📝 لطفاً به پرسش‌ها پاسخ دهید');
        $this->editMessageReplyMarkup($ticket->assignee->assignee_chat_id, $messageId);
        $this->sendMessage(
            $ticket->assignee->assignee_chat_id,
            '🧾 <b>تیکت '.e($ticket->ticket_number)." در حال بررسی است.</b>\n"
            .$this->questionPrompt(1)
        );
    }

    private function closeTicket(string $callbackId, IncidentTicket $ticket, string|int $messageId): void
    {
        $fromStatus = $ticket->status;
        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
            'root_cause_author_id' => $ticket->assignee_id,
            'resolution_pending' => false,
            'resolution_step' => 0,
        ]);
        $this->logChange($ticket, 'closed', performer: (string) $ticket->assignee?->name, from: $fromStatus, to: 'closed');
        $this->answerCallbackQuery($callbackId, '✅ تیکت بسته شد');
        $this->editMessageReplyMarkup($ticket->assignee->assignee_chat_id, $messageId);
        $message = '✅ <b>تیکت '.e($ticket->ticket_number).' با موفقیت بسته شد.</b>';
        if ($ticket->bug_owner_note !== null || $ticket->bug_cause_note !== null || $ticket->bug_action_note !== null) {
            $message .= "\n\n".$this->resolutionSummary($ticket);
        }
        $this->sendMessage($ticket->assignee->assignee_chat_id, $message);
    }

    /**
     * Show a list of other assignable users so the current assignee can hand
     * the ticket over to someone else.
     */
    private function showTransferList(string $callbackId, IncidentTicket $ticket): void
    {
        $users = SupportUser::query()
            ->assignable()
            ->whereKeyNot($ticket->assignee_id)
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->answerCallbackQuery($callbackId, '⚠️ مسئول دیگری تعریف نشده است.');

            return;
        }

        $this->answerCallbackQuery($callbackId, '👇 مسئول جدید را انتخاب کنید');
        $this->sendMessage(
            $ticket->assignee->assignee_chat_id,
            '↪️ <b>انتقال تیکت '.e($ticket->ticket_number).':</b>'."\n"
            .'تیکت به کدام مسئول ارسال شود؟',
            $this->ticketTransferKeyboard($ticket->id, $users)
        );
    }

    /**
     * Reassign the ticket to another assignee: delete the ticket message from
     * the current chat and deliver a fresh copy to the new assignee's bot chat.
     */
    private function reassignTicket(string $callbackId, IncidentTicket $ticket, string|int $fromChatId, string|int $listMessageId, int $newUserId): void
    {
        $newUser = SupportUser::query()->assignable()->find($newUserId);
        if (! $newUser || $newUser->id === $ticket->assignee_id) {
            $this->answerCallbackQuery($callbackId, '⚠️ مسئول انتخاب‌شده معتبر نیست.');

            return;
        }
        if (! $newUser->assignee_chat_id) {
            $this->answerCallbackQuery($callbackId, 'این کاربر هنوز بات را استارت نکرده است.');

            return;
        }

        $oldAssigneeName = (string) $ticket->assignee?->name;
        $oldMessageId = $ticket->assignee_message_id;
        $ticket->update([
            'assignee_id' => $newUser->id,
            'assignee_message_id' => null,
            'resolution_pending' => false,
            'resolution_step' => 0,
        ]);
        $ticket->log('assigned_to', null, (string) $newUser->name, $oldAssigneeName, (string) $newUser->name);

        // Best-effort cleanup of the forwarded-from chat (both the transfer
        // list and the original ticket message).
        $this->safeDelete($fromChatId, $listMessageId);
        if ($oldMessageId) {
            $this->safeDelete($fromChatId, $oldMessageId);
        }

        $this->answerCallbackQuery($callbackId, '✅ تیکت منتقل شد');
        $sent = $this->sendMessage(
            $newUser->assignee_chat_id,
            $this->ticketMessageText($ticket, $newUser, 'transferred'),
            $ticket->status === 'open'
                ? $this->ticketProgressKeyboard($ticket->id)
                : $this->ticketResolveKeyboard($ticket->id)
        );
        $ticket->update(['assignee_message_id' => $sent['result']['message_id'] ?? null]);
        $this->sendTicketPhotos($ticket, $newUser);
    }

    private function cancelTransfer(string $callbackId, string|int $chatId, string|int $messageId): void
    {
        $this->answerCallbackQuery($callbackId, 'انتقال لغو شد');
        $this->safeDelete($chatId, $messageId);
    }

    private function safeDelete(string|int $chatId, string|int $messageId): void
    {
        try {
            $this->deleteMessage($chatId, $messageId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function safeEditMessageReplyMarkup(string|int $chatId, ?int $messageId): void
    {
        if ($messageId === null) {
            return;
        }
        try {
            $this->editMessageReplyMarkup($chatId, $messageId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Append an audit-trail entry to the ticket's log. Errors are reported but
     * never block the user-facing flow.
     */
    private function logChange(
        IncidentTicket $ticket,
        string $action,
        ?string $performer = null,
        ?string $description = null,
        ?string $from = null,
        ?string $to = null,
    ): void {
        try {
            $ticket->log($action, $description, $performer, $from, $to);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Collect the answers to the resolution questions step by step. Each reply
     * is stored on its dedicated column; the last answer closes the ticket
     * automatically — no final confirmation button is shown.
     */
    private function handleResolutionNote(string|int $chatId, string $text): void
    {
        $ticket = IncidentTicket::query()
            ->whereHas('assignee', fn ($query) => $query->where('assignee_chat_id', (int) $chatId))
            ->where('resolution_pending', true)
            ->latest('id')
            ->first();
        if (! $ticket) {
            return;
        }

        // NOTE: پرسیدن «مسئول باگ چه کسی بود؟» فعلاً غیرفعال است.
        // 1 => 'bug_owner_note',
        $columns = [
            1 => 'bug_cause_note',
            2 => 'bug_action_note',
        ];
        $step = max(1, (int) $ticket->resolution_step);
        $column = $columns[$step] ?? null;
        if ($column === null) {
            return;
        }

        $ticket->update([$column => $text]);
        $this->logChange($ticket, 'note_answer', performer: (string) $ticket->assignee?->name, description: $this->questionText($step)."\n".$text);

        $lastStep = max(array_keys($columns));
        if ($step < $lastStep) {
            $ticket->update(['resolution_step' => $step + 1]);
            $this->sendMessage($chatId, $this->questionPrompt($step + 1));

            return;
        }

        // Last answer recorded: close the ticket right away, no confirmation.
        $fromStatus = $ticket->status;
        $ticket->update([
            'resolution_pending' => false,
            'resolution_step' => 0,
            'status' => 'closed',
            'resolved_at' => now(),
            'root_cause_author_id' => $ticket->assignee_id,
        ]);
        $this->logChange($ticket, 'closed', performer: (string) $ticket->assignee?->name, from: $fromStatus, to: 'closed');
        $this->safeEditMessageReplyMarkup($chatId, $ticket->assignee_message_id);
        $this->sendMessage(
            $chatId,
            '✅ <b>تیکت '.e($ticket->ticket_number).' بسته شد.</b>'."\n\n".$this->resolutionSummary($ticket)
        );
    }

    /**
     * Label of the questionnaire step as a Persian question.
     */
    private function questionText(int $step): string
    {
        // NOTE: پرسیدن «مسئول باگ چه کسی بود؟» فعلاً غیرفعال است.
        // 1 => '1️⃣ مسئول باگ چه کسی بود؟',
        return match ($step) {
            1 => '1️⃣ دلیل باگ چه بوده؟',
            2 => '2️⃣ اقدامات انجام شده جهت رفع آن چه بوده است؟',
            default => '',
        };
    }

    /**
     * A question prompt inviting the assignee to reply in one message.
     */
    private function questionPrompt(int $step): string
    {
        $text = $this->questionText($step);
        if ($text === '') {
            return '';
        }

        return $text."\n\nپاسخ را در یک پیام بفرستید.";
    }

    /**
     * Render the recorded answers to the resolution questions as an HTML
     * message block.
     */
    private function resolutionSummary(IncidentTicket $ticket): string
    {
        // NOTE: «مسئول باگ» فعلاً پرسیده نمیشود و در خلاصه هم نمیآید.
        // ['1️⃣ مسئول باگ', $ticket->bug_owner_note],
        $pairs = [
            ['1️⃣ دلیل باگ', $ticket->bug_cause_note],
            ['2️⃣ اقدامات انجام‌شده', $ticket->bug_action_note],
        ];

        return implode("\n\n", array_map(
            fn (array $pair): string => '<b>'.$pair[0].':</b>'
                .($pair[1] !== null ? "\n".e((string) $pair[1]) : ''),
            $pairs
        ));
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

        try {
            // Re-deliver any open ticket that was never successfully delivered
            // (e.g. created before this user /started the bot).
            $undelivered = IncidentTicket::query()
                ->where('assignee_id', $user->id)
                ->whereNull('assignee_message_id')
                ->whereIn('status', ['open', 'in_progress'])
                ->orderBy('id')
                ->get();

            foreach ($undelivered as $ticket) {
                $this->notifyAssignee($ticket, $user);
            }

            if ($undelivered->isNotEmpty()) {
                $this->sendMessage(
                    $chatId,
                    '📬 <b>'.$undelivered->count().' تیکت معلق به شما ارسال شد.</b>'
                );
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return true;
    }

    /**
     * Build the full ticket message (header, analysis, sample data) and send it
     * to the ticket's assignee on the assignee bot. All taxonomy values are
     * rendered as their Persian labels and raw sample data is wrapped in a
     * monospace code block so it stays readable and unmodified.
     */
    public function notifyAssignee(IncidentTicket $ticket, ?SupportUser $assignee): bool
    {
        if (! $assignee || ! $assignee->assignee_chat_id) {
            return false;
        }

        $sent = $this->sendMessage(
            $assignee->assignee_chat_id,
            $this->ticketMessageText($ticket, $assignee),
            $this->ticketProgressKeyboard($ticket->id)
        );
        $ticket->update(['assignee_message_id' => $sent['result']['message_id'] ?? null]);
        $this->sendTicketPhotos($ticket, $assignee);

        return true;
    }

    public function ticketProgressKeyboard(int $ticketId): array
    {
        return ['inline_keyboard' => [
            [['text' => '🔄 در حال بررسی', 'callback_data' => "ticket:progress:{$ticketId}"]],
            [['text' => '↪️ انتقال به مسئول دیگر', 'callback_data' => "ticket:transfer:{$ticketId}"]],
        ]];
    }

    public function ticketResolveKeyboard(int $ticketId): array
    {
        return ['inline_keyboard' => [
            [['text' => '✅ بررسی و رفع شد', 'callback_data' => "ticket:resolve:{$ticketId}"]],
            [['text' => '↪️ انتقال به مسئول دیگر', 'callback_data' => "ticket:transfer:{$ticketId}"]],
        ]];
    }

    public function ticketCloseKeyboard(int $ticketId): array
    {
        return ['inline_keyboard' => [
            [['text' => '📌 ثبت توضیحات و بستن تیکت', 'callback_data' => "ticket:close:{$ticketId}"]],
        ]];
    }

    public function ticketTransferKeyboard(int $ticketId, iterable $users): array
    {
        $rows = [];
        foreach ($users as $user) {
            $rows[] = [[
                'text' => '👤 '.$user->name.' ('.$user->coveredCategoryLabelText().')',
                'callback_data' => "ticket:pick:{$ticketId}:{$user->id}",
            ]];
        }
        $rows[] = [['text' => '❌ انصراف', 'callback_data' => "ticket:canceltransfer:{$ticketId}"]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Compose the full ticket message (header, analysis, sample data). All
     * taxonomy values are rendered as their Persian labels and raw sample data
     * is wrapped in a monospace code block so it stays readable and unmodified.
     */
    public function ticketMessageText(IncidentTicket $ticket, SupportUser $assignee, string $variant = 'new'): string
    {
        $a = $ticket->sample_data ?? [];
        $sample = is_array($a) && $a !== []
            ? "\n\n🧾 <b>داده‌های نمونه (دیباگ):</b>\n<pre>"
                .htmlspecialchars(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>'
            : '';

        $header = match ($variant) {
            'transferred' => '↪️ <b>تیکتی به شما ارجاع شد</b>',
            'reminder' => '🔁 <b>یادآوری تیکت در انتظار بررسی</b>',
            default => '🔔 <b>تیکت جدیدی برای شما ثبت شد</b>',
        };

        return $header."\n\n"
            .'🆔 شماره تیکت: <code>'.e($ticket->ticket_number)."</code>\n"
            .'🔍 <b>'.e($ticket->title)."</b>\n\n"
            .'📝 <b>شرح مشکل:</b>'."\n".e((string) $ticket->description)
            ."\n\n👤 <b>مسئول:</b> ".e($assignee->name).$assignee->taggingText()
            ."\n📂 <b>وضعیت:</b> ".$this->statusLabel($ticket->status)
            ."\n📁 <b>دسته:</b> ".$this->categoryLabel($ticket->category)
            ."\n🚦 <b>اولویت:</b> ".$this->priorityLabel($ticket->priority)
            ."\n🌐 <b>دامنه:</b> ".$this->scopeLabel($ticket->scope)
            .$sample
            ."\n\n📌 لطفاً پس از بررسی، نتیجه و اقدام‌های انجام‌شده را اعلام کنید.";
    }

    private function categoryLabel(?string $category): string
    {
        if (! is_string($category) || $category === '') {
            return 'نامشخص';
        }
        $key = strtolower(trim($category));
        $categories = (array) config('incident.categories');
        if (isset($categories[$key]['label'])) {
            return (string) $categories[$key]['label'];
        }
        $aliases = [
            'payments' => 'payment',
            'general' => 'other',
            'client-side' => 'client',
            'frontend' => 'client',
            'back-end' => 'backend',
            'server' => 'backend',
        ];
        $mapped = $aliases[$key] ?? $key;

        return isset($categories[$mapped]['label'])
            ? (string) $categories[$mapped]['label']
            : $category;
    }

    private function scopeLabel(?string $scope): string
    {
        return match ($scope) {
            'system_wide' => 'سراسری (همه کاربران)',
            'user_specific' => 'تک کاربر / حساب خاص',
            'client', 'backend' => $scope === 'client' ? 'کلاینت' : 'بک‌اند',
            default => 'نامشخص',
        };
    }

    private function priorityLabel(?string $priority): string
    {
        return match ($priority) {
            'low' => 'کم',
            'normal' => 'عادی',
            'high' => 'بالا',
            'critical' => 'بحرانی',
            default => 'عادی',
        };
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'open' => 'باز',
            'in_progress' => 'در حال بررسی',
            'resolved' => 'حل شده',
            'closed' => 'بسته شده',
            default => (string) $status,
        };
    }
}
