<?php

namespace Tests\Unit;

use App\Services\AssigneeNotificationService;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramGatewaySendTest extends TestCase
{
    public function test_main_bot_message_is_sent_through_the_gateway(): void
    {
        config(['app.debug' => false, 'incident.telegram.gateway_url' => 'https://me.sifb.ir']);
        config(['incident.telegram.bot_token' => 'main-token']);
        Http::fake(['https://me.sifb.ir*' => Http::response('', 200)]);

        $result = app(TelegramBotService::class)->sendMessage('1001', 'سلام');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->url() === 'https://me.sifb.ir?token=main-token&chatId=1001&text=%D8%B3%D9%84%D8%A7%D9%85');
    }

    public function test_assignee_message_uses_its_own_bot_token_at_the_gateway(): void
    {
        config(['app.debug' => false, 'incident.telegram.gateway_url' => 'https://me.sifb.ir']);
        config(['incident.assignee_bot.token' => 'assignee-token']);
        Http::fake(['https://me.sifb.ir*' => Http::response('', 200)]);

        $result = app(AssigneeNotificationService::class)->sendMessage('2002', 'تیکت جدید');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'token=assignee-token')
            && str_contains($request->url(), 'chatId=2002'));
    }

    public function test_gateway_failure_is_reported_without_throwing(): void
    {
        config(['app.debug' => false]);
        config(['incident.telegram.bot_token' => 'main-token']);
        Http::fake(['https://me.sifb.ir*' => Http::response('', 502)]);

        $result = app(TelegramBotService::class)->sendMessage('1001', 'خطا');

        $this->assertFalse($result['ok']);
    }
}
