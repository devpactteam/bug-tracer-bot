<?php

namespace Tests\Feature;

use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;
use App\Models\IntakeMessage;
use App\Services\IncidentIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_intake_stores_forwarded_photo_locally(): void
    {
        Storage::fake('public');
        Http::fake([
            '*/getFile' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_123.jpg']]),
            '*/file/bot*' => Http::response('fakeimagebytes'),
        ]);

        $service = app(IncidentIntakeService::class);
        $session = $service->appendMessage([
            'message_id' => '111',
            'content' => null,
            'media_type' => 'photo',
            'media_file_id' => 'AgACxxxxx',
            'forward_origin_metadata' => null,
        ], 100, 200);

        $message = IntakeMessage::query()->where('session_id', $session->session_id)->first();

        $this->assertNotNull($message);
        $this->assertSame('photo', $message->media_type);
        $this->assertNotNull($message->media_local_path);
        $this->assertTrue(Storage::disk('public')->exists($message->media_local_path));
    }

    public function test_ticket_exposes_photo_paths_from_its_session(): void
    {
        $session = IncidentIntakeSession::query()->create([
            'session_id' => (string) Str::uuid(),
            'telegram_chat_id' => 100,
            'operator_telegram_id' => 200,
            'status' => 'completed',
        ]);
        IntakeMessage::query()->create([
            'session_id' => $session->session_id,
            'telegram_message_id' => '222',
            'media_type' => 'photo',
            'media_file_id' => 'AgACxxxxx',
            'media_local_path' => 'ticket-photos/'.$session->session_id.'/222.jpg',
        ]);
        IntakeMessage::query()->create([
            'session_id' => $session->session_id,
            'telegram_message_id' => '333',
            'media_type' => 'text',
            'content' => 'فقط متن',
        ]);

        $ticket = IncidentTicket::query()->create([
            'ticket_number' => 'INC-PHOTO',
            'session_id' => $session->session_id,
            'title' => 'تیکت عکس‌دار',
            'description' => 'شرح',
            'scope' => 'system_wide',
            'category' => 'backend',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->assertSame([$session->session_id.'/222.jpg'], [
            Str::after($ticket->photoPaths()[0], 'ticket-photos/'),
        ]);
        $this->assertCount(1, $ticket->photoPaths());
    }
}
