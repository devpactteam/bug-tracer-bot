<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\MaybeSendsMorningReminder;
use App\Services\AssigneeNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramPollAssigneeCommand extends Command
{
    use MaybeSendsMorningReminder;

    protected $signature = 'telegram:poll-assignee {--once : Process one getUpdates request}';

    protected $description = 'Long-poll the assignee bot (captures /start chat ids and delivers tickets).';

    public function handle(AssigneeNotificationService $notification): int
    {
        $this->maybeSendMorningReminder();

        $offset = 0;
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running): void {
                $running = false;
            });
        }
        while ($running) {
            $request = Http::baseUrl(rtrim(config('incident.assignee_bot.api_base_url'), '/')
                .'/bot'.config('incident.assignee_bot.token').'/')
                ->timeout((int) config('incident.assignee_bot.poll_timeout') + 10)
                ->when(
                    filled(config('incident.assignee_bot.proxy.http_bridge')),
                    fn ($http) => $http->withOptions(['proxy' => config('incident.assignee_bot.proxy.http_bridge')])
                );
            $response = $request->get('getUpdates', [
                'offset' => $offset,
                'limit' => config('incident.assignee_bot.poll_limit'),
                'timeout' => config('incident.assignee_bot.poll_timeout'),
            ])->throw();
            foreach ($response->json('result', []) as $update) {
                $offset = max($offset, ((int) ($update['update_id'] ?? 0)) + 1);
                try {
                    $notification->handleUpdate($update);
                } catch (Throwable $exception) {
                    report($exception);
                }
                if (function_exists('pcntl_signal')) {
                    pcntl_signal_dispatch();
                }
            }
            if ($this->option('once')) {
                break;
            }
        }

        return self::SUCCESS;
    }
}
