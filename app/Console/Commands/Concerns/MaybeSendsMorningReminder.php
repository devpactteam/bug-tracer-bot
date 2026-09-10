<?php

namespace App\Console\Commands\Concerns;

use App\Services\AssigneeNotificationService;
use App\Services\PendingTicketReminderService;

/**
 * When a process connects (server startup), re-trigger the daily morning
 * reminder if it was not delivered yet that day. Guarded inside
 * PendingTicketReminderService so an already-sent day is a no-op.
 */
trait MaybeSendsMorningReminder
{
    private function maybeSendMorningReminder(): void
    {
        try {
            app(PendingTicketReminderService::class)->run(app(AssigneeNotificationService::class));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
