<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\IncidentIntakeSession;
use App\Services\TelegramBotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class IntakeSessionPanelController extends Controller
{
    private const ACTIVE_STATUSES = [
        'collecting',
        'analyzing',
        'awaiting_clarification',
        'awaiting_approval',
    ];

    public function index(Request $request): View
    {
        $scope = $request->query('scope') === 'all' ? 'all' : 'open';

        $sessions = IncidentIntakeSession::query()
            ->withCount('messages')
            ->when($scope === 'open', fn ($query) => $query->whereIn('status', self::ACTIVE_STATUSES))
            ->latest('updated_at')
            ->get();

        return view('panel.sessions.index', [
            'sessions' => $sessions,
            'scope' => $scope,
            'counts' => [
                'open' => IncidentIntakeSession::query()->whereIn('status', self::ACTIVE_STATUSES)->count(),
                'all' => IncidentIntakeSession::count(),
            ],
            'activeStatuses' => self::ACTIVE_STATUSES,
        ]);
    }

    public function close(IncidentIntakeSession $session, TelegramBotService $telegram): RedirectResponse
    {
        if (! in_array($session->status, self::ACTIVE_STATUSES, true)) {
            return back()->with('info', 'این سشن از قبل بسته شده است.');
        }

        $messageIds = array_values(array_unique(array_filter([
            ...$session->messages()->pluck('telegram_message_id')->all(),
            ...($session->telegram_bot_message_ids ?? []),
            $session->preview_message_id,
        ])));

        $session->update([
            'status' => 'cancelled',
            'clarification_question' => null,
            'preview_message_id' => null,
        ]);

        foreach ($messageIds as $messageId) {
            try {
                $telegram->deleteMessage($session->telegram_chat_id, $messageId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return back()->with('success', 'سشن بسته شد. پیام‌های مرتبط نیز برای حذف از تلگرام ارسال شدند.');
    }
}
