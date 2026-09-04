<?php

namespace App\Services;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Models\IncidentIntakeSession;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class OpenAiAnalysisService implements AiIncidentAnalysisInterface
{
    public function __construct(private readonly AiAnalysisAuditLogger $auditLogger) {}

    public function analyze(IncidentIntakeSession $session, string $phase = 'initial'): array
    {
        $apiKey = (string) config('incident.ai.api_key');
        $messages = $session->messages->map(fn ($message): array => [
            'content' => $message->content,
            'media_type' => $message->media_type,
            'forward_origin' => $message->forward_origin_metadata,
        ])->values()->all();
        $input = [
            'messages' => $messages,
            'previous_clarification_question' => $session->clarification_question,
        ];

        $system = <<<'PROMPT'
گزارش مشکل را تحلیل و دسته‌بندی کن. تمام متن‌های title، summary و clarification_question را فارسی بنویس.
اگر previous_clarification_question وجود دارد، پاسخ جدید اپراتور را با آن بررسی کن و همان سؤال را تکرار نکن.
اگر اطلاعات فعلی برای یک گزارش قابل پیگیری کافی است، clarification_needed را false قرار بده.
فقط JSON معتبر با دقیقاً کلیدهای زیر برگردان:
title (string), summary (string), scope ("system_wide"|"user_specific"|"unknown"),
category (string|null), priority ("low"|"normal"|"high"|"critical"), sample_data (object),
clarification_needed (boolean), clarification_question (string|null).
PROMPT;

        $baseUrl = rtrim((string) config('incident.ai.base_url'), '/');
        $requestBody = [
            'model' => config('incident.ai.model'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => json_encode($input, JSON_THROW_ON_ERROR)],
            ],
        ];
        $startedAt = hrtime(true);
        $log = $this->auditLogger->start($session, $phase, (string) config('incident.ai.driver'), (string) config('incident.ai.model'), [
            'endpoint' => "{$baseUrl}/chat/completions",
            'body' => $requestBody,
        ]);
        $response = null;

        try {
            if ($apiKey === '') {
                throw new RuntimeException('AI_API_KEY is not configured.');
            }

            $response = Http::baseUrl($baseUrl)
                ->withToken($apiKey)
                ->acceptJson()
                ->timeout(60)
                ->post('chat/completions', $requestBody)
                ->throw();

            $content = $response->json('choices.0.message.content');
            $result = json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
            $required = ['title', 'summary', 'scope', 'category', 'priority', 'sample_data', 'clarification_needed', 'clarification_question'];
            foreach ($required as $key) {
                if (!array_key_exists($key, $result)) {
                    throw new RuntimeException("AI response missing key: {$key}");
                }
            }

            $this->auditLogger->succeed(
                $log,
                $this->responsePayload($response),
                $result,
                $startedAt
            );

            return $result;
        } catch (Throwable $exception) {
            $this->auditLogger->fail(
                $log,
                $exception,
                $startedAt,
                $response ? $this->responsePayload($response) : null
            );
            throw $exception;
        }
    }

    private function responsePayload(Response $response): array
    {
        $payload = $response->json();

        return is_array($payload) ? $payload : ['body' => $response->body()];
    }
}
