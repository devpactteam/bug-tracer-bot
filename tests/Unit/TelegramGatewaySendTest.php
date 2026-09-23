<?php

namespace Tests\Unit;

use App\Services\AssigneeNotificationService;
use App\Services\TelegramBotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramGatewaySendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'incident.telegram.bot_token' => 'main-token',
            'incident.telegram.gateway_url' => 'https://gateway.test/send',
            'incident.telegram.gateway_endpoints' => [
                'edit_message' => 'https://gateway.test/edit-message.php',
                'delete_message' => 'https://gateway.test/delete-message.php',
                'answer_callback_query' => 'https://gateway.test/answer-callback-query.php',
                'get_file' => 'https://gateway.test/get-file.php',
                'download_file' => 'https://gateway.test/download-file.php',
            ],
        ]);
    }

    public function test_main_bot_message_forwards_all_required_parameters_and_returns_telegram_response(): void
    {
        $telegramResponse = ['ok' => true, 'result' => ['message_id' => 91]];
        $keyboard = ['inline_keyboard' => [[['text' => 'Confirm', 'callback_data' => 'confirm']]]];
        Http::fake(['https://gateway.test/send*' => Http::response($telegramResponse)]);

        $result = app(TelegramBotService::class)->sendMessage('1001', '<b>Hello</b>', $keyboard);

        $this->assertSame($telegramResponse, $result);
        Http::assertSent(function (Request $request) use ($keyboard): bool {
            $query = $this->queryParameters($request);

            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/send'
                && $query['token'] === 'main-token'
                && $query['chatId'] === '1001'
                && $query['text'] === '<b>Hello</b>'
                && $query['parse_mode'] === 'HTML'
                && json_decode($query['reply_markup'], true) === $keyboard;
        });
    }

    public function test_message_mutations_and_callback_answers_use_their_dedicated_endpoints(): void
    {
        Http::fake([
            'https://gateway.test/edit-message.php*' => Http::response(['ok' => true, 'result' => ['message_id' => 12]]),
            'https://gateway.test/delete-message.php*' => Http::response(['ok' => true, 'result' => true]),
            'https://gateway.test/answer-callback-query.php*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        $service = app(TelegramBotService::class);

        $this->assertTrue($service->editMessage(1, 12, 'Edited')['ok']);
        $this->assertTrue($service->deleteMessage(1, 12)['ok']);
        $this->assertTrue($service->answerCallbackQuery('callback-1', 'Done')['ok']);

        Http::assertSent(function (Request $request): bool {
            $query = $this->queryParameters($request);

            return match (parse_url($request->url(), PHP_URL_PATH)) {
                '/edit-message.php' => $query['token'] === 'main-token'
                    && $query['chatId'] === '1'
                    && $query['messageId'] === '12'
                    && $query['text'] === 'Edited'
                    && $query['parse_mode'] === 'HTML',
                '/delete-message.php' => $query['token'] === 'main-token'
                    && $query['chatId'] === '1'
                    && $query['messageId'] === '12',
                '/answer-callback-query.php' => $query['token'] === 'main-token'
                    && $query['callbackQueryId'] === 'callback-1'
                    && $query['text'] === 'Done',
                default => false,
            };
        });
    }

    public function test_file_resolution_and_raw_download_use_gateway_endpoints(): void
    {
        Http::fake([
            'https://gateway.test/get-file.php*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'photos/file_1.jpg'],
            ]),
            'https://gateway.test/download-file.php*' => Http::response('raw-image-bytes', 200, [
                'Content-Type' => 'image/jpeg',
            ]),
        ]);
        $service = app(TelegramBotService::class);

        $path = $service->getFile('telegram-file-id');
        $bytes = $service->downloadFile((string) $path);

        $this->assertSame('photos/file_1.jpg', $path);
        $this->assertSame('raw-image-bytes', $bytes);
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'get-file.php?token=main-token&fileId=telegram-file-id',
        ));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'download-file.php?token=main-token&filePath=photos%2Ffile_1.jpg',
        ));
    }

    public function test_assignee_message_keeps_its_existing_boolean_gateway_contract(): void
    {
        config(['incident.assignee_bot.token' => 'assignee-token']);
        Http::fake(['https://gateway.test/send*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 92],
        ])]);

        $result = app(AssigneeNotificationService::class)->sendMessage('2002', 'New ticket');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token=assignee-token')
            && str_contains($request->url(), 'chatId=2002'));
    }

    public function test_gateway_failure_is_reported_without_throwing_for_send_message(): void
    {
        Http::fake(['https://gateway.test/send*' => Http::response('', 502)]);

        $result = app(TelegramBotService::class)->sendMessage('1001', 'Failure');

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['result']);
    }

    private function queryParameters(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
