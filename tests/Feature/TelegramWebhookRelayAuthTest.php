<?php

namespace Tests\Feature;

use App\Services\TelegramUpdateHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramWebhookRelayAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_accepts_telegram_and_relay_secrets(): void
    {
        config([
            'incident.telegram.webhook_secret' => 'telegram-secret',
            'incident.telegram.relay_auth_secret' => 'relay-secret',
        ]);
        $this->mock(TelegramUpdateHandler::class)
            ->shouldReceive('handle')
            ->once()
            ->with(['update_id' => 123]);

        $this->postJson('/api/telegram/webhook', ['update_id' => 123], [
            'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
            'X-AMPTrace-Relay-Authorization' => 'Bearer relay-secret',
        ])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_webhook_rejects_missing_or_invalid_relay_secret(): void
    {
        config([
            'incident.telegram.webhook_secret' => 'telegram-secret',
            'incident.telegram.relay_auth_secret' => 'relay-secret',
        ]);

        foreach (['', 'Bearer wrong-secret'] as $relayHeader) {
            $this->postJson('/api/telegram/webhook', ['update_id' => 123], [
                'X-Telegram-Bot-Api-Secret-Token' => 'telegram-secret',
                'X-AMPTrace-Relay-Authorization' => $relayHeader,
            ])->assertForbidden();
        }
    }
}
