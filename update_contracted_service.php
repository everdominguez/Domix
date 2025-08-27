<?php
require_once 'auth.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: contracted_services.php");
    exit;
}

$id           = $_POST['id'] ?? null;
$company_id   = $_POST['company_id'] ?? null;
$provider_id  = $_POST['provider_id'] ?? null;
$description  = trim($_POST['description'] ?? '');
$start_date   = $_POST['start_date'] ?? null;
$end_date     = $_POST['end_date'] ?? null;
$customer_po  = trim($_POST['customer_po'] ?? null);

// Nuevos campos recurrentes
$is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
$payment_day  = $_POST['payment_day'] ?? null;
$payment_day  = ($is_recurring && $payment_day !== '') ? (int)$payment_day : null;

// Validación básica
$errors = [];
if (!$id || !$company_id || !$provider_id || !$description || !$start_date || !$end_date) {
    $errors[] = "Todos los campos obligatorios deben completarse.";
}

if ($is_recurring && (!$payment_day || $payment_day < 1 || $payment_day > 31)) {
    $errors[] = "El día de pago mensual debe estar entre 1 y 31.";
}

// Validar que el servicio pertenezca a la empresa activa
$stmt = $pdo->prepare("SELECT id FROM contracted_services WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
if (!$stmt->fetch()) {
    $errors[] = "No autorizado para editar este servicio.";
}

if (!empty($errors)) {
    echo "<div class='alert alert-danger'>" . implode("<br>", $errors) . "</div>";
    echo "<a href='edit_contracted_service.php?id=$id' class='btn btn-secondary'>Regresar</a>";
    exit;
}

// Actualizar
try {
    $stmt = $pdo->prepare("
        UPDATE contracted_services
        SET provider_id = ?, description = ?, start_date = ?, end_date = ?, is_recurring = ?, payment_day = ?, customer_po = ?
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([
        $provider_id,
        $description,
        $start_date,
        $end_date,
        $is_recurring,
        $payment_day,
        $customer_po,
        $id,
        $company_id
    ]);

    header("Location: contracted_services.php?success=1");
    exit;

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error al actualizar: " . $e->getMessage() . "</div>";
    echo "<a href='edit_contracted_service.php?id=$id' class='btn btn-secondary'>Regresar</a>";
}
