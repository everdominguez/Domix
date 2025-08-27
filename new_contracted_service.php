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

// Generar siguiente código de servicio
$stmt = $pdo->query("SELECT MAX(id) AS last_id FROM contracted_services");
$last_id = $stmt->fetchColumn();
$next_code = 'SRV-' . str_pad($last_id + 1, 5, '0', STR_PAD_LEFT);

// Obtener proveedores
$stmt = $pdo->prepare("SELECT id, name FROM providers WHERE company_id = ? ORDER BY name ASC");
$stmt->execute([$company_id]);
$providers = $stmt->fetchAll();
?>

<div class="container py-4">
    <h2 class="mb-4">➕ Nuevo Servicio Contratado</h2>

    <form method="POST" action="save_contracted_service.php">
        <div class="card">
            <div class="card-body">

                <input type="hidden" name="company_id" value="<?= $company_id ?>">
                <input type="hidden" name="service_code" value="<?= $next_code ?>">

                <div class="mb-3">
                    <label for="provider_id" class="form-label">Proveedor</label>
                    <select name="provider_id" id="provider_id" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?= $provider['id'] ?>"><?= htmlspecialchars($provider['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Descripción del Servicio</label>
                    <textarea name="description" id="description" class="form-control" rows="3" required></textarea>
                </div>

                <div class="mb-3">
                    <label for="customer_po" class="form-label">Orden de Compra del Cliente</label>
                    <input type="text" name="customer_po" id="customer_po" class="form-control" placeholder="Ej. OC-12345">
                </div>

                <div class="mb-3">
                    <label for="start_date" class="form-label">Fecha de inicio</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label for="end_date" class="form-label">Fecha de fin</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" required>
                </div>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="is_recurring" name="is_recurring">
                    <label class="form-check-label" for="is_recurring">¿Es gasto recurrente?</label>
                </div>

                <div class="mb-3" id="payment_date_group" style="display: none;">
                    <label for="payment_day" class="form-label">Día de pago mensual</label>
                    <input type="number" name="payment_day" id="payment_day" class="form-control" min="1" max="31">
                    <div class="form-text">Selecciona el día del mes en que se realiza el pago (1-31).</div>
                </div>

                <button type="submit" class="btn btn-primary">Guardar Servicio</button>
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

                // Recalcular fecha de fin automáticamente al cambiar la fecha de inicio
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

// Mostrar u ocultar campo de día de pago si es recurrente
document.getElementById('is_recurring').addEventListener('change', function () {
    const paymentGroup = document.getElementById('payment_date_group');
    paymentGroup.style.display = this.checked ? 'block' : 'none';
});
</script>

<?php include 'footer.php'; ?>
