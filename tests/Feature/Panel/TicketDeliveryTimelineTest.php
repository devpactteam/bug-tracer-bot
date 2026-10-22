<?php

namespace Tests\Feature\Panel;

use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;
use Illuminate\Support\Str;

class TicketDeliveryTimelineTest extends PanelTestCase
{
    public function test_ticket_timeline_shows_safe_telegram_delivery_details(): void
    {
        $session = IncidentIntakeSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'telegram_chat_id' => 100,
            'operator_telegram_id' => 200,
            'status' => 'completed',
        ]);
        $ticket = IncidentTicket::query()->create([
            'ticket_number' => 'INC-DELIVERY',
            'session_id' => $session->session_id,
            'title' => 'Delivery test ticket',
            'description' => 'Ticket description',
            'scope' => 'system_wide',
            'category' => 'backend',
            'priority' => 'normal',
            'status' => 'open',
        ]);
        $ticket->log(
            'telegram_delivery',
            'پیام تیکت — ارسال ناموفق بود: کد خطای تلگرام: 403.',
            'مسئول تست',
            'message',
            'failed',
        );

        $this->get('/panel/tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('ارسال به تلگرام')
            ->assertSee('پیام تیکت')
            ->assertSee('ناموفق')
            ->assertSee('گیرنده')
            ->assertSee('مسئول تست')
            ->assertSee('کد خطای تلگرام: 403.');
    }
}
