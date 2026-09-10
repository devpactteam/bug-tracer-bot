<?php

namespace App\Console\Commands;

use App\Services\AssigneeNotificationService;
use App\Services\PendingTicketReminderService;
use Illuminate\Console\Command;

/**
 * Daily reminder: re-send every open/in_progress ticket to its assignee on the
 * assignee bot so nothing slips through. Runs once each morning via the
 * scheduler (routes/console.php); the once-per-day guard means any later
 * invocation (e.g. triggered by a process connecting) is a no-op unless the
 * morning send failed.
 */
class ResendPendingTicketsCommand extends Command
{
    protected $signature = 'tickets:resend-pending {--force : Send again even if today\'s reminder was already delivered}';

    protected $description = 'Re-send all open/in_progress tickets to their assignees (daily reminder).';

    public function handle(AssigneeNotificationService $notification): int
    {
        $delivered = app(PendingTicketReminderService::class)->run($notification, $this->option('force'));
        if ($delivered === 0) {
            $this->info('امروز از قبل ارسال شده است یا تیکت در انتظار بررسی‌ای وجود ندارد.');

            return self::SUCCESS;
        }

        $this->info("{$delivered} تیکت به مسئولان یادآوری شد.");

        return self::SUCCESS;
    }
}
