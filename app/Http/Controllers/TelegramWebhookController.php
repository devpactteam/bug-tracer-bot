<?php

namespace App\Http\Controllers;

use App\Services\TelegramUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler): JsonResponse
    {
        $secret = config('incident.telegram.webhook_secret');
        if ($secret && ! hash_equals((string) $secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        $relaySecret = config('incident.telegram.relay_auth_secret');
        if ($relaySecret && ! hash_equals(
            (string) $relaySecret,
            (string) preg_replace('/^Bearer\s+/i', '', (string) $request->header('X-AMPTrace-Relay-Authorization')),
        )) {
            abort(403);
        }
        $handler->handle($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
