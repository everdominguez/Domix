<?php
// telegram/telegram_send.php
declare(strict_types=1);

/**
 * Enviar mensaje a un grupo/canal/usuario en Telegram.
 *
 * @param string $text   Texto del mensaje (permite HTML).
 * @param bool   $silent true = silencioso (sin notificación), false = normal.
 * @return bool  true si se envió correctamente, false si hubo error.
 */
function telegram_send(string $text, bool $silent = false): bool {
    // 🔑 Token de tu bot (de @BotFather)
    $token = '8467767673:AAHXPARxcs-8qUqP2wJa8Bzg6QlGs2ilT1c';

    // 🆔 ID del chat destino (grupo AVISOS)
    $chatId = '-4955641569';

    // === Preparar request ===
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'disable_notification' => $silent,
    ];

    // === Enviar con cURL ===
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        error_log('[TELEGRAM] cURL error: ' . $err);
        return false;
    }
    if ($resp === false) {
        error_log('[TELEGRAM] Respuesta vacía');
        return false;
    }

    $data = json_decode($resp, true);
    if (!is_array($data) || !($data['ok'] ?? false)) {
        error_log('[TELEGRAM] Error API: ' . ($data['description'] ?? 'respuesta inválida'));
        return false;
    }

    return true;
}
