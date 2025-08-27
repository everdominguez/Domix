<?php
require_once '../auth.php';
require_once '../db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Validar que se haya enviado por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = $_SESSION['company_id'] ?? null;
    if (!$company_id || !is_numeric($company_id)) {
        die("Empresa no válida.");
    }

    $id = (int) $_POST['id'];
    $name = trim($_POST['name']);
    $business_name = trim($_POST['business_name']);
    $rfc = trim($_POST['rfc']);
    $contact_name = trim($_POST['contact_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $bank = trim($_POST['bank']);
    $account = trim($_POST['account']);
    $address = trim($_POST['address']);
    $payment_terms = trim($_POST['payment_terms']);
    $notes = trim($_POST['notes']);

    if ($id <= 0 || $name === '') {
        die("Datos inválidos.");
    }

    // Verificar que el proveedor exista y pertenezca a la empresa
    $stmt = $pdo->prepare("SELECT id FROM providers WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    if (!$stmt->fetch()) {
        die("Proveedor no encontrado o no pertenece a esta empresa.");
    }

    // Actualizar proveedor con campos completos
    $stmt = $pdo->prepare("UPDATE providers 
        SET name = ?, business_name = ?, rfc = ?, contact_name = ?, phone = ?, email = ?, bank = ?, account = ?, 
            address = ?, payment_terms = ?, notes = ?
        WHERE id = ? AND company_id = ?");

    $stmt->execute([
        $name,
        $business_name,
        $rfc,
        $contact_name,
        $phone,
        $email,
        $bank,
        $account,
        $address,
        $payment_terms,
        $notes,
        $id,
        $company_id
    ]);

    // Redirigir a pantalla de listado
    header("Location: /admin/provider.php?updated=1");
    exit;
} else {
    echo "Acceso no permitido.";
}
