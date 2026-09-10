<?php

namespace App\Services;

use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Models\ProcessedTelegramUpdate;
use App\Models\SupportUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TelegramUpdateHandler
{
    public function __construct(
        private readonly IncidentIntakeService $intake,
        private readonly TelegramBotService $telegram,
    ) {}

    public function handle(array $update): void
    {
        $updateId = (int) ($update['update_id'] ?? 0);
        if ($updateId <= 0 || ! $this->claimUpdate($updateId)) {
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (! $message) {
            return;
        }
        $operatorId = (string) ($message['from']['id'] ?? '');
        if (! $this->authorized($operatorId)) {
            return;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if (strcasecmp($text, '/start') === 0) {
            $this->telegram->sendMessage($chatId, '👋 سلام! پیام‌های مربوط به مشکل را فوروارد کنید 📩؛ سپس روی <b>🚀 نهایی‌سازی و تحلیل</b> بزنید.');

            return;
        }
        if (strcasecmp($text, '/finalize') === 0) {
            $session = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)->latest('id')->first();
            if ($session) {
                $this->intake->finalize($session->session_id);
            }

            return;
        }
        if (preg_match('/^\/assignee(?:\s+(.+))?$/i', $text, $matches)) {
            $this->handleAssigneeCommand($chatId, $matches[1] ?? null);

            return;
        }

        $awaiting = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)
            ->where('status', 'awaiting_clarification')->latest('id')->first();
        if ($awaiting && ($text !== '' || isset($message['photo']))) {
            $session = $this->intake->appendMessage($this->normalizeMessage($message), $chatId, $operatorId);
            ProcessIncidentBundleJob::dispatch($session->session_id, true, true)
                ->onQueue(config('incident.intake.queue'));

            return;
        }
        $this->intake->appendMessage($this->normalizeMessage($message), $chatId, $operatorId);
        $session = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)->latest('id')->first();
        // Avoid one bot reply per forwarded message. Keep a single finalize
        // prompt for the first message in the current intake session.
        if ($session && Cache::add("incident:finalize-prompt:{$session->session_id}", true, now()->addDay())) {
            try {
                $sentMessage = $this->telegram->sendMessage(
                    $chatId,
                    '📥 پیام دریافت شد. پیام‌های بیشتری را فوروارد کنید؛ در پایان روی 🚀 <b>نهایی‌سازی و تحلیل</b> بزنید.',
                    $this->telegram->finalizeKeyboard($session->session_id)
                );
                $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
            } catch (\Throwable $exception) {
                Cache::forget("incident:finalize-prompt:{$session->session_id}");
                throw $exception;
            }
        }
    }

    private function claimUpdate(int $updateId): bool
    {
        try {
            DB::transaction(fn () => ProcessedTelegramUpdate::create(['update_id' => $updateId]));

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function authorized(string $operatorId): bool
    {
        $allowed = config('incident.telegram.allowed_operator_ids', []);

        return $allowed === [] || in_array((int) $operatorId, $allowed, true);
    }

    private function normalizeMessage(array $message): array
    {
        $mediaType = null;
        $mediaFileId = null;
        foreach (['photo', 'document', 'video', 'audio', 'voice'] as $type) {
            if (isset($message[$type])) {
                $mediaType = $type;
                $media = is_array($message[$type]) && array_is_list($message[$type]) ? end($message[$type]) : $message[$type];
                $mediaFileId = $media['file_id'] ?? null;
                break;
            }
        }

        return [
            'message_id' => $message['message_id'] ?? null,
            'content' => $message['text'] ?? $message['caption'] ?? null,
            'media_type' => $mediaType,
            'media_file_id' => $mediaFileId,
            'forward_origin_metadata' => $message['forward_origin'] ?? $message['forward_from'] ?? null,
        ];
    }

    /**
     * Resolve a /assignee argument (numeric support-user id or @username) and
     * set the assignee for the latest active session of this chat. Calling
     * /assignee with no argument shows the assignee picker list.
     */
    private function handleAssigneeCommand(string|int $chatId, ?string $arg): void
    {
        $session = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)
            ->whereIn('status', ['awaiting_approval', 'collecting'])->latest('id')->first();

        if (! $session || $arg === null || trim($arg) === '') {
            $users = SupportUser::query()->assignable()->orderBy('id')->get();
            if ($users->isEmpty()) {
                $this->telegram->sendMessage($chatId, '⚠️ هنوز کاربری به عنوان مسئول تعریف نشده است.');

                return;
            }
            $sentMessage = $this->telegram->sendMessage(
                $chatId,
                '👤 <b>انتخاب مسئول تیکت:</b>',
                $this->telegram->assigneeListKeyboard($session->session_id, $users)
            );
            $session?->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);

            return;
        }

        $query = SupportUser::query()->assignable();
        $arg = trim($arg);
        if (preg_match('/^\d+$/', $arg)) {
            $user = (clone $query)->find((int) $arg);
        } else {
            $user = (clone $query)->whereRaw('LOWER(username) = ?', [mb_strtolower(ltrim($arg, '@'))])->first();
        }
        if (! $user) {
            $this->telegram->sendMessage($chatId, '⚠️ کاربر یافت نشد یا قابلیت مسئول شدن ندارد.');

            return;
        }

        $session->update(['selected_assignee_id' => $user->id]);
        $this->telegram->sendMessage(
            $chatId,
            '✅ <b>مسئول تیکت ثبت شد:</b> 👤 '.e($user->name).'| دسته‌ها: <b>'.e($user->coveredCategoryLabelText()).'</b>'.$user->taggingText()
        );
    }

    private function handleCallback(array $callback): void
    {
        $operatorId = (string) ($callback['from']['id'] ?? '');
        if (! $this->authorized($operatorId)) {
            return;
        }
        $data = explode(':', (string) ($callback['data'] ?? ''));
        if (($data[0] ?? '') !== 'incident') {
            return;
        }
        $action = $data[1] ?? '';
        $sessionId = $data[2] ?? null;

        // Assignee picker buttons carry an extra segment:
        // incident:assignee:pick:{sessionId}:{userId}
        if ($action === 'assignee' && $sessionId === 'pick') {
            $this->handleAssigneePick($callback, $data[3] ?? null, (int) ($data[4] ?? 0));

            return;
        }
        if (! $sessionId) {
            return;
        }
        $session = IncidentIntakeSession::query()->where('session_id', $sessionId)->first();
        if (! $session) {
            return;
        }
        if ($action === 'finalize' && $session->status === 'collecting') {
            // Telegram callback queries expire quickly; acknowledge before
            // starting synchronous analysis or dispatching a queue job.
            $this->telegram->answerCallbackQuery($callback['id'], '⏳ تحلیل در حال آماده‌سازی است.');
            $callbackMessage = $callback['message'] ?? [];
            if (isset($callbackMessage['chat']['id'], $callbackMessage['message_id'])) {
                try {
                    $this->telegram->deleteMessage(
                        $callbackMessage['chat']['id'],
                        $callbackMessage['message_id']
                    );
                } catch (\Throwable $exception) {
                    // Deletion may fail due to Telegram permissions or stale data.
                    // The analysis must still continue for the operator.
                    report($exception);
                }
            }
            $this->intake->finalize($sessionId);
        } elseif ($action === 'clarifications'
            && $session->status === 'awaiting_clarification'
            && (($session->ai_analysis_result['clarification_ready_for_finalization'] ?? false) === true)) {
            $this->telegram->answerCallbackQuery($callback['id'], '🧠 تحلیل نهایی در حال آماده‌سازی است.');
            $this->deleteCallbackMessage($callback['message'] ?? []);
            $this->intake->finalize($sessionId, true);
        } elseif ($action === 'approve' && $session->status === 'awaiting_approval') {
            if (config('incident.intake.require_assignee_before_approval') && ! $session->selected_assignee_id) {
                $this->telegram->answerCallbackQuery($callback['id'], '⚠️ ابتدا مسئول را انتخاب کنید.');
                $this->telegram->sendMessage(
                    $session->telegram_chat_id,
                    '⚠️ <b>برای ثبت تیکت، ابتدا باید مسئول آن را انتخاب کنید.</b>'."\n"
                    .'روی «👤 تغییر مسئول» بزنید و یکی از مسئولان را برگزینید.'
                );

                return;
            }
            $this->telegram->answerCallbackQuery($callback['id'], '📝 در حال ایجاد تیکت...');
            $this->deleteCallbackMessage($callback['message'] ?? []);
            $ticket = ProcessIncidentBundleJob::createTicket($sessionId);
            if ($ticket) {
                $assignee = $ticket->assignee;
                $deliveryWarning = ($assignee && ! $assignee->assignee_chat_id)
                    ? "\n\n⚠️ مسئول هنوز بات تیکت را «/start» نکرده؛ تیکت زمانی برایش ارسال می‌شود که استارت کند."
                    : '';
                $this->telegram->sendMessage(
                    $session->telegram_chat_id,
                    "✅ <b>تیکت با موفقیت ثبت شد.</b>\n\n"
                    ."🎫 شماره تیکت:\n<code>".e($ticket->ticket_number)."</code>\n\n"
                    .'🔍 <b>'.e($ticket->title)."</b>\n"
                    .'📂 وضعیت: <b>'.e(match ($ticket->status) {
                        'open' => 'باز',
                        'in_progress' => 'در حال بررسی',
                        'resolved' => 'حل شده',
                        'closed' => 'بسته شده',
                        default => $ticket->status,
                    }).'</b>'
                    .($assignee ? "\n👤 مسئول: <b>".e($assignee->name).'</b>'.$assignee->taggingText() : '')
                    .$deliveryWarning
                );
            } else {
                $this->telegram->sendMessage(
                    $session->telegram_chat_id,
                    '⚠️ تیکت قبلاً ثبت شده یا امکان ایجاد آن وجود ندارد.'
                );
            }
        } elseif ($action === 'clarify' && $session->status === 'awaiting_approval') {
            // Operator wants to add more info before approving.
            // Delete the preview, switch back to clarification, and wait for text.
            $this->telegram->answerCallbackQuery($callback['id'], '📝 منتظر توضیحات شما هستم...');
            $this->deleteCallbackMessage($callback['message'] ?? []);
            $previousAnalysis = $session->ai_analysis_result ?? [];
            $previousQuestion = $session->clarification_question;
            // Preserve original AI question if it exists; otherwise use a meaningful prompt.
            $contextQuestion = $previousQuestion && $previousQuestion !== ''
                ? $previousQuestion
                : 'چه اطلاعات تکمیلی می‌توانید درباره این گزارش ارائه دهید؟';
            $session->update([
                'status' => 'awaiting_clarification',
                'clarification_question' => $contextQuestion,
                'ai_analysis_result' => array_merge($session->ai_analysis_result ?? [], [
                    'clarification_needed' => true,
                    'operator_requested_clarification' => true,
                ]),
            ]);
            $sentMessage = $this->telegram->sendMessage(
                $session->telegram_chat_id,
                '📝 <b>لطفاً توضیحات تکمیلی خود را ارسال کنید.</b>'
            );
            $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
        } elseif ($action === 'cancel' && in_array($session->status, ['awaiting_approval', 'awaiting_clarification'], true)) {
            $this->telegram->answerCallbackQuery($callback['id'], '🗑️ گزارش لغو شد.');
            $session->update(['status' => 'cancelled']);
            $this->deleteSessionMessages($session, $callback['message'] ?? []);
        } elseif ($action === 'assignee') {
            $this->telegram->answerCallbackQuery($callback['id'], '👤 در حال آماده‌سازی فهرست مسئولان...');
            $users = SupportUser::query()->assignable()->orderBy('id')->get();
            if ($users->isEmpty()) {
                $this->telegram->answerCallbackQuery($callback['id'], '⚠️ هنوز مسئولی تعریف نشده است.');
                $this->telegram->sendMessage(
                    $session->telegram_chat_id,
                    '⚠️ هنوز کاربری به عنوان مسئول تعریف نشده است.'
                );

                return;
            }
            $current = $session->assignee;
            $text = '👤 <b>انتخاب مسئول تیکت:</b>';
            if ($current) {
                $text .= "\n‏\n🚦 مسئول فعلی: 👤 <b>".e($current->name).'</b>'.$current->taggingText();
            }
            $sentMessage = $this->telegram->sendMessage(
                $session->telegram_chat_id,
                $text,
                $this->telegram->assigneeListKeyboard($session->session_id, $users)
            );
            $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
        } else {
            $this->telegram->answerCallbackQuery($callback['id'], '⚠️ این گزینه دیگر قابل استفاده نیست.');
        }
    }

    /**
     * Persist the assignee chosen from the picker buttons and confirm to the
     * operator. The selection is only valid while the session is still being
     * collected or awaiting approval.
     */
    private function handleAssigneePick(array $callback, ?string $sessionId, int $userId): void
    {
        if (! $sessionId || $userId <= 0) {
            $this->telegram->answerCallbackQuery($callback['id'], '⚠️ درخواست نامعتبر است.');

            return;
        }

        $session = IncidentIntakeSession::query()
            ->where('session_id', $sessionId)
            ->whereIn('status', ['awaiting_approval', 'collecting'])
            ->first();
        $user = SupportUser::query()->assignable()->find($userId);

        if (! $session || ! $user) {
            $this->telegram->answerCallbackQuery($callback['id'], '⚠️ مسئول انتخاب‌شده معتبر نیست.');

            return;
        }

        $session->update(['selected_assignee_id' => $user->id]);
        $this->telegram->answerCallbackQuery($callback['id'], '✅ مسئول ثبت شد: '.$user->name);
        // Remove both the assignee picker message and the previous preview so a
        // fresh preview reflecting the new assignee can be sent again.
        $this->deleteCallbackMessage($callback['message'] ?? []);
        if ($session->preview_message_id) {
            $this->deleteTelegramMessage($session->telegram_chat_id, $session->preview_message_id);
            $session->update(['preview_message_id' => null]);
        }

        if ($session->status === 'awaiting_approval') {
            $analysis = $session->ai_analysis_result ?? [];
            $text = TelegramBotService::previewText($analysis, $user, null);
            $sentMessage = $this->telegram->sendMessage(
                $session->telegram_chat_id,
                $text,
                $this->telegram->previewKeyboard($session->session_id)
            );
            $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
            $session->update(['preview_message_id' => $sentMessage['result']['message_id'] ?? null]);
        } else {
            $this->telegram->sendMessage(
                $session->telegram_chat_id,
                '✅ <b>مسئول تیکت ثبت شد:</b> 👤 '.e($user->name).'| دسته‌ها: <b>'.e($user->coveredCategoryLabelText()).'</b>'.$user->taggingText()
            );
        }
    }

    /**
     * Remove the bot preview and forwarded messages belonging to a cancelled
     * session. Telegram can reject individual deletions (for example in a
     * group without rights), so each deletion is intentionally best-effort.
     */
    private function deleteSessionMessages(IncidentIntakeSession $session, array $callbackMessage): void
    {
        $chatId = $session->telegram_chat_id;

        if (isset($callbackMessage['chat']['id'], $callbackMessage['message_id'])
            && (string) $callbackMessage['chat']['id'] === (string) $chatId) {
            $this->deleteTelegramMessage($chatId, $callbackMessage['message_id']);
        }

        $session->messages()->pluck('telegram_message_id')->each(
            fn (string $messageId) => $this->deleteTelegramMessage($chatId, $messageId)
        );

        collect($session->telegram_bot_message_ids ?? [])->each(
            fn (string $messageId) => $this->deleteTelegramMessage($chatId, $messageId)
        );
    }

    private function deleteCallbackMessage(array $callbackMessage): void
    {
        if (! isset($callbackMessage['chat']['id'], $callbackMessage['message_id'])) {
            return;
        }

        $this->deleteTelegramMessage($callbackMessage['chat']['id'], $callbackMessage['message_id']);
    }

    private function deleteTelegramMessage(string|int $chatId, string|int $messageId): void
    {
        try {
            $this->telegram->deleteMessage($chatId, $messageId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
