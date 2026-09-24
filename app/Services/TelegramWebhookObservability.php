<?php

namespace App\Services;

use App\Models\TelegramWebhookTrace;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookObservability
{
    private const FORBIDDEN = ['token', 'secret', 'authorization', 'body', 'text', 'caption', 'content', 'file_id', 'media', 'prompt', 'response', 'payload'];
    private ?TelegramWebhookTrace $currentTrace = null;

    public function begin(array $update): TelegramWebhookTrace
    {
        $type = isset($update['callback_query']) ? 'callback_query' : (isset($update['message']) ? 'message' : null);
        $trace = TelegramWebhookTrace::query()->create([
            'correlation_id' => (string) str()->uuid(),
            'update_id' => isset($update['update_id']) ? (int) $update['update_id'] : null,
            'update_type' => $type,
        ]);
        $this->record($trace, 'request.received', ['update_keys' => array_keys($update), 'update_type' => $type]);

        return $this->currentTrace = $trace;
    }

    public function current(): ?TelegramWebhookTrace { return $this->currentTrace; }

    public function record(TelegramWebhookTrace $trace, string $checkpoint, array $metadata = []): void
    {
        try {
            $safe = $this->sanitize($metadata);
            $trace->events()->create(['checkpoint' => $checkpoint, 'metadata' => $safe]);
            $trace->forceFill(array_filter(['latest_checkpoint' => $checkpoint, 'session_id' => $safe['session_id'] ?? null], fn ($value) => $value !== null))->save();
            Log::channel('telegram-webhook')->info($checkpoint, ['correlation_id' => $trace->correlation_id, 'update_id' => $trace->update_id, ...$safe]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function finish(TelegramWebhookTrace $trace, string $outcome = 'handled', array $metadata = []): void
    {
        $this->record($trace, 'request.'.$outcome, $metadata);
        $trace->forceFill(['outcome' => $outcome, 'completed_at' => now()])->save();
    }

    public function fail(TelegramWebhookTrace $trace, Throwable $exception): void
    {
        $message = $this->safeError($exception->getMessage());
        $this->record($trace, 'request.failed', ['exception_class' => $exception::class, 'exception_message' => $message]);
        $trace->forceFill(['outcome' => 'failed', 'failure_class' => class_basename($exception), 'failure_message' => $message, 'completed_at' => now()])->save();
    }

    public function sanitize(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            $normalized = strtolower((string) $key);
            if (collect(self::FORBIDDEN)->contains(fn ($needle) => str_contains($normalized, $needle))) continue;
            if (is_array($value)) $safe[$key] = $this->sanitize($value);
            elseif (is_scalar($value) || $value === null) $safe[$key] = is_string($value) ? str($value)->limit(500, '…')->toString() : $value;
        }
        return $safe;
    }

    public function safeError(string $message): string
    {
        return str($message)->replaceMatches('/(?i)(token|secret|authorization|password)\\s*[=:]\\s*[^\\s,]+/', '$1=[redacted]')->limit(500, '…')->toString();
    }
}
