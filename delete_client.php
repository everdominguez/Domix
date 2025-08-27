<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    die("No autorizado.");
}

$company_id = $_SESSION['company_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    die("ID no proporcionado.");
}

// Validar que el cliente sea de la empresa
$stmt = $pdo->prepare("SELECT id FROM clients WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$client = $stmt->fetch();

if (!$client) {
    die("Cliente no encontrado o no autorizado.");
}

// Eliminar cliente
$stmt = $pdo->prepare("DELETE FROM clients WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);

header("Location: clients.php?success=3");
exit;
