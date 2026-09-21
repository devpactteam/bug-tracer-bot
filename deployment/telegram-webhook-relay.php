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

const RELAY_VERSION = '1.0.0';

function relayConfig(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function relayJson(int $status, array $payload): never
{
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
    $curl = curl_init($url);
    if ($curl === false) {
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
        throw new RuntimeException($error !== '' ? $error : 'Relay request failed.');
    }

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

if ($laravelWebhookUrl === '' || ! filter_var($laravelWebhookUrl, FILTER_VALIDATE_URL)) {
    relayJson(500, ['ok' => false, 'error' => 'Relay upstream URL is not configured.']);
}
if (! str_starts_with(strtolower($laravelWebhookUrl), 'https://')) {
    relayJson(500, ['ok' => false, 'error' => 'Relay upstream URL must use HTTPS.']);
}
if ($telegramSecret === '') {
    relayJson(500, ['ok' => false, 'error' => 'Telegram webhook secret is not configured.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        relayJson(200, ['ok' => true, 'service' => 'AMPTrace Telegram webhook relay', 'version' => RELAY_VERSION]);
    }
    relayJson(405, ['ok' => false, 'error' => 'Only POST is supported.']);
}

$receivedSecret = relayHeader('X-Telegram-Bot-Api-Secret-Token');
if (! hash_equals($telegramSecret, $receivedSecret)) {
    relayJson(403, ['ok' => false, 'error' => 'Invalid Telegram webhook secret.']);
}

$body = file_get_contents('php://input');
if ($body === false || $body === '' || strlen($body) > $maxBodyBytes) {
    relayJson(413, ['ok' => false, 'error' => 'Invalid or oversized webhook body.']);
}

$decoded = json_decode($body, true);
if (! is_array($decoded) || ! isset($decoded['update_id'])) {
    relayJson(400, ['ok' => false, 'error' => 'Webhook body is not a Telegram update.']);
}

$headers = [
    'Content-Type: application/json',
    'Accept: application/json',
    'User-Agent: AMPTrace-Telegram-Relay/'.RELAY_VERSION,
    'X-Telegram-Bot-Api-Secret-Token: '.$telegramSecret,
];
if ($relayAuthSecret !== '') {
    $headers[] = 'X-AMPTrace-Relay-Authorization: Bearer '.$relayAuthSecret;
}

try {
    $response = relayForward($laravelWebhookUrl, $body, $headers, max(1, min($timeout, 60)));
} catch (Throwable $exception) {
    error_log('[AMPTrace relay] '.$exception->getMessage());
    relayJson(502, ['ok' => false, 'error' => 'Could not reach Laravel upstream.']);
}

// Return a 2xx only when Laravel accepted the update. Telegram retries failed
// deliveries, which protects updates during a temporary upstream outage.
if ($response['status'] < 200 || $response['status'] >= 300) {
    error_log('[AMPTrace relay] upstream HTTP '.$response['status'].' body: '.substr($response['body'], 0, 500));
    relayJson(502, ['ok' => false, 'error' => 'Laravel upstream rejected the update.']);
}

relayJson(200, ['ok' => true]);
