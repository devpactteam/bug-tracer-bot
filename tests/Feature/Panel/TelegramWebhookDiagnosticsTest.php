<?php

namespace Tests\Feature\Panel;

use App\Models\TelegramWebhookTrace;

class TelegramWebhookDiagnosticsTest extends PanelTestCase
{
    public function test_authenticated_operator_can_view_safe_trace_timeline(): void
    {
        $trace = TelegramWebhookTrace::query()->create([
            'correlation_id' => (string) str()->uuid(), 'update_id' => 42, 'outcome' => 'failed',
            'latest_checkpoint' => 'request.failed', 'failure_message' => 'connection failed',
        ]);
        $trace->events()->create(['checkpoint' => 'request.received', 'metadata' => ['update_id' => 42]]);

        $this->get(route('panel.telegram-webhooks.index'))->assertOk()->assertSee('42');
        $this->get(route('panel.telegram-webhooks.show', $trace))->assertOk()->assertSee('request.received')->assertDontSee('token');
    }

    public function test_diagnostics_requires_login(): void
    {
        auth('web')->logout();
        $this->get(route('panel.telegram-webhooks.index'))->assertRedirect(route('login'));
    }
}
