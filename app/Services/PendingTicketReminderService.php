<?php

namespace App\Services;

use App\Models\IncidentTicket;
use App\Models\SupportUser;
use Illuminate\Support\Facades\Cache;

/**
 * Daily morning reminder for open / in_progress tickets. Guarded by a
 * once-per-day marker (cache): the scheduled run at 07:00 sends it, and any
 * later process startup (bot poll connecting) re-runs it only when today's
 * reminder has not actually been delivered yet.
 */
class PendingTicketReminderService
{
    private const CACHE_KEY = 'tickets:reminder:sent_date';

    public function sentToday(): bool
    {
        return Cache::get(self::CACHE_KEY) === now()->toDateString();
    }

    public function run(AssigneeNotificationService $notification, bool $force = false): int
    {
        if (! $force && $this->sentToday()) {
            return 0;
        }

        $delivered = $this->sendReminders($notification);
        if ($delivered > 0 || IncidentTicket::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('assignee', fn ($query) => $query->whereNotNull('assignee_chat_id'))
            ->doesntExist()
        ) {
            $this->markSentToday();
        }

        return $delivered;
    }

    public function markSentToday(): void
    {
        Cache::put(self::CACHE_KEY, now()->toDateString(), now()->endOfDay());
    }

    private function sendReminders(AssigneeNotificationService $notification): int
    {
        $tickets = IncidentTicket::query()
            ->with('assignee')
            ->whereIn('status', ['open', 'in_progress'])
            ->whereHas('assignee', fn ($query) => $query->whereNotNull('assignee_chat_id'))
            ->orderBy('assignee_id')
            ->orderBy('id')
            ->get();

        if ($tickets->isEmpty()) {
            return 0;
        }

        $delivered = 0;
        $grouped = $tickets->groupBy('assignee.id');

        foreach ($grouped as $assigneeTickets) {
            /** @var SupportUser $assignee */
            $assignee = $assigneeTickets->first()->assignee;
            if (! $assignee || ! $assignee->assignee_chat_id) {
                continue;
            }

            try {
                $notification->sendMessage(
                    $assignee->assignee_chat_id,
                    '🔁 <b>یادآوری روزانه:</b> '.count($assigneeTickets).' تیکت در انتظار بررسی شما است.'
                );
            } catch (\Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($assigneeTickets as $ticket) {
                try {
                    $sent = $notification->sendMessage(
                        $assignee->assignee_chat_id,
                        $notification->ticketMessageText($ticket, $assignee, 'reminder'),
                        $ticket->status === 'open'
                            ? $notification->ticketProgressKeyboard($ticket->id)
                            : $notification->ticketResolveKeyboard($ticket->id)
                    );
                    $ticket->update(['assignee_message_id' => $sent['result']['message_id'] ?? null]);
                    $delivered++;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        }

        return $delivered;
    }
}
