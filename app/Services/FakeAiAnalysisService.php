<?php

namespace App\Services;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Models\IncidentIntakeSession;

class FakeAiAnalysisService implements AiIncidentAnalysisInterface
{
    public function analyze(IncidentIntakeSession $session): array
    {
        $text = strtolower($session->messages->pluck('content')->filter()->implode("\n"));
        $needsClarification = str_contains($text, '[clarify]') || str_contains($text, 'not sure');
        $scope = str_contains($text, 'only me') || str_contains($text, 'single user')
            ? 'user_specific'
            : (str_contains($text, 'everyone') || str_contains($text, 'all users') ? 'system_wide' : 'unknown');

        return [
            'title' => $scope === 'system_wide' ? 'گزارش اختلال سراسری سامانه' : 'گزارش مشکل مشتری',
            'summary' => trim($session->messages->pluck('content')->filter()->implode("\n")) ?: 'No textual details supplied.',
            'scope' => $scope,
            'category' => str_contains($text, 'payment') ? 'payments' : 'general',
            'priority' => str_contains($text, 'down') || str_contains($text, 'urgent') ? 'high' : 'normal',
            'sample_data' => ['message_count' => $session->messages->count()],
            'clarification_needed' => $needsClarification,
            'clarification_question' => $needsClarification ? 'این مشکل برای همه کاربران رخ می‌دهد یا فقط یک کاربر/حساب خاص؟' : null,
        ];
    }
}
