<?php

namespace App\Jobs;

use App\Models\IncidentIntakeSession;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClarificationTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $sessionId) {}

    public function handle(TelegramBotService $telegram): void
    {
        $session = IncidentIntakeSession::query()->where('session_id', $this->sessionId)->first();
        if (! $session || $session->status !== 'awaiting_clarification') {
            return;
        }

        $analysis = $session->ai_analysis_result ?? [];
        $analysis['clarification_needed'] = false;
        $analysis['scope'] = $analysis['scope'] ?? 'unknown';
        $session->update([
            'ai_analysis_result' => $analysis,
            'clarification_question' => null,
            'status' => 'awaiting_approval',
        ]);
        $sentMessage = $telegram->sendMessage(
            $session->telegram_chat_id,
            "⏰ <b>زمان دریافت توضیح بیشتر تمام شد.</b>\n📋 پیش‌نمایش تقریبی گزارش را بررسی کنید.",
            $telegram->previewKeyboard($session->session_id)
        );
        $session->rememberBotMessageId($sentMessage['result']['message_id'] ?? null);
    }
}
