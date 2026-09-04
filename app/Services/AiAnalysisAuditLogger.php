<?php

namespace App\Services;

use App\Models\AiIncidentAnalysisLog;
use App\Models\IncidentIntakeSession;
use Throwable;

class AiAnalysisAuditLogger
{
    public function start(
        IncidentIntakeSession $session,
        string $phase,
        string $provider,
        ?string $model,
        array $requestPayload,
    ): AiIncidentAnalysisLog {
        return AiIncidentAnalysisLog::query()->create([
            'session_id' => $session->session_id,
            'phase' => $phase,
            'provider' => $provider,
            'model' => $model,
            'operator_telegram_id' => $session->operator_telegram_id,
            'request_payload' => $requestPayload,
            'status' => 'started',
            'started_at' => now(),
        ]);
    }

    public function succeed(
        AiIncidentAnalysisLog $log,
        array $responsePayload,
        array $normalizedResponse,
        int $startedAtNanoseconds,
    ): void {
        $log->update([
            'response_payload' => $responsePayload,
            'normalized_response' => $normalizedResponse,
            'status' => 'succeeded',
            'duration_ms' => $this->durationMilliseconds($startedAtNanoseconds),
            'finished_at' => now(),
        ]);
    }

    public function fail(
        AiIncidentAnalysisLog $log,
        Throwable $exception,
        int $startedAtNanoseconds,
        ?array $responsePayload = null,
    ): void {
        $log->update([
            'response_payload' => $responsePayload,
            'status' => 'failed',
            'error_class' => $exception::class,
            'error_message' => mb_substr($exception->getMessage(), 0, 65000),
            'duration_ms' => $this->durationMilliseconds($startedAtNanoseconds),
            'finished_at' => now(),
        ]);
    }

    private function durationMilliseconds(int $startedAtNanoseconds): int
    {
        return max(0, (int) round((hrtime(true) - $startedAtNanoseconds) / 1_000_000));
    }
}
