<?php

namespace Tests\Feature;

use App\Models\IncidentIntakeSession;
use App\Models\IntakeMessage;
use App\Services\FakeAiAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiAnalysisAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_logs_ai_input_and_output(): void
    {
        $session = IncidentIntakeSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'telegram_chat_id' => '1001',
            'operator_telegram_id' => '2002',
            'status' => 'collecting',
        ]);
        IntakeMessage::query()->create([
            'session_id' => $session->session_id,
            'telegram_message_id' => '3003',
            'content' => 'سامانه پرداخت برای همه کاربران قطع است.',
        ]);

        $result = app(FakeAiAnalysisService::class)->analyze($session->fresh('messages'), 'initial');

        $this->assertSame('گزارش مشکل مشتری', $result['title']);
        $this->assertDatabaseHas('ai_incident_analysis_logs', [
            'session_id' => $session->session_id,
            'phase' => 'initial',
            'provider' => 'fake',
            'status' => 'succeeded',
            'operator_telegram_id' => '2002',
        ]);

        $log = $session->aiAnalysisLogs()->firstOrFail();
        $this->assertSame('سامانه پرداخت برای همه کاربران قطع است.', $log->request_payload['messages'][0]['content']);
        $this->assertSame($result, $log->normalized_response);
    }
}
