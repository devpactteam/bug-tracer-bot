<?php

/**
 * AMPTrace Telegram webhook relay
 *
 * Upload this single file to an HTTPS-enabled intermediary server. Configure
 * the constants below (or matching environment variables), then set Telegram
 * webhook URL to this file's public URL. The relay validates Telegram's
 * secret-token header and forwards the original JSON update to Laravel.
 *
 * Required PHP extension: cURL.
 */

declare(strict_types=1);

require __DIR__.'/load-env.php';

const RELAY_VERSION = '1.0.0';

function relayConfig(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function relayLog(string $step, array $context = []): void
{
    $enabled = getenv('AMPTRACE_DEPLOYMENT_LOG_ENABLED');
    if ($enabled === false || ! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    static $requestId = null;
    if ($requestId === null) {
        try {
            $requestId = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $requestId = uniqid('', true);
        }
    }

    $directory = getenv('AMPTRACE_DEPLOYMENT_LOG_DIR');
    if ($directory === false || $directory === '') {
        $directory = __DIR__.'/logs';
    }
    if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
        return;
    }

    $record = [
        'timestamp' => gmdate('c'),
        'request_id' => $requestId,
        'script' => basename(__FILE__),
        'step' => $step,
        'context' => $context,
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($line)) {
        @file_put_contents(
            rtrim($directory, '/').'/'.pathinfo(__FILE__, PATHINFO_FILENAME).'.log',
            $line.PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}

function relayJson(int $status, array $payload): never
{
    relayLog('response.sent', [
        'status' => $status,
        'ok' => $payload['ok'] ?? null,
        'has_error' => isset($payload['error']),
    ]);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function relayHeader(string $name): string
{
    $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function relayConstantOrEnv(string $constant, string $env, string $default = ''): string
{
    return defined($constant) ? (string) constant($constant) : relayConfig($env, $default);
}

function relayForward(string $url, string $body, array $headers, int $timeout): array
{
    relayLog('laravel.request.started', [
        'upstream_host' => parse_url($url, PHP_URL_HOST),
        'upstream_path' => parse_url($url, PHP_URL_PATH),
        'body_bytes' => strlen($body),
        'timeout_seconds' => $timeout,
    ]);
    $startedAt = microtime(true);
    $curl = curl_init($url);
    if ($curl === false) {
        relayLog('laravel.curl.initialization_failed');
        throw new RuntimeException('Could not initialise cURL.');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);

    if ($response === false) {
        relayLog('laravel.request.failed', [
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'curl_error' => $error !== '' ? $error : 'unknown',
        ]);
        throw new RuntimeException($error !== '' ? $error : 'Relay request failed.');
    }

    relayLog('laravel.response.received', [
        'status' => $status,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'response_bytes' => strlen($response) - $headerSize,
    ]);

    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

// Optional deployment-time overrides. Environment variables are recommended.
// define('LARAVEL_WEBHOOK_URL', 'https://app.example.com/api/telegram/webhook');
// define('TELEGRAM_WEBHOOK_SECRET', 'same-secret-used-in-Laravel');
// define('RELAY_AUTH_SECRET', 'a-different-secret-between-relay-and-Laravel');

$laravelWebhookUrl = relayConstantOrEnv(
    'LARAVEL_WEBHOOK_URL',
    'AMPTRACE_LARAVEL_WEBHOOK_URL',
);
$telegramSecret = relayConstantOrEnv(
    'TELEGRAM_WEBHOOK_SECRET',
    'AMPTRACE_TELEGRAM_WEBHOOK_SECRET',
);
$relayAuthSecret = relayConstantOrEnv(
    'RELAY_AUTH_SECRET',
    'AMPTRACE_RELAY_AUTH_SECRET',
);
$maxBodyBytes = (int) relayConfig('AMPTRACE_MAX_BODY_BYTES', '1048576');
$timeout = (int) relayConfig('AMPTRACE_UPSTREAM_TIMEOUT', '15');
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

relayLog('request.received', [
    'http_method' => $requestMethod,
    'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null,
]);
relayLog('configuration.loaded', [
    'has_upstream_url' => $laravelWebhookUrl !== '',
    'has_telegram_secret' => $telegramSecret !== '',
    'has_relay_auth_secret' => $relayAuthSecret !== '',
    'max_body_bytes' => $maxBodyBytes,
    'upstream_timeout' => $timeout,
]);

if ($laravelWebhookUrl === '' || ! filter_var($laravelWebhookUrl, FILTER_VALIDATE_URL)) {
    relayLog('configuration.validation_failed', ['reason' => 'invalid_upstream_url']);
    relayJson(500, ['ok' => false, 'error' => 'Relay upstream URL is not configured.']);
}
if (! str_starts_with(strtolower($laravelWebhookUrl), 'https://')) {
    relayLog('configuration.validation_failed', ['reason' => 'upstream_url_not_https']);
    relayJson(500, ['ok' => false, 'error' => 'Relay upstream URL must use HTTPS.']);
}
if ($telegramSecret === '') {
    relayLog('configuration.validation_failed', ['reason' => 'missing_telegram_secret']);
    relayJson(500, ['ok' => false, 'error' => 'Telegram webhook secret is not configured.']);
}
relayLog('configuration.validated');

if ($requestMethod !== 'POST') {
    if ($requestMethod === 'GET') {
        relayLog('health_check.completed');
        relayJson(200, ['ok' => true, 'service' => 'AMPTrace Telegram webhook relay', 'version' => RELAY_VERSION]);
    }
    relayLog('request.method_rejected', ['http_method' => $requestMethod]);
    relayJson(405, ['ok' => false, 'error' => 'Only POST is supported.']);
}

$receivedSecret = relayHeader('X-Telegram-Bot-Api-Secret-Token');
if (! hash_equals($telegramSecret, $receivedSecret)) {
    relayLog('telegram.secret.validation_failed', ['header_present' => $receivedSecret !== '']);
    relayJson(403, ['ok' => false, 'error' => 'Invalid Telegram webhook secret.']);
}
relayLog('telegram.secret.validated');

$body = file_get_contents('php://input');
if ($body === false || $body === '' || strlen($body) > $maxBodyBytes) {
    relayLog('telegram.body.validation_failed', [
        'body_present' => is_string($body) && $body !== '',
        'body_bytes' => is_string($body) ? strlen($body) : null,
        'max_body_bytes' => $maxBodyBytes,
    ]);
    relayJson(413, ['ok' => false, 'error' => 'Invalid or oversized webhook body.']);
}

$decoded = json_decode($body, true);
if (! is_array($decoded) || ! isset($decoded['update_id'])) {
    relayLog('telegram.update.validation_failed', [
        'decoded_as_object' => is_array($decoded),
        'has_update_id' => is_array($decoded) && isset($decoded['update_id']),
    ]);
    relayJson(400, ['ok' => false, 'error' => 'Webhook body is not a Telegram update.']);
}
relayLog('telegram.update.validated', [
    'update_id' => $decoded['update_id'],
    'body_bytes' => strlen($body),
    'update_keys' => array_keys($decoded),
]);

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'User-Agent: AMPTrace-Telegram-Relay/'.RELAY_VERSION,
    'X-Telegram-Bot-Api-Secret-Token: '.$telegramSecret,
];
if ($relayAuthSecret !== '') {
    $headers[] = 'X-AMPTrace-Relay-Authorization: Bearer '.$relayAuthSecret;
}
relayLog('laravel.headers.prepared', [
    'has_relay_authorization' => $relayAuthSecret !== '',
]);

try {
    $response = relayForward($laravelWebhookUrl, $body, $headers, max(1, min($timeout, 60)));
} catch (Throwable $exception) {
    relayLog('laravel.forwarding.failed', ['exception' => $exception->getMessage()]);
    error_log('[AMPTrace relay] '.$exception->getMessage());
    relayJson(502, ['ok' => false, 'error' => 'Could not reach Laravel upstream.']);
}

// Return a 2xx only when Laravel accepted the update. Telegram retries failed
// deliveries, which protects updates during a temporary upstream outage.
if ($response['status'] < 200 || $response['status'] >= 300) {
    relayLog('laravel.response.rejected', [
        'status' => $response['status'],
        'response_bytes' => strlen($response['body']),
    ]);
    error_log('[AMPTrace relay] upstream HTTP '.$response['status']);
    relayJson(502, ['ok' => false, 'error' => 'Laravel upstream rejected the update.']);
}

relayLog('relay.completed', ['upstream_status' => $response['status']]);
relayJson(200, ['ok' => true]);
