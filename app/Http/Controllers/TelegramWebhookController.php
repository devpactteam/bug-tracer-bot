<?php

namespace App\Http\Controllers;

use App\Services\TelegramUpdateHandler;
use App\Services\TelegramWebhookObservability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler, TelegramWebhookObservability $observability): JsonResponse
    {
        $trace = $observability->begin($request->json()->all());
        $secret = config('incident.telegram.webhook_secret');
        if ($secret && ! hash_equals((string) $secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            $observability->finish($trace, 'authentication_rejected', ['authentication' => 'telegram']);
            abort(403);
        }

        $relaySecret = config('incident.telegram.relay_auth_secret');
        if ($relaySecret && ! hash_equals(
            (string) $relaySecret,
            (string) preg_replace('/^Bearer\s+/i', '', (string) $request->header('X-AMPTrace-Relay-Authorization')),
        )) {
            $observability->finish($trace, 'authentication_rejected', ['authentication' => 'relay']);
            abort(403);
        }
        try {
            $observability->record($trace, 'authentication.accepted');
            $handler->handle($request->json()->all());
            $observability->finish($trace);
        } catch (\Throwable $exception) {
            $observability->fail($trace, $exception);
            throw $exception;
        }

        return response()->json(['ok' => true]);
    }
}
