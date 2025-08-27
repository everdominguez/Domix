<?php
require_once '../auth.php';
require_once '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Validar ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$id = (int) $_GET['id'];

// Marcar usuario como activo
$stmt = $pdo->prepare("UPDATE users SET active = 1 WHERE id = ?");
$stmt->execute([$id]);

// Redirigir al listado
header("Location: user.php?filtro=inactivos&enabled=1");
exit;
