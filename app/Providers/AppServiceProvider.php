<?php

namespace App\Providers;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Services\FakeAiAnalysisService;
use App\Services\OpenAiAnalysisService;
use App\Services\TelegramWebhookObservability;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A TLS-terminating proxy can make Laravel see the origin connection
        // as HTTP unless its address is listed in TRUSTED_PROXIES. Keep
        // generated form actions and links on HTTPS in forced-HTTPS installs.
        if (config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    public function register(): void
    {
        // A webhook controller, its handler, and Telegram services must share
        // one trace during a request. `scoped` is reset between HTTP requests
        // and queue jobs, including in long-running workers.
        $this->app->scoped(TelegramWebhookObservability::class, fn (): TelegramWebhookObservability => new TelegramWebhookObservability);

        $this->app->bind(AiIncidentAnalysisInterface::class, function ($app): AiIncidentAnalysisInterface {
            return in_array(config('incident.ai.driver'), ['openai', 'gapgpt'], true)
                ? $app->make(OpenAiAnalysisService::class)
                : $app->make(FakeAiAnalysisService::class);
        });
    }
}
