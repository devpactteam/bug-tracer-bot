<?php

namespace Tests\Feature\Panel;

use App\Models\IncidentIntakeSession;
use App\Models\IntakeMessage;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntakeSessionPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $id, string $status): IncidentIntakeSession
    {
        return IncidentIntakeSession::query()->create([
            'session_id' => $id,
            'telegram_chat_id' => '1001',
            'operator_telegram_id' => '2002',
            'status' => $status,
        ]);
    }

    public function test_sessions_page_defaults_to_open_sessions(): void
    {
        $open = $this->makeSession('session-open', 'collecting');
        $completed = $this->makeSession('session-completed', 'completed');

        $this->get('/panel/sessions')
            ->assertOk()
            ->assertSee($open->session_id)
            ->assertDontSee($completed->session_id)
            ->assertSee('سشن‌های باز');

        $this->get('/panel/sessions?scope=all')
            ->assertOk()
            ->assertSee($open->session_id)
            ->assertSee($completed->session_id);
    }

    public function test_active_session_can_be_closed_from_the_panel(): void
    {
        $session = $this->makeSession('session-to-close', 'awaiting_approval');
        $session->update([
            'preview_message_id' => '10',
            'telegram_bot_message_ids' => ['10', '11'],
        ]);
        IntakeMessage::query()->create([
            'session_id' => $session->session_id,
            'telegram_message_id' => '9',
            'content' => 'گزارش مشکل',
        ]);

        $telegram = $this->mock(TelegramBotService::class);
        $telegram->shouldReceive('deleteMessage')->once()->with('1001', '9')->andReturn(['ok' => true]);
        $telegram->shouldReceive('deleteMessage')->once()->with('1001', '10')->andReturn(['ok' => true]);
        $telegram->shouldReceive('deleteMessage')->once()->with('1001', '11')->andReturn(['ok' => true]);

        $this->post('/panel/sessions/'.$session->id.'/close')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('incident_intake_sessions', [
            'id' => $session->id,
            'status' => 'cancelled',
            'preview_message_id' => null,
        ]);
    }
}
