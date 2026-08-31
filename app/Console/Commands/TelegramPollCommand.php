<?php

namespace App\Console\Commands;

use App\Services\TelegramUpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll {--once : Process one getUpdates request}';
    protected $description = 'Long-poll Telegram updates for local development.';

    public function handle(TelegramUpdateHandler $handler): int
    {
        $offset = 0;
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running): void { $running = false; });
            pcntl_signal(SIGTERM, function () use (&$running): void { $running = false; });
        }
        while ($running) {
            $request = Http::baseUrl(rtrim(config('incident.telegram.api_base_url'), '/').'/bot'.config('incident.telegram.bot_token').'/')
                ->timeout((int) config('incident.telegram.poll_timeout') + 10)
                ->when(
                    filled(config('incident.telegram.proxy.http_bridge')),
                    fn ($http) => $http->withOptions(['proxy' => config('incident.telegram.proxy.http_bridge')])
                );
            $response = $request->get('getUpdates', [
                    'offset' => $offset,
                    'limit' => config('incident.telegram.poll_limit'),
                    'timeout' => config('incident.telegram.poll_timeout'),
                ])->throw();
            foreach ($response->json('result', []) as $update) {
                $offset = max($offset, ((int) ($update['update_id'] ?? 0)) + 1);
                $handler->handle($update);
                if (function_exists('pcntl_signal')) pcntl_signal_dispatch();
            }
            if ($this->option('once')) break;
        }
        return self::SUCCESS;
    }
}
