<?php

declare(strict_types=1);

require __DIR__.'/load-env.php';

function gatewayLog(string $step, array $context = []): void
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

function gatewayInput(): array
{
    $json = json_decode((string) file_get_contents('php://input'), true);
    $json = is_array($json) ? $json : [];
    $body = $json['body'] ?? [];
    if (is_string($body)) {
        $body = json_decode($body, true);
    }

    $input = array_merge(is_array($body) ? $body : [], $json, $_GET);
    gatewayLog('input.normalized', [
        'input_keys' => array_values(array_diff(array_keys($input), ['token'])),
        'has_token' => isset($input['token']) && $input['token'] !== '',
    ]);

    return $input;
}

function gatewayValue(array $input, string ...$keys): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $input)) {
            return $input[$key];
        }
    }

    return null;
}

function gatewayJson(int $status, array $payload): never
{
    gatewayLog('response.sent', [
        'status' => $status,
        'ok' => $payload['ok'] ?? null,
        'has_description' => isset($payload['description']),
    ]);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

gatewayLog('request.received', [
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'query_keys' => array_values(array_diff(array_keys($_GET), ['token'])),
]);
$input = gatewayInput();
$token = (string) gatewayValue($input, 'token');
$filePath = gatewayValue($input, 'filePath', 'file_path');
if ($token === '' || ! is_string($filePath) || $filePath === '') {
    gatewayLog('request.validation_failed', [
        'has_token' => $token !== '',
        'has_file_path' => is_string($filePath) && $filePath !== '',
    ]);
    gatewayJson(422, ['ok' => false, 'description' => 'token and filePath are required.']);
}
gatewayLog('request.validated', [
    'file_path' => $filePath,
]);
$encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim($filePath, '/'))));
gatewayLog('telegram.file_request.started', [
    'file_path' => $filePath,
    'has_token' => $token !== '',
]);
$startedAt = microtime(true);
$curl = curl_init('https://api.telegram.org/file/bot'.$token.'/'.$encodedPath);
if ($curl === false) {
    gatewayLog('telegram.curl.initialization_failed', ['operation' => 'downloadFile']);
    gatewayJson(500, ['ok' => false, 'description' => 'Could not initialise cURL.']);
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($curl);
$error = curl_error($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
curl_close($curl);
if ($response === false) {
    gatewayLog('telegram.file_request.failed', [
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'curl_error' => $error !== '' ? str_replace($token, '[redacted]', $error) : 'unknown',
    ]);
    gatewayJson(502, ['ok' => false, 'description' => $error ?: 'Telegram file download failed.']);
}

gatewayLog('telegram.file_response.received', [
    'status' => $status,
    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
    'response_bytes' => strlen($response),
]);
http_response_code($status > 0 ? $status : 502);
header('Content-Type: '.($contentType !== '' ? $contentType : 'application/octet-stream'));
header('Cache-Control: no-store');
gatewayLog('response.sent', [
    'status' => $status > 0 ? $status : 502,
    'response_bytes' => strlen($response),
]);
echo $response;
