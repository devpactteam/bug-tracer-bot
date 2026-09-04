<?php

namespace App\Services;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Models\IncidentIntakeSession;

class FakeAiAnalysisService implements AiIncidentAnalysisInterface
{
    public function __construct(private readonly AiAnalysisAuditLogger $auditLogger) {}

    public function analyze(IncidentIntakeSession $session, string $phase = 'initial'): array
    {
        $startedAt = hrtime(true);
        $requestPayload = [
            'messages' => $session->messages->map(fn ($message): array => [
                'content' => $message->content,
                'media_type' => $message->media_type,
                'forward_origin' => $message->forward_origin_metadata,
            ])->values()->all(),
            'previous_clarification_question' => $session->clarification_question,
        ];
        $log = $this->auditLogger->start($session, $phase, 'fake', 'deterministic', $requestPayload);

        $text = strtolower($session->messages->pluck('content')->filter()->implode("\n"));
        preg_match('/\[clarify(?::(\d+))?]/', $text, $matches);
        $requiredAnswers = isset($matches[0])
            ? max(1, (int) ($matches[1] ?? 1))
            : (str_contains($text, 'not sure') ? 1 : 0);
        $needsClarification = $requiredAnswers > 0 && $session->messages->count() <= $requiredAnswers;
        $scope = str_contains($text, 'only me') || str_contains($text, 'single user')
            ? 'user_specific'
            : (str_contains($text, 'everyone') || str_contains($text, 'all users') ? 'system_wide' : 'unknown');

        $result = [
            'title' => $scope === 'system_wide' ? 'گزارش اختلال سراسری سامانه' : 'گزارش مشکل مشتری',
            'summary' => trim($session->messages->pluck('content')->filter()->implode("\n")) ?: 'No textual details supplied.',
            'scope' => $scope,
            'category' => str_contains($text, 'payment') ? 'payments' : 'general',
            'priority' => str_contains($text, 'down') || str_contains($text, 'urgent') ? 'high' : 'normal',
            'sample_data' => ['message_count' => $session->messages->count()],
            'clarification_needed' => $needsClarification,
            'clarification_question' => $needsClarification
                ? ($session->messages->count() === 1
                    ? 'این مشکل برای همه کاربران رخ می‌دهد یا فقط یک کاربر/حساب خاص؟'
                    : 'لطفاً یک نمونه شناسه سفارش، کاربر یا زمان تقریبی رخداد را هم ارسال کنید.')
                : null,
        ];

        $this->auditLogger->succeed($log, $result, $result, $startedAt);

        return $result;
    }
}
