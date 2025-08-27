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

// Obtener servicio
$stmt = $pdo->prepare("
    SELECT * FROM contracted_services 
    WHERE id = ? AND company_id = ?
");
$stmt->execute([$id, $company_id]);
$service = $stmt->fetch();

if (!$service) {
    echo "<div class='alert alert-danger'>Servicio no encontrado o no pertenece a esta empresa.</div>";
    include 'footer.php';
    exit;
}

// Obtener proveedores
$stmt = $pdo->prepare("SELECT id, name FROM providers WHERE company_id = ? ORDER BY name ASC");
$stmt->execute([$company_id]);
$providers = $stmt->fetchAll();
?>

<div class="container py-4">
    <h2 class="mb-4">✏️ Editar Servicio Contratado</h2>

    <form method="POST" action="update_contracted_service.php">
        <input type="hidden" name="id" value="<?= $service['id'] ?>">
        <input type="hidden" name="company_id" value="<?= $company_id ?>">

        <div class="card">
            <div class="card-body">

                <div class="mb-3">
                    <label class="form-label">Código del Servicio</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($service['service_code']) ?>" readonly>
                </div>

                <div class="mb-3">
                    <label for="provider_id" class="form-label">Proveedor</label>
                    <select name="provider_id" id="provider_id" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($p['id'] == $service['provider_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Descripción del Servicio</label>
                    <textarea name="description" id="description" class="form-control" rows="3" required><?= htmlspecialchars($service['description']) ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="customer_po" class="form-label">Orden de Compra del Cliente</label>
                    <input type="text" name="customer_po" id="customer_po" class="form-control" value="<?= htmlspecialchars($service['customer_po']) ?>">
                </div>

                <div class="mb-3">
                    <label for="start_date" class="form-label">Fecha de inicio</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?= $service['start_date'] ?>" required>
                </div>

                <div class="mb-3">
                    <label for="end_date" class="form-label">Fecha de fin</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?= $service['end_date'] ?>" required>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring" <?= $service['is_recurring'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_recurring">¿Es gasto recurrente?</label>
                </div>

                <div class="mb-3" id="payment_date_group" style="<?= $service['is_recurring'] ? '' : 'display:none;' ?>">
                    <label for="payment_day" class="form-label">Día de pago mensual</label>
                    <input type="number" name="payment_day" id="payment_day" class="form-control" min="1" max="31" value="<?= (int)$service['payment_day'] ?>">
                    <div class="form-text">Selecciona el día del mes en que se realiza el pago (1-31).</div>
                </div>

                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                <a href="contracted_services.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </div>
    </form>
</div>

<script>
document.getElementById('provider_id').addEventListener('change', function () {
    const providerId = this.value;
    if (providerId) {
        fetch('get_provider_term.php?provider_id=' + providerId)
            .then(res => res.json())
            .then(data => {
                const term = parseInt(data.term_days);
                const startInput = document.getElementById('start_date');
                const endInput = document.getElementById('end_date');

                startInput.addEventListener('change', function () {
                    const startDate = new Date(startInput.value);
                    if (!isNaN(startDate) && term > 0) {
                        const calculatedEnd = new Date(startDate);
                        calculatedEnd.setDate(startDate.getDate() + term);
                        endInput.value = calculatedEnd.toISOString().split('T')[0];
                    }
                });
            });
    }
});

// Mostrar/ocultar campo de pago mensual
document.getElementById('is_recurring').addEventListener('change', function () {
    const paymentGroup = document.getElementById('payment_date_group');
    paymentGroup.style.display = this.checked ? 'block' : 'none';
});
</script>

<?php include 'footer.php'; ?>
