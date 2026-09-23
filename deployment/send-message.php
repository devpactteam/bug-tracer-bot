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

function gatewayTelegram(string $token, string $method, array $parameters): never
{
    $context = [
        'telegram_method' => $method,
        'parameter_keys' => array_keys($parameters),
        'has_token' => $token !== '',
        'text_length' => isset($parameters['text']) && is_string($parameters['text'])
            ? strlen($parameters['text'])
            : null,
        'has_reply_markup' => isset($parameters['reply_markup']),
    ];
    foreach (['chat_id', 'message_id', 'callback_query_id', 'file_id'] as $identifier) {
        if (isset($parameters[$identifier])) {
            $context[$identifier] = (string) $parameters[$identifier];
        }
    }
    gatewayLog('telegram.request.started', $context);
    $startedAt = microtime(true);
    $curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    if ($curl === false) {
        gatewayLog('telegram.curl.initialization_failed', ['telegram_method' => $method]);
        gatewayJson(500, ['ok' => false, 'description' => 'Could not initialise cURL.']);
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($parameters),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        gatewayLog('telegram.request.failed', [
            'telegram_method' => $method,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'curl_error' => $error !== '' ? str_replace($token, '[redacted]', $error) : 'unknown',
        ]);
        gatewayJson(502, ['ok' => false, 'description' => $error ?: 'Telegram request failed.']);
    }
    gatewayLog('telegram.response.received', [
        'telegram_method' => $method,
        'status' => $status,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'response_bytes' => strlen($response),
    ]);
    gatewayLog('response.sent', [
        'status' => $status > 0 ? $status : 502,
        'response_bytes' => strlen($response),
    ]);
    http_response_code($status > 0 ? $status : 502);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $response;
    exit;
}

gatewayLog('request.received', [
    'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'query_keys' => array_values(array_diff(array_keys($_GET), ['token'])),
]);
$input = gatewayInput();
$token = (string) gatewayValue($input, 'token');
$chatId = gatewayValue($input, 'chatId', 'chat_id');
$text = gatewayValue($input, 'text');
if ($token === '' || $chatId === null || $chatId === '' || ! is_string($text)) {
    gatewayLog('request.validation_failed', [
        'has_token' => $token !== '',
        'has_chat_id' => $chatId !== null && $chatId !== '',
        'has_text' => is_string($text),
    ]);
    gatewayJson(422, ['ok' => false, 'description' => 'token, chatId, and text are required.']);
}
gatewayLog('request.validated', [
    'chat_id' => (string) $chatId,
    'text_length' => strlen($text),
]);
$parameters = ['chat_id' => $chatId, 'text' => $text];
$parseMode = gatewayValue($input, 'parseMode', 'parse_mode');
if (is_string($parseMode) && $parseMode !== '') {
    $parameters['parse_mode'] = $parseMode;
}
$replyMarkup = gatewayValue($input, 'replyMarkup', 'reply_markup');
if (is_array($replyMarkup)) {
    $replyMarkup = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
if (is_string($replyMarkup) && $replyMarkup !== '') {
    $parameters['reply_markup'] = $replyMarkup;
}
gatewayLog('telegram.parameters.prepared', [
    'parse_mode' => $parameters['parse_mode'] ?? null,
    'has_reply_markup' => isset($parameters['reply_markup']),
]);

gatewayTelegram($token, 'sendMessage', $parameters);
