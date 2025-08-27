<?php
require_once __DIR__ . '/telegram_send.php';

$ok = telegram_send("🔔 Prueba explícita al grupo AVISOS");
var_dump($ok);
