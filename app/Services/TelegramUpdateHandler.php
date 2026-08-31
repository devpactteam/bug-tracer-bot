<?php

namespace App\Services;

use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Models\ProcessedTelegramUpdate;
use Illuminate\Database\QueryException;
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
            $this->telegram->sendMessage($chatId, 'Forward incident messages, then press <b>Finalize &amp; Analyze</b>.');
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
                $this->telegram->sendMessage($chatId, 'Assignee updated.');
            }
            return;
        }

        $awaiting = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)
            ->where('status', 'awaiting_clarification')->latest('id')->first();
        if ($awaiting && $text !== '') {
            $awaiting->update(['status' => 'collecting', 'clarification_question' => null]);
        }
        $this->intake->appendMessage($this->normalizeMessage($message), $chatId, $operatorId);
        $session = IncidentIntakeSession::query()->where('telegram_chat_id', $chatId)->latest('id')->first();
        $this->telegram->sendMessage(
            $chatId,
            'Message added. Continue forwarding or finalize when ready.',
            $session ? $this->telegram->finalizeKeyboard($session->session_id) : null
        );
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
            $this->intake->finalize($sessionId);
            $this->telegram->answerCallbackQuery($callback['id'], 'Analysis queued.');
        } elseif ($action === 'approve' && $session->status === 'awaiting_approval') {
            ProcessIncidentBundleJob::createTicket($sessionId);
            $this->telegram->answerCallbackQuery($callback['id'], 'Ticket created.');
        } elseif ($action === 'cancel' && in_array($session->status, ['awaiting_approval', 'awaiting_clarification'], true)) {
            $session->update(['status' => 'cancelled']);
            $this->telegram->answerCallbackQuery($callback['id'], 'Incident cancelled.');
        } elseif ($action === 'assignee') {
            $this->telegram->answerCallbackQuery($callback['id'], 'Send the assignee ID in chat.');
        } else {
            $this->telegram->answerCallbackQuery($callback['id'], 'This action is no longer available.');
        }
    }
}
