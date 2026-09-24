<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TelegramWebhookTrace;
use Illuminate\View\View;

class TelegramWebhookDiagnosticsController extends Controller
{
    public function index(): View
    {
        return view('panel.webhook-diagnostics.index', [
            'traces' => TelegramWebhookTrace::query()->latest()->paginate(30),
        ]);
    }

    public function show(TelegramWebhookTrace $trace): View
    {
        return view('panel.webhook-diagnostics.show', ['trace' => $trace->load(['events' => fn ($query) => $query->oldest('id')])]);
    }
}
