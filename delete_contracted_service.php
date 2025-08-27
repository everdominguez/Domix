<?php
require_once 'auth.php';
require_once 'db.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    exit;
}

$company_id = $_SESSION['company_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>ID no proporcionado.</div>";
    exit;
}

// Validar que el servicio exista y pertenezca a la empresa
$stmt = $pdo->prepare("SELECT id FROM contracted_services WHERE id = ? AND company_id = ?");
$stmt->execute([$id, $company_id]);
$service = $stmt->fetch();

if (!$service) {
    echo "<div class='alert alert-danger'>Servicio no encontrado o no autorizado.</div>";
    exit;
}

// Eliminar
try {
    $stmt = $pdo->prepare("DELETE FROM contracted_services WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);

    header("Location: contracted_services.php?deleted=1");
    exit;

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error al eliminar: " . $e->getMessage() . "</div>";
    echo "<a href='contracted_services.php' class='btn btn-secondary'>Regresar</a>";
}
