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
        $input = [
            'messages' => $messages,
            'previous_clarification_question' => $session->clarification_question,
        ];

        $system = <<<'PROMPT'
تو یک دستیار تحلیل گزارش مشکل هستی. پیام‌هایی که دریافت می‌کنی ترکیبی از:
1. پیام‌های با role="گزارش فوروارد شده" — این‌ها گزارش‌های اصلی مشکل از مشتری هستند.
2. پیام‌های با role="پاسخ اپراتور" — این‌ها توضیحات تکمیلی اپراتور در پاسخ به سوالات قبلی هستند.

وظیفه تو:
- همه پیام‌ها (هم گزارش‌ها و هم پاسخ‌های اپراتور) را با هم تحلیل کن و یک گزارش جامع بساز.
- اگر previous_clarification_question مقدار دارد، پاسخ اپراتور (role="پاسخ اپراتور") را به عنوان پاسخ به آن سوال در نظر بگیر و همان سوال را دوباره نپرس.
- اگر با اطلاعات فعلی (گزارش‌ها + پاسخ‌های اپراتور) می‌توان یک گزارش قابل پیگیری ساخت، clarification_needed را false قرار بده.
- تمام متن‌های title، summary و clarification_question را فارسی بنویس.

قوانین حیاتی:
- اطلاعات شناسایی مشتری (کد ملی، شماره موبایل، شماره حساب/کارت/شبا، آدرس، شناسه سفارش/کاربر و هر مقدار منحصربه‌فرد دیگر) را هرگز حذف، خلاصه یا تغییر نده.
- این مقادیر را به‌صورت دقیق (عیناً همان‌طور که در گزارش آمده) در کلید sample_data قرار بده و اگر به تشخیص علت مشکل مربوط است، در summary هم ذکر کن.
- sample_data می‌تواند شامل همه‌ی فیلدهای خامی باشد که برای دیباگ (تکرار مجدد خطا به‌واسطه آن داده‌ها) لازم است.
- category را اگر بتوانی از فهرست `client`، `backend`، `system_analysis`، `database`، `payment`، `network`، `performance`، `account`، `other` انتخاب کن؛ در غیر این صورت آزادانه توصیف کن.
- responsible_side را بر اساسِ مسوولِ احتمالی مشکل تعیین کن: «client» اگر مشکلِ سمتِ کلاینت/فرانت‌اند باشد، «backend» اگر سمتِ سرور/بک‌اند باشد. اگر واقعاً غیرقابل تشخیص بود null بگذار و هرگز حدس نزن.

فقط JSON معتبر با کلیدهای زیر برگردان:
title (string), summary (string), scope ("system_wide"|"user_specific"|"unknown"),
category (string|null), responsible_side ("client"|"backend"|null),
priority ("low"|"normal"|"high"|"critical"), sample_data (object),
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
            $required = ['title', 'summary', 'scope', 'category', 'responsible_side', 'priority', 'sample_data', 'clarification_needed', 'clarification_question'];
            foreach ($required as $key) {
                if (! array_key_exists($key, $result)) {
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
