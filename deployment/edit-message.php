<?php

declare(strict_types=1);

function gatewayInput(): array
{
    $json = json_decode((string) file_get_contents('php://input'), true);
    $json = is_array($json) ? $json : [];
    $body = $json['body'] ?? [];
    if (is_string($body)) {
        $body = json_decode($body, true);
    }

    return array_merge(is_array($body) ? $body : [], $json, $_GET);
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
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function gatewayTelegram(string $token, string $method, array $parameters): never
{
    $curl = curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    if ($curl === false) {
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
        gatewayJson(502, ['ok' => false, 'description' => $error ?: 'Telegram request failed.']);
    }
    http_response_code($status > 0 ? $status : 502);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $response;
    exit;
}

$input = gatewayInput();
$token = (string) gatewayValue($input, 'token');
$chatId = gatewayValue($input, 'chatId', 'chat_id');
$messageId = gatewayValue($input, 'messageId', 'message_id');
$text = gatewayValue($input, 'text');
if ($token === '' || $chatId === null || $chatId === '' || $messageId === null || $messageId === '' || ! is_string($text)) {
    gatewayJson(422, ['ok' => false, 'description' => 'token, chatId, messageId, and text are required.']);
}
$parameters = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
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

gatewayTelegram($token, 'editMessageText', $parameters);
