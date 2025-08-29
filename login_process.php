<?php
session_start();
require_once 'db.php';

$email = trim($_POST['email']);
$password = $_POST['password'];

// Definir redirección (por defecto a login.php)
$redirect = $_POST['redirect'] ?? 'login.php';

// Buscar al usuario activo con ese email
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND active = 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    // Crear sesión
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_role']  = $user['role'];   // <- oficial
    $_SESSION['role']       = $user['role'];   // <- alias para compatibilidad

    header("Location: choose_company.php");
    exit;
} else {
    header("Location: $redirect?error=1");
    exit;
}
