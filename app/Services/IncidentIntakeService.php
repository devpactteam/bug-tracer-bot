<?php

namespace App\Services;

use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Models\IntakeMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class IncidentIntakeService
{
    public function __construct(
        private readonly TelegramBotService $telegram,
    ) {}

    public function appendMessage(array $message, string|int $chatId, string|int $operatorId): IncidentIntakeSession
    {
        return Cache::lock("incident-intake:{$chatId}", 10)->block(5, function () use ($message, $chatId, $operatorId): IncidentIntakeSession {
            return DB::transaction(function () use ($message, $chatId, $operatorId): IncidentIntakeSession {
                $session = IncidentIntakeSession::query()
                    ->where('telegram_chat_id', (string) $chatId)
                    ->whereIn('status', ['collecting', 'awaiting_clarification'])
                    ->latest('id')->first();

                if (! $session) {
                    $session = IncidentIntakeSession::create([
                        'session_id' => (string) Str::uuid(),
                        'telegram_chat_id' => (string) $chatId,
                        'operator_telegram_id' => (string) $operatorId,
                        'status' => 'collecting',
                    ]);
                }

                $message = IntakeMessage::query()->firstOrCreate(
                    ['session_id' => $session->session_id, 'telegram_message_id' => (string) ($message['message_id'] ?? Str::uuid())],
                    [
                        'content' => $message['content'] ?? null,
                        'media_type' => $message['media_type'] ?? null,
                        'media_file_id' => $message['media_file_id'] ?? null,
                        'forward_origin_metadata' => $message['forward_origin_metadata'] ?? null,
                    ]
                );
                if ($message->media_type === 'photo' && $message->media_file_id !== null && $message->media_local_path === null) {
                    $this->storePhoto($message);
                }
                $session->touch();

                return $session->fresh('messages');
            });
        });
    }

    /**
     * Download the photo behind a forwarded message and persist it on the
     * public disk so it can be delivered to the assignee with the ticket.
     * Failures are reported but never block message intake.
     */
    private function storePhoto(IntakeMessage $message): void
    {
        try {
            $filePath = $this->telegram->getFile($message->media_file_id);
            $bytes = is_string($filePath) ? $this->telegram->downloadFile($filePath) : null;
            if (! is_string($bytes) || $bytes === '') {
                return;
            }
            $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'jpg';
            $relative = 'ticket-photos/'.$message->session_id.'/'.(int) $message->telegram_message_id.'.'.$extension;
            Storage::disk('public')->put($relative, $bytes);
            $message->update(['media_local_path' => $relative]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function finalize(string $sessionId, bool $finalizeClarifications = false): void
    {
        ProcessIncidentBundleJob::dispatch($sessionId, true, false, $finalizeClarifications)
            ->onQueue(config('incident.intake.queue'));
    }

    public function setStatus(string $sessionId, string $from, string $to): bool
    {
        return IncidentIntakeSession::query()->where('session_id', $sessionId)
            ->where('status', $from)->update(['status' => $to]) === 1;
    }
}
