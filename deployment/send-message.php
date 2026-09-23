<?php

$data = file_get_contents('php://input');
$data = json_decode($data, true);

$text = "";
$chat_id = "";
if (array_key_exists('text', $data)) {
    $text = $data["text"];
}
if (array_key_exists('chatId', $data)) {
    $chat_id = $data["chatId"];
}
if (array_key_exists('body', $data)) {
    if (array_key_exists('text', $data['body'])) {
        $text = $data['body']["text"];
    }
    if (array_key_exists('chatId', $data['body'])) {
        $chat_id = $data['body']["chatId"];
    }
    if (array_key_exists('chat_id', $data['body'])) {
        $chat_id = $data['body']["chat_id"];
    }
}


$url = "https://api.telegram.org/bot" . $data["token"] . "/sendMessage";
$data = [
    'chat_id' => $chat_id,
    'text' => $text,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo 'Error: ' . curl_error($ch);
}
curl_close($ch);
echo $response;