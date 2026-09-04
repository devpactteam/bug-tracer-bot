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

    public function __construct(
        public readonly string $sessionId,
        public readonly bool $force = false,
        public readonly bool $clarificationCheck = false,
        public readonly bool $finalizeClarifications = false,
    ) {}

    public function handle(AiIncidentAnalysisInterface $ai, TelegramBotService $telegram): void
    {
        $allowedStatuses = ($this->clarificationCheck || $this->finalizeClarifications)
            ? ['awaiting_clarification']
            : ['collecting', 'analyzing'];
        $session = IncidentIntakeSession::query()->where('session_id', $this->sessionId)->first();
        if (!$session || !in_array($session->status, $allowedStatuses, true)) {
            return;
        }

        $isFresh = $session->updated_at?->gt(
            now()->subSeconds((int) config('incident.intake.debounce_seconds'))
        );

        // The sync driver executes a redispatch immediately. Never redispatch
        // from inside the lock in that mode, otherwise it deadlocks itself.
        if (!$this->force && $isFresh && config('queue.default') !== 'sync') {
            self::dispatch($this->sessionId)
                ->delay(now()->addSeconds((int) config('incident.intake.debounce_seconds')))
                ->onQueue(config('incident.intake.queue'));
            return;
        }

        Cache::lock("incident-analysis:{$this->sessionId}", 60)->block(10, function () use ($ai, $telegram, $allowedStatuses): void {
            $session = IncidentIntakeSession::query()->with('messages')
                ->where('session_id', $this->sessionId)->first();
            if (!$session || !in_array($session->status, $allowedStatuses, true)) return;
            $previousStatus = $session->status;
            $session->update(['status' => 'analyzing']);
            try {
                $result = $ai->analyze($session->fresh('messages'), $this->analysisPhase());
            } catch (Throwable $exception) {
                report($exception);
                $session->update(['status' => $previousStatus]);
                $telegram->sendMessage(
                    $session->telegram_chat_id,
                    '⚠️ تحلیل این مجموعه فعلاً انجام نشد. لطفاً چند لحظه بعد دوباره 🚀 نهایی‌سازی کنید.'
                );
                throw $exception;
            }
            $previousAnalysis = $session->ai_analysis_result ?? [];
            $round = (int) ($previousAnalysis['clarification_round'] ?? 0);
            $previousQuestion = $session->clarification_question;
            $newQuestion = $result['clarification_question'] ?? null;
            $isRepeatedQuestion = $this->clarificationCheck
                && is_string($previousQuestion)
                && $previousQuestion !== ''
                && is_string($newQuestion)
                && $this->normalizeQuestion($previousQuestion) === $this->normalizeQuestion($newQuestion);

            if ($result['clarification_needed']) {
                $round++;
                $result['clarification_round'] = $round;
            }

            // Do not trap the operator in a loop when an AI provider repeats
            // the same question or exceeds the configured clarification limit.
            if ($this->clarificationCheck && (
                $isRepeatedQuestion
                || $round >= (int) config('incident.intake.max_clarification_rounds')
            )) {
                $result['clarification_needed'] = false;
                $result['clarification_question'] = null;
            }
            if (!$result['clarification_needed'] && $this->clarificationCheck) {
                $result['clarification_ready_for_finalization'] = true;
                $session->update([
                    'ai_analysis_result' => $result,
                    'clarification_question' => null,
                    'status' => 'awaiting_clarification',
                ]);
                $sentMessage = $telegram->sendMessage(
                    $session->telegram_chat_id,
                    '✅ <b>توضیحات کافی دریافت شد.</b>'."\n"
                    .'برای دریافت تحلیل نهایی، روی دکمه زیر بزنید.',
                    $telegram->clarificationCompleteKeyboard($session->session_id)
                );
                $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
                return;
            }

            $session->update([
                'ai_analysis_result' => $result,
                'clarification_question' => $result['clarification_question'] ?? null,
                'status' => $result['clarification_needed'] ? 'awaiting_clarification' : 'awaiting_approval',
            ]);
            // Delayed jobs are executed immediately by Laravel's sync driver,
            // so do not schedule a timeout in local synchronous mode.
            if ($result['clarification_needed'] && config('queue.default') !== 'sync') {
                ClarificationTimeoutJob::dispatch($session->session_id)
                    ->delay(now()->addMinutes((int) config('incident.intake.clarification_timeout_minutes')))
                    ->onQueue(config('incident.intake.queue'));
            }
            $text = $result['clarification_needed']
                ? "🔎 <b>نیاز به توضیح بیشتر</b>\n💬 ".e($result['clarification_question'])
                : "📋 <b>پیش‌نمایش گزارش</b>\n🏷️ <b>".e($result['title'])."</b>\n📝 ".e($result['summary']);
            $sentMessage = $telegram->sendMessage(
                $session->telegram_chat_id,
                $text,
                $result['clarification_needed'] ? null : $telegram->previewKeyboard($session->session_id)
            );
            $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
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

    private function normalizeQuestion(string $question): string
    {
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower($question))) ?? '';
    }

    private function analysisPhase(): string
    {
        return match (true) {
            $this->finalizeClarifications => 'final',
            $this->clarificationCheck => 'clarification',
            default => 'initial',
        };
    }
}
