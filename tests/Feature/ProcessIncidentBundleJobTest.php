<?php

namespace Tests\Feature;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Jobs\ProcessIncidentBundleJob;
use App\Models\IncidentIntakeSession;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProcessIncidentBundleJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_analysis_sends_a_retry_finalize_button(): void
    {
        $session = IncidentIntakeSession::query()->create([
            'session_id' => 'session-analysis-failure',
            'telegram_chat_id' => '1001',
            'operator_telegram_id' => '2002',
            'status' => 'collecting',
        ]);
        $ai = $this->mock(AiIncidentAnalysisInterface::class);
        $ai->shouldReceive('analyze')->once()->andThrow(new RuntimeException('AI unavailable'));
        $telegram = $this->mock(TelegramBotService::class);
        $telegram->shouldReceive('finalizeKeyboard')
            ->once()
            ->with($session->session_id)
            ->andReturn(['inline_keyboard' => [
                [['text' => '🚀 نهایی‌سازی و تحلیل', 'callback_data' => "incident:finalize:{$session->session_id}"]],
            ]]);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(
                '1001',
                '⚠️ تحلیل این مجموعه فعلاً انجام نشد. لطفاً چند لحظه بعد دوباره روی دکمه زیر بزنید.',
                ['inline_keyboard' => [
                    [['text' => '🚀 نهایی‌سازی و تحلیل', 'callback_data' => "incident:finalize:{$session->session_id}"]],
                ]]
            )
            ->andReturn(['ok' => true]);

        try {
            (new ProcessIncidentBundleJob($session->session_id, true))->handle($ai, $telegram);
            $this->fail('The failed AI analysis must be rethrown for the queue retry policy.');
        } catch (RuntimeException) {
            // Expected: the queue should still apply its retry policy.
        }

        $this->assertDatabaseHas('incident_intake_sessions', [
            'session_id' => $session->session_id,
            'status' => 'collecting',
        ]);
    }
}
