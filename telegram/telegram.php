<?php
$BOT_TOKEN = "8467767673:AAHXPARxcs-8qUqP2wJa8Bzg6QlGs2ilT1c";
$CHAT_ID   = "-4955641569"; // Grupo AVISOS
$MENSAJE   = "📣 Hola equipo AVISOS! Este es un mensaje de prueba desde PHP.";

$url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";

$params = [
    "chat_id" => $CHAT_ID,
    "text"    => $MENSAJE,
    "parse_mode" => "HTML"
];

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $params,
]);
$response = curl_exec($ch);
curl_close($ch);

echo $response;
