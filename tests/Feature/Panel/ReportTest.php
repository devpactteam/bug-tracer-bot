<?php

namespace Tests\Feature\Panel;

use App\Models\IncidentIntakeSession;
use App\Models\IncidentTicket;
use App\Models\SupportUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): SupportUser
    {
        return SupportUser::query()->create([
            'name' => 'کاربر گزارش',
            'username' => 'report_user',
            'role' => 'backend_developer',
            'categories_covered' => ['backend'],
            'can_be_assignee' => true,
            'is_active' => true,
        ]);
    }

    private function makeSession(): IncidentIntakeSession
    {
        return IncidentIntakeSession::query()->create([
            'session_id' => uniqid('sess-'),
            'telegram_chat_id' => 1,
            'operator_telegram_id' => 2,
            'status' => 'completed',
        ]);
    }

    private function makeTicket(int $index, SupportUser $user, string $status): void
    {
        $session = $this->makeSession();
        IncidentTicket::query()->create([
            'ticket_number' => 'INC-RPT'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'session_id' => $session->session_id,
            'title' => 'تیکت شماره '.$index,
            'description' => 'شرح تیکت',
            'scope' => 'system_wide',
            'category' => 'backend',
            'priority' => 'normal',
            'status' => $status,
            'assignee_id' => $user->id,
        ]);
    }

    public function test_report_renders_counts_per_user(): void
    {
        $user = $this->makeUser();
        $this->makeTicket(1, $user, 'open');
        $this->makeTicket(2, $user, 'open');
        $this->makeTicket(3, $user, 'in_progress');
        $this->makeTicket(4, $user, 'closed');

        $this->get('/panel/report')
            ->assertOk()
            ->assertSee('کاربر گزارش')
            ->assertSee('بسته شده');
    }

    public function test_avatar_can_be_uploaded(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $response = $this->post('/panel/users/'.$user->id.'/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png', 200, 200),
        ]);

        $response->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertTrue(Storage::disk('public')->exists('avatars/'.$user->avatar_path));
    }

    public function test_avatar_upload_requires_an_image(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->post('/panel/users/'.$user->id.'/avatar', ['avatar' => 'not-an-image'])
            ->assertSessionHasErrors('avatar');

        $user->refresh();
        $this->assertNull($user->avatar_path);
    }
}
