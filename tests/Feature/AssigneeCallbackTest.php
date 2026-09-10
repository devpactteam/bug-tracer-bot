<?php

namespace Tests\Feature;

use App\Services\AssigneeNotificationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssigneeCallbackTest extends TestCase
{
    public function test_expired_assignee_callback_does_not_fail_polling(): void
    {
        config()->set('incident.assignee_bot.token', 'test-token');
        config()->set('incident.assignee_bot.api_base_url', 'https://api.telegram.org');
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: query is too old and response timeout expired',
            ], 400),
        ]);

        $result = app(AssigneeNotificationService::class)->answerCallbackQuery('expired-query', 'تأیید شد');

        $this->assertSame(['ok' => false, 'description' => 'callback expired'], $result);
    }
}
