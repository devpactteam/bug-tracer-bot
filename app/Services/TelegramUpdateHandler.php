<?php

namespace App\Services;

use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Models\ProcessedTelegramUpdate;
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
        if ($updateId <= 0 || !$this->claimUpdate($updateId)) {
            return;
        }

        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $operatorId = (string) ($message['from']['id'] ?? '');
        if (!$this->authorized($operatorId)) {
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
        if (preg_match('/^\/assignee\s+(\d+)$/i', $text, $matches)) {
            $session = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)
                ->whereIn('status', ['awaiting_approval', 'collecting'])->latest('id')->first();
            if ($session) {
                $session->update(['selected_assignee_id' => (int) $matches[1]]);
                $this->telegram->sendMessage($chatId, '✅ مسئول تیکت با موفقیت تغییر کرد.');
            }
            return;
        }

        $awaiting = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)
            ->where('status', 'awaiting_clarification')->latest('id')->first();
        if ($awaiting && $text !== '') {
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

    private function handleCallback(array $callback): void
    {
        $operatorId = (string) ($callback['from']['id'] ?? '');
        if (!$this->authorized($operatorId)) return;
        $data = explode(':', (string) ($callback['data'] ?? ''), 3);
        $sessionId = $data[2] ?? null;
        if (($data[0] ?? '') !== 'incident' || !$sessionId) return;
        $session = IncidentIntakeSession::query()->where('session_id', $sessionId)->first();
        if (!$session) return;

        $action = $data[1] ?? '';
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
            $this->telegram->answerCallbackQuery($callback['id'], '📝 در حال ایجاد تیکت...');
            ProcessIncidentBundleJob::createTicket($sessionId);
        } elseif ($action === 'cancel' && in_array($session->status, ['awaiting_approval', 'awaiting_clarification'], true)) {
            $this->telegram->answerCallbackQuery($callback['id'], '🗑️ گزارش لغو شد.');
            $session->update(['status' => 'cancelled']);
            $this->deleteSessionMessages($session, $callback['message'] ?? []);
        } elseif ($action === 'assignee') {
            $this->telegram->answerCallbackQuery($callback['id'], '👤 شناسه مسئول را با دستور /assignee ارسال کنید.');
        } else {
            $this->telegram->answerCallbackQuery($callback['id'], '⚠️ این گزینه دیگر قابل استفاده نیست.');
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
        if (!isset($callbackMessage['chat']['id'], $callbackMessage['message_id'])) {
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
