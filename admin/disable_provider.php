<?php
require_once '../auth.php';
require_once '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: ../choose_company.php");
    exit();
}
$company_id = $_SESSION['company_id'];

// Validar ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID inválido.");
}

$id = (int) $_GET['id'];

// Validar que el proveedor pertenezca a la empresa
$stmt = $pdo->prepare("SELECT id FROM providers WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$exists = $stmt->fetch();

if (!$exists) {
    die("Proveedor no encontrado o no pertenece a esta empresa.");
}

// Desactivar proveedor
$stmt = $pdo->prepare("UPDATE providers SET active = 0 WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);

// Redirigir con confirmación
header("Location: provider.php?disabled=1");
exit;
