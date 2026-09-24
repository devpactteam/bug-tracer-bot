<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\TelegramWebhookTrace;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily morning reminder (07:00): re-send every open/in_progress ticket to its
// assignee on the assignee bot. If the schedule is missed for any reason the
// reminder is re-triggered the next time a process connects (see the poll
// commands), guarded by a once-per-day marker.
Schedule::command('tickets:resend-pending')->dailyAt('07:00');
Schedule::call(fn () => TelegramWebhookTrace::query()->where('created_at', '<', now()->subDays((int) config('incident.telegram.webhook_trace_retention_days', 30)))->delete())
    ->dailyAt('03:20')->name('prune-telegram-webhook-traces');
