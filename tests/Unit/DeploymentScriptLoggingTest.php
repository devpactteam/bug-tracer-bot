<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeploymentScriptLoggingTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryRoot = sys_get_temp_dir().'/amptrace-deployment-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryRoot);

        parent::tearDown();
    }

    public function test_every_deployment_script_writes_to_its_own_log_when_enabled(): void
    {
        $scripts = [
            'send-message.php',
            'edit-message.php',
            'delete-message.php',
            'answer-callback-query.php',
            'get-file.php',
            'download-file.php',
        ];

        foreach ($scripts as $script) {
            $scriptPath = base_path('deployment/'.$script);
            $code = '$_SERVER["REQUEST_METHOD"] = "GET"; '
                .'$_GET = ["token" => "must-not-appear-in-logs"]; '
                .'include '.var_export($scriptPath, true).';';
            $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
                'AMPTRACE_DEPLOYMENT_LOG_ENABLED' => 'true',
                'AMPTRACE_DEPLOYMENT_LOG_DIR' => $this->temporaryRoot,
            ]);

            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $logPath = $this->temporaryRoot.'/'.pathinfo($script, PATHINFO_FILENAME).'.log';
            $this->assertFileExists($logPath);
            $contents = (string) file_get_contents($logPath);
            $this->assertStringNotContainsString('must-not-appear-in-logs', $contents);
            $this->assertLogRecordsAreStructured($contents, $script);
        }

        $relayPath = base_path('deployment/telegram-webhook-relay.php');
        $relayCode = '$_SERVER["REQUEST_METHOD"] = "GET"; include '.var_export($relayPath, true).';';
        $relay = new Process([PHP_BINARY, '-r', $relayCode], base_path(), [
            'AMPTRACE_DEPLOYMENT_LOG_ENABLED' => 'true',
            'AMPTRACE_DEPLOYMENT_LOG_DIR' => $this->temporaryRoot,
            'AMPTRACE_LARAVEL_WEBHOOK_URL' => 'https://app.example.test/api/telegram/webhook',
            'AMPTRACE_TELEGRAM_WEBHOOK_SECRET' => 'must-not-appear-in-logs',
            'AMPTRACE_RELAY_AUTH_SECRET' => 'also-must-not-appear-in-logs',
        ]);

        $relay->run();

        $this->assertTrue($relay->isSuccessful(), $relay->getErrorOutput());
        $relayLog = (string) file_get_contents($this->temporaryRoot.'/telegram-webhook-relay.log');
        $this->assertStringNotContainsString('must-not-appear-in-logs', $relayLog);
        $this->assertLogRecordsAreStructured($relayLog, 'telegram-webhook-relay.php');
    }

    public function test_deployment_logging_is_disabled_by_default(): void
    {
        $scriptPath = base_path('deployment/send-message.php');
        $code = '$_SERVER["REQUEST_METHOD"] = "GET"; include '.var_export($scriptPath, true).';';
        $process = new Process([PHP_BINARY, '-r', $code], base_path(), [
            'AMPTRACE_DEPLOYMENT_LOG_ENABLED' => 'false',
            'AMPTRACE_DEPLOYMENT_LOG_DIR' => $this->temporaryRoot,
        ]);

        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->temporaryRoot);
    }

    private function assertLogRecordsAreStructured(string $contents, string $script): void
    {
        $lines = array_values(array_filter(explode(PHP_EOL, $contents)));
        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($script, $record['script']);
            $this->assertNotEmpty($record['timestamp']);
            $this->assertNotEmpty($record['request_id']);
            $this->assertNotEmpty($record['step']);
            $this->assertIsArray($record['context']);
        }
    }
}
