<?php

namespace Tests\Feature;

use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;
use App\Models\IntakeMessage;
use App\Models\SupportUser;
use App\Services\AssigneeNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketTelegramDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'incident.assignee_bot.token' => 'assignee-test-token',
            'incident.assignee_bot.api_base_url' => 'https://api.telegram.org',
            'incident.telegram.gateway_url' => 'https://gateway.test/send',
        ]);
    }

    public function test_it_logs_an_accepted_message_before_the_request_without_storing_ticket_content(): void
    {
        $assignee = $this->makeAssignee();
        $ticket = $this->makeTicket($assignee, ['title' => 'private title', 'description' => 'private description']);
        Http::fake([
            'https://gateway.test/send*' => function (Request $request) use ($ticket) {
                $this->assertDatabaseHas('ticket_logs', [
                    'ticket_id' => $ticket->id,
                    'action' => 'telegram_delivery',
                    'from_value' => 'message',
                    'to_value' => 'attempted',
                ]);

                return Http::response(['ok' => true]);
            },
        ]);

        $result = app(AssigneeNotificationService::class)->notifyAssignee($ticket, $assignee);

        $this->assertTrue($result);
        $log = $ticket->ticketLogs()->where('action', 'telegram_delivery')->firstOrFail();
        $this->assertSame('accepted', $log->to_value);
        $this->assertSame('message', $log->from_value);
        $this->assertSame($assignee->name, $log->actor);
        $this->assertStringNotContainsString('private title', $log->description);
        $this->assertStringNotContainsString('private description', $log->description);
        $this->assertNull($ticket->fresh()->assignee_message_id);
    }

    public function test_it_logs_a_sanitized_gateway_failure(): void
    {
        $assignee = $this->makeAssignee();
        $ticket = $this->makeTicket($assignee, ['title' => 'private title']);
        Http::fake(['https://gateway.test/send*' => Http::response([
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user; token=secret-value private title',
        ])]);

        $result = app(AssigneeNotificationService::class)->notifyAssignee($ticket, $assignee);

        $this->assertFalse($result);
        $log = $ticket->ticketLogs()->where('action', 'telegram_delivery')->firstOrFail();
        $this->assertSame('failed', $log->to_value);
        $this->assertStringContainsString('403', $log->description);
        $this->assertStringContainsString('کاربر ربات را مسدود کرده است', $log->description);
        $this->assertStringNotContainsString('secret-value', $log->description);
        $this->assertStringNotContainsString('private title', $log->description);
    }

    public function test_it_logs_delivery_as_skipped_without_a_chat_destination(): void
    {
        $ticket = $this->makeTicket();
        Http::fake();

        $result = app(AssigneeNotificationService::class)->notifyAssignee($ticket, null);

        $this->assertFalse($result);
        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $ticket->id,
            'action' => 'telegram_delivery',
            'from_value' => 'message',
            'to_value' => 'skipped',
        ]);
        Http::assertNothingSent();
    }

    public function test_it_logs_delivery_as_skipped_when_the_assignee_has_no_chat_id(): void
    {
        $assignee = $this->makeAssignee();
        $assignee->update(['assignee_chat_id' => null]);
        $ticket = $this->makeTicket($assignee);
        Http::fake();

        $result = app(AssigneeNotificationService::class)->notifyAssignee($ticket, $assignee);

        $this->assertFalse($result);
        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $ticket->id,
            'action' => 'telegram_delivery',
            'actor' => $assignee->name,
            'from_value' => 'message',
            'to_value' => 'skipped',
        ]);
        Http::assertNothingSent();
    }

    public function test_it_logs_each_photo_outcome_separately_and_continues_after_a_failure(): void
    {
        Storage::fake('public');
        $assignee = $this->makeAssignee();
        $ticket = $this->makeTicket($assignee);
        $this->addPhoto($ticket, 'first.jpg', 'first image bytes');
        $this->addPhoto($ticket, 'second.jpg', 'second image bytes');
        $photoRequest = 0;

        Http::fake([
            'https://gateway.test/send*' => Http::response(['ok' => true]),
            'https://api.telegram.org/*' => function () use (&$photoRequest) {
                $photoRequest++;

                return $photoRequest === 1
                    ? Http::response(['ok' => true, 'result' => ['message_id' => 10]])
                    : Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found; private caption'], 400);
            },
        ]);

        app(AssigneeNotificationService::class)->notifyAssignee($ticket, $assignee);

        $photoLogs = $ticket->ticketLogs()
            ->where('action', 'telegram_delivery')
            ->where('from_value', 'photo')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $photoLogs);
        $this->assertSame(['accepted', 'failed'], $photoLogs->pluck('to_value')->all());
        $this->assertStringNotContainsString('first.jpg', $photoLogs[0]->description);
        $this->assertStringNotContainsString('second.jpg', $photoLogs[1]->description);
        $this->assertStringNotContainsString('private caption', $photoLogs[1]->description);
        $this->assertStringContainsString('HTTP 400', $photoLogs[1]->description);
        $this->assertStringContainsString('گفتگوی تلگرام پیدا نشد', $photoLogs[1]->description);
        $this->assertSame(2, $photoRequest);
    }

    public function test_registration_retries_only_tickets_without_an_accepted_message_event(): void
    {
        $assignee = $this->makeAssignee();
        $acceptedTicket = $this->makeTicket($assignee);
        $acceptedTicket->log('telegram_delivery', 'Accepted', $assignee->name, 'message', 'accepted');
        $pendingTicket = $this->makeTicket($assignee);
        Http::fake(['https://gateway.test/send*' => Http::response(['ok' => true])]);

        $result = app(AssigneeNotificationService::class)->registerFromUpdate([
            'message' => [
                'chat' => ['id' => $assignee->assignee_chat_id],
                'from' => ['username' => $assignee->username],
                'text' => '/start',
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(1, $acceptedTicket->ticketLogs()->where('action', 'telegram_delivery')->count());
        $this->assertDatabaseHas('ticket_logs', [
            'ticket_id' => $pendingTicket->id,
            'action' => 'telegram_delivery',
            'from_value' => 'message',
            'to_value' => 'accepted',
        ]);
        Http::assertSentCount(2); // registration confirmation and one pending ticket
    }

    private function makeAssignee(): SupportUser
    {
        return SupportUser::query()->create([
            'name' => 'مسئول تست',
            'username' => 'delivery_agent',
            'assignee_chat_id' => 2002,
            'is_active' => true,
        ]);
    }

    private function makeTicket(?SupportUser $assignee = null, array $attributes = []): IncidentTicket
    {
        $session = IncidentIntakeSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'telegram_chat_id' => 100,
            'operator_telegram_id' => 200,
            'status' => 'completed',
        ]);

        return IncidentTicket::query()->create(array_merge([
            'ticket_number' => 'INC-'.strtoupper(Str::random(8)),
            'session_id' => $session->session_id,
            'title' => 'Ticket title',
            'description' => 'Ticket description',
            'scope' => 'system_wide',
            'category' => 'backend',
            'priority' => 'normal',
            'assignee_id' => $assignee?->id,
            'status' => 'open',
        ], $attributes));
    }

    private function addPhoto(IncidentTicket $ticket, string $filename, string $contents): void
    {
        $path = 'ticket-photos/'.$ticket->session_id.'/'.$filename;
        Storage::disk('public')->put($path, $contents);
        IntakeMessage::query()->create([
            'session_id' => $ticket->session_id,
            'telegram_message_id' => $filename,
            'media_type' => 'photo',
            'media_file_id' => 'file-'.$filename,
            'media_local_path' => $path,
        ]);
    }
}
