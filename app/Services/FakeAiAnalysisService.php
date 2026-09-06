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
        $allMessages = $session->messages;
        $messageCount = $allMessages->count();
        $messages = $allMessages->map(function ($message, int $index): array {
            $isForwarded = $message->forward_origin_metadata !== null;
            $role = $isForwarded ? 'گزارش فوروارد شده' : 'پاسخ اپراتور';

            return [
                'index' => $index + 1,
                'role' => $role,
                'content' => $message->content,
                'media_type' => $message->media_type,
                'forward_origin' => $message->forward_origin_metadata,
            ];
        })->values()->all();
        $requestPayload = [
            'messages' => $messages,
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
            'responsible_side' => match (true) {
                str_contains($text, 'client'), str_contains($text, 'front') => 'client',
                str_contains($text, 'backend'), str_contains($text, 'back') => 'backend',
                default => null,
            },
            'priority' => str_contains($text, 'down') || str_contains($text, 'urgent') ? 'high' : 'normal',
            'sample_data' => array_merge(
                ['message_count' => $session->messages->count()],
                $this->extractIdentifiers($text)
            ),
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

    /**
     * Scan the raw text for common Iranian identifiers and return them in
     * sample_data so tests and local development reproduce the full retention
     * contract without an external LLM. Nothing is sanitised or removed.
     */
    private function extractIdentifiers(string $text): array
    {
        $identifiers = [];

        // Iranian mobile (09xxxxxxxxx)
        if (preg_match_all('/09\d{9}/', $text, $m)) {
            $identifiers['mobile_phones'] = array_values(array_unique($m[0]));
        }

        // Iranian national ID (exactly 10 digits, optionally with dashes/spaces)
        if (preg_match_all('/(?<!\d)(\d[\d\s\-]{8}\d)(?!\d)/', $text, $m)) {
            $ids = array_map(fn ($v) => preg_replace('/[\s\-]/', '', $v), $m[1]);
            $ids = array_filter($ids, fn ($v) => strlen($v) === 10 && ctype_digit($v));
            if ($ids !== []) {
                $identifiers['national_ids'] = array_values(array_unique($ids));
            }
        }

        // Order / ticket / tracking IDs (e.g. ORD-123456, INC-XXXXXXXX, ref12345)
        if (preg_match_all('/\b(?:ORD|INC|REF|TRACK|TICKET)-?\s*\d+\b/i', $text, $m)) {
            $identifiers['order_ids'] = array_values(array_unique(array_map('strtoupper', $m[0])));
        }

        return $identifiers;
    }
}
