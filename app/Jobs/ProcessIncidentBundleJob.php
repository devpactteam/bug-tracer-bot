<?php

namespace App\Jobs;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessIncidentBundleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 120];

    public function __construct(public readonly string $sessionId) {}

    public function handle(AiIncidentAnalysisInterface $ai, TelegramBotService $telegram): void
    {
        Cache::lock("incident-analysis:{$this->sessionId}", 60)->block(10, function () use ($ai, $telegram): void {
            $session = IncidentIntakeSession::query()->with('messages')
                ->where('session_id', $this->sessionId)->first();
            if (!$session || !in_array($session->status, ['collecting', 'analyzing'], true)) return;
            if ($session->updated_at?->gt(now()->subSeconds((int) config('incident.intake.debounce_seconds')))) {
                self::dispatch($this->sessionId)
                    ->delay(now()->addSeconds((int) config('incident.intake.debounce_seconds')))
                    ->onQueue(config('incident.intake.queue'));
                return;
            }
            $session->update(['status' => 'analyzing']);
            try {
                $result = $ai->analyze($session->fresh('messages'));
            } catch (Throwable $exception) {
                report($exception);
                $session->update(['status' => 'collecting']);
                $telegram->sendMessage(
                    $session->telegram_chat_id,
                    'I could not analyze this bundle yet. Please finalize again in a moment.'
                );
                throw $exception;
            }
            $session->update([
                'ai_analysis_result' => $result,
                'clarification_question' => $result['clarification_question'] ?? null,
                'status' => $result['clarification_needed'] ? 'awaiting_clarification' : 'awaiting_approval',
            ]);
            if ($result['clarification_needed']) {
                ClarificationTimeoutJob::dispatch($session->session_id)
                    ->delay(now()->addMinutes((int) config('incident.intake.clarification_timeout_minutes')));
            }
            $text = $result['clarification_needed']
                ? "<b>Clarification needed</b>\n".e($result['clarification_question'])
                : "<b>Incident preview</b>\n<b>{$result['title']}</b>\n".e($result['summary']);
            $telegram->sendMessage($session->telegram_chat_id, $text, $result['clarification_needed'] ? null : $telegram->previewKeyboard($session->session_id));
        });
    }

    public static function createTicket(string $sessionId): ?IncidentTicket
    {
        return DB::transaction(function () use ($sessionId): ?IncidentTicket {
            $session = IncidentIntakeSession::query()->where('session_id', $sessionId)->lockForUpdate()->first();
            if (!$session || $session->status !== 'awaiting_approval' || IncidentTicket::query()->where('session_id', $sessionId)->exists()) return null;
            $a = $session->ai_analysis_result ?? [];
            $ticket = IncidentTicket::create([
                'ticket_number' => 'INC-'.strtoupper(Str::random(8)),
                'session_id' => $session->session_id,
                'title' => $a['title'] ?? 'Incident',
                'description' => $a['summary'] ?? '',
                'scope' => $a['scope'] ?? 'unknown',
                'sample_data' => $a['sample_data'] ?? [],
                'category' => $a['category'] ?? null,
                'priority' => $a['priority'] ?? 'normal',
                'assignee_id' => $session->selected_assignee_id,
                'status' => 'open',
            ]);
            $session->update(['status' => 'completed']);
            return $ticket;
        });
    }
}
