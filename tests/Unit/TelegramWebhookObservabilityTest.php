<?php

namespace Tests\Unit;

use App\Services\TelegramWebhookObservability;
use Tests\TestCase;

class TelegramWebhookObservabilityTest extends TestCase
{
    public function test_sanitizer_removes_sensitive_values_recursively(): void
    {
        $safe = app(TelegramWebhookObservability::class)->sanitize([
            'update_id' => 12,
            'text' => 'do not persist',
            'token' => 'secret-token',
            'nested' => ['caption' => 'hidden', 'chat_id' => 55],
            'exception_message' => 'safe failure',
        ]);

        $this->assertSame(['update_id' => 12, 'nested' => ['chat_id' => 55], 'exception_message' => 'safe failure'], $safe);
    }
}
