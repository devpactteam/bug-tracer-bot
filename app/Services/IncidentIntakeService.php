<?php

namespace App\Services;

use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Models\IntakeMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentIntakeService
{
    public function appendMessage(array $message, string|int $chatId, string|int $operatorId): IncidentIntakeSession
    {
        return Cache::lock("incident-intake:{$chatId}", 10)->block(5, function () use ($message, $chatId, $operatorId): IncidentIntakeSession {
            return DB::transaction(function () use ($message, $chatId, $operatorId): IncidentIntakeSession {
                $session = IncidentIntakeSession::query()
                    ->where('telegram_chat_id', (string) $chatId)
                    ->whereIn('status', ['collecting', 'awaiting_clarification'])
                    ->latest('id')->first();

                if (!$session) {
                    $session = IncidentIntakeSession::create([
                        'session_id' => (string) Str::uuid(),
                        'telegram_chat_id' => (string) $chatId,
                        'operator_telegram_id' => (string) $operatorId,
                        'status' => 'collecting',
                    ]);
                }

                IntakeMessage::query()->firstOrCreate(
                    ['session_id' => $session->session_id, 'telegram_message_id' => (string) ($message['message_id'] ?? Str::uuid())],
                    [
                        'content' => $message['content'] ?? null,
                        'media_type' => $message['media_type'] ?? null,
                        'media_file_id' => $message['media_file_id'] ?? null,
                        'forward_origin_metadata' => $message['forward_origin_metadata'] ?? null,
                    ]
                );
                $session->touch();

                return $session->fresh('messages');
            });
        });
    }

    public function finalize(string $sessionId): void
    {
        ProcessIncidentBundleJob::dispatch($sessionId, true)->onQueue(config('incident.intake.queue'));
    }

    public function setStatus(string $sessionId, string $from, string $to): bool
    {
        return IncidentIntakeSession::query()->where('session_id', $sessionId)
            ->where('status', $from)->update(['status' => $to]) === 1;
    }
}
