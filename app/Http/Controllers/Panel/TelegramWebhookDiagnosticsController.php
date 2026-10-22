<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\TelegramWebhookTrace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TelegramWebhookDiagnosticsController extends Controller
{
    public function index(Request $request): View
    {
        $username = $request->query('username', '');
        $username = is_string($username) ? trim($username) : '';
        if (str_starts_with($username, '@')) {
            $username = substr($username, 1);
        }

        $query = TelegramWebhookTrace::query();
        if ($username !== '') {
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Str::lower($username));
            $query->whereRaw("LOWER(sender_username) LIKE ? ESCAPE '!'", ['%'.$term.'%']);
        }

        return view('panel.webhook-diagnostics.index', [
            'traces' => $query->latest()->paginate(30)->withQueryString(),
            'username' => $username,
        ]);
    }

    public function show(TelegramWebhookTrace $trace): View
    {
        return view('panel.webhook-diagnostics.show', ['trace' => $trace->load(['events' => fn ($query) => $query->oldest('id')])]);
    }
}
