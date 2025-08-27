<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
$id = $_GET['id'] ?? null;

if (!$id) {
    echo "<div class='alert alert-danger'>ID no proporcionado.</div>";
    include 'footer.php';
    exit;
}

// Obtener detalles del servicio
$stmt = $pdo->prepare("
    SELECT cs.*, p.name AS provider_name
    FROM contracted_services cs
    JOIN providers p ON cs.provider_id = p.id
    WHERE cs.id = ? AND cs.company_id = ?
");
$stmt->execute([$id, $company_id]);
$service = $stmt->fetch();

if (!$service) {
    echo "<div class='alert alert-danger'>Servicio no encontrado o no pertenece a esta empresa.</div>";
    include 'footer.php';
    exit;
}

// Calcular estatus
$today = date('Y-m-d');
$status = ($service['end_date'] >= $today) ? 'Activo' : 'Vencido';
$badge = ($status === 'Activo') ? 'success' : 'danger';
?>

<div class="container py-4">
    <h2 class="mb-4">🔎 Detalles del Servicio Contratado</h2>

    <div class="card">
        <div class="card-header bg-light">
            <strong>Código:</strong> <?= htmlspecialchars($service['service_code']) ?>
        </div>
        <div class="card-body">
            <p><strong>Proveedor:</strong> <?= htmlspecialchars($service['provider_name']) ?></p>
            <p><strong>Descripción:</strong><br><?= nl2br(htmlspecialchars($service['description'])) ?></p>
            <p><strong>Orden de Compra del Cliente:</strong> <?= htmlspecialchars($service['customer_po'] ?? '-') ?></p>
            <p><strong>Fecha de inicio:</strong> <?= htmlspecialchars($service['start_date']) ?></p>
            <p><strong>Fecha de fin:</strong> <?= htmlspecialchars($service['end_date']) ?></p>
            <p><strong>Estatus:</strong> <span class="badge bg-<?= $badge ?>"><?= $status ?></span></p>

            <?php if ($service['is_recurring']): ?>
                <p><strong>Gasto recurrente:</strong> Sí</p>
                <p><strong>Día de pago mensual:</strong> <?= (int)$service['payment_day'] ?></p>
            <?php else: ?>
                <p><strong>Gasto recurrente:</strong> No</p>
            <?php endif; ?>
        </div>
        <div class="card-footer text-end">
            <a href="contracted_services.php" class="btn btn-secondary">Regresar</a>
            <a href="edit_contracted_service.php?id=<?= $service['id'] ?>" class="btn btn-warning">Editar</a>
            <a href="delete_contracted_service.php?id=<?= $service['id'] ?>" class="btn btn-danger" onclick="return confirm('¿Eliminar este servicio?')">Eliminar</a>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
