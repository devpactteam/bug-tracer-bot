<?php

namespace Tests\Feature\Panel;

use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;

class TicketPanelCloseTest extends PanelTestCase
{
    private function makeSession(string $sessionId): IncidentIntakeSession
    {
        return IncidentIntakeSession::query()->create([
            'session_id' => $sessionId,
            'telegram_chat_id' => 1,
            'operator_telegram_id' => 2,
            'status' => 'completed',
        ]);
    }

    public function test_it_closes_an_open_ticket(): void
    {
        $session = $this->makeSession('session-test-1');
        $ticket = IncidentTicket::query()->create([
            'ticket_number' => 'INC-TEST01',
            'session_id' => $session->session_id,
            'title' => 'تیکت تست بستن',
            'description' => 'شرح مشکل',
            'scope' => 'system_wide',
            'category' => 'backend',
            'priority' => 'high',
            'status' => 'open',
        ]);

        $this->post('/panel/tickets/'.$ticket->id.'/close')
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertSame('closed', $ticket->ticketLogs()->latest()->value('to_value'));
        $this->assertSame('closed', $ticket->ticketLogs()->latest()->value('action'));
    }

    public function test_reopening_is_ignored_for_closed_tickets(): void
    {
        $session = $this->makeSession('session-test-2');
        $ticket = IncidentTicket::query()->create([
            'ticket_number' => 'INC-TEST02',
            'session_id' => $session->session_id,
            'title' => 'تیکت بسته',
            'description' => 'شرح مشکل',
            'scope' => 'user_specific',
            'category' => 'client',
            'priority' => 'low',
            'status' => 'closed',
            'resolved_at' => now(),
        ]);
        $closedAt = $ticket->resolved_at;
        $logCount = $ticket->ticketLogs()->count();

        $this->post('/panel/tickets/'.$ticket->id.'/close')
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame('closed', $ticket->status);
        $this->assertSame($closedAt->timestamp, $ticket->resolved_at->timestamp);
        $this->assertSame($logCount, $ticket->ticketLogs()->count());
    }
}
