<?php
require_once '../auth.php';
require_once '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Asegurar que haya empresa activa
    $company_id = $_SESSION['company_id'] ?? $_POST['company_id'] ?? null;
    if (!$company_id || !is_numeric($company_id)) {
        die("Empresa no válida.");
    }

    // Limpiar datos
    $name = trim($_POST['name']);
    $rfc = trim($_POST['rfc']);
    $contact_name = trim($_POST['contact_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $address = trim($_POST['address']);
    $payment_terms = trim($_POST['payment_terms']);
    $notes = trim($_POST['notes']);

    // Validación básica
    if ($name === '') {
        die("El nombre del proveedor es obligatorio.");
    }

    // Insertar en base de datos
    $stmt = $pdo->prepare("INSERT INTO providers (name, rfc, contact_name, phone, email, address, payment_terms, notes, company_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $name,
        $rfc,
        $contact_name,
        $phone,
        $email,
        $address,
        $payment_terms,
        $notes,
        $company_id
    ]);

header("Location: /admin/provider.php?success=1");
    exit;
} else {
    echo "Acceso no permitido.";
}
