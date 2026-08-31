<?php

namespace App\Providers;

use App\Contracts\AiIncidentAnalysisInterface;
use App\Services\FakeAiAnalysisService;
use App\Services\OpenAiAnalysisService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiIncidentAnalysisInterface::class, function ($app): AiIncidentAnalysisInterface {
            return config('incident.ai.driver') === 'openai'
                ? $app->make(OpenAiAnalysisService::class)
                : $app->make(FakeAiAnalysisService::class);
        });
    }
}
