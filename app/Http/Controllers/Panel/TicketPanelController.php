<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\IncidentTicket;
use App\Models\SupportUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketPanelController extends Controller
{
    public function index(Request $request): View
    {
        $statuses = ['open', 'in_progress', 'resolved', 'closed'];

        $status = (string) $request->query('status', '');
        if (! in_array($status, $statuses, true)) {
            $status = '';
        }

        $search = trim((string) $request->query('q', ''));

        $tickets = IncidentTicket::query()
            ->with('assignee')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('ticket_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%");
            }))
            ->latest()
            ->get();

        return view('panel.index', [
            'tickets' => $tickets,
            'status' => $status,
            'search' => $search,
            'counts' => [
                'all' => IncidentTicket::count(),
                'open' => IncidentTicket::where('status', 'open')->count(),
                'in_progress' => IncidentTicket::where('status', 'in_progress')->count(),
                'resolved' => IncidentTicket::where('status', 'resolved')->count(),
                'closed' => IncidentTicket::where('status', 'closed')->count(),
            ],
        ]);
    }

    public function show(IncidentTicket $ticket): View
    {
        $ticket->load(['assignee', 'rootCauseAuthor', 'ticketLogs', 'session']);

        return view('panel.show', [
            'ticket' => $ticket,
        ]);
    }

    public function report(): View
    {
        $users = SupportUser::query()
            ->with('assignedTickets:id,assignee_id,status')
            ->orderBy('id')
            ->get();

        $rows = $users->map(function (SupportUser $user): array {
            $tickets = $user->assignedTickets;
            $count = fn (string $status): int => $tickets->where('status', $status)->count();

            return [
                'user' => $user,
                'open' => $count('open'),
                'in_progress' => $count('in_progress'),
                'closed' => $count('closed'),
                'total' => $tickets->count(),
            ];
        });

        $totals = [
            'open' => $rows->sum('open'),
            'in_progress' => $rows->sum('in_progress'),
            'closed' => $rows->sum('closed'),
        ];

        return view('panel.report', [
            'rows' => $rows,
            'totals' => $totals,
            'roles' => SupportUserController::ROLES,
        ]);
    }

    public function close(IncidentTicket $ticket): RedirectResponse
    {
        if ($ticket->status === 'closed') {
            return back()->with('info', 'این تیکت از قبل بسته شده است.');
        }

        $fromStatus = $ticket->status;
        $ticket->update([
            'status' => 'closed',
            'resolved_at' => now(),
            'resolution_pending' => false,
            'resolution_step' => 0,
        ]);
        $ticket->log('closed', 'بسته شدن از پنل مدیریت', 'پنل مدیریت', $fromStatus, 'closed');

        return back()->with('success', 'تیکت '.$ticket->ticket_number.' با موفقیت بسته شد.');
    }
}
