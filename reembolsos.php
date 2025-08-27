<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No autorizado.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];

// Obtener métodos de pago bancarios
$stmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? AND type = 'BANCO'");
$stmt->execute([$company_id]);
$bank_methods = $stmt->fetchAll();

// Obtener lista de deudores
// Solo deudores con pagos pendientes
$stmt = $pdo->prepare("
    SELECT DISTINCT pm.id, pm.name
    FROM payment_methods pm
    JOIN payments p ON p.source_id = pm.id
    WHERE pm.company_id = ? 
      AND pm.type = 'DEUDOR'
      AND p.source_type = 2
      AND p.reimburses_payment_id IS NULL
    ORDER BY pm.name
");
$stmt->execute([$company_id]);
$debtors = $stmt->fetchAll();

?>

<div class="container mt-4">
    <div class="card shadow-sm">
        <div class="card-body">
            <h4 class="card-title mb-4">💸 Reembolsar a Deudores</h4>

            <form method="POST" action="procesar_reembolso.php">
                <!-- Filtro de deudores -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="filtro_deudor" class="form-label">🔍 Filtrar por deudor:</label>
                        <select id="filtro_deudor" class="form-select form-select-sm">
                            <option value="">-- Todos --</option>
                            <?php foreach ($debtors as $debtor): ?>
                                <option value="<?= $debtor['id'] ?>"><?= htmlspecialchars($debtor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Tabla dinámica -->
                <div id="tabla_pagos_pendientes" class="mb-4"></div>

                <!-- Datos principales: forma de pago y fecha -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="forma_pago" class="form-label">🏦 Forma de pago bancaria:</label>
                        <select name="forma_pago" id="forma_pago" class="form-select form-select-sm" required>
                            <option value="">-- Elegir --</option>
                            <?php foreach ($bank_methods as $method): ?>
                                <option value="<?= $method['id'] ?>"><?= htmlspecialchars($method['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="fecha" class="form-label">📅 Fecha del reembolso:</label>
                        <input type="date" name="fecha" id="fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>

                <!-- Observaciones -->
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label for="observaciones" class="form-label">📝 Observaciones:</label>
                        <input type="text" name="observaciones" id="observaciones" class="form-control form-control-sm" placeholder="Ej. Reembolso completo a múltiples deudores">
                    </div>
                </div>

                <!-- Total + botón -->
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="alert alert-secondary py-2 mb-0">
                            💰 <strong>Total a reembolsar:</strong> <span id="total_reembolso">$0.00</span>
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            💰 Registrar Reembolso
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const tabla = document.getElementById('tabla_pagos_pendientes');
    const filtro = document.getElementById('filtro_deudor');
    const totalSpan = document.getElementById('total_reembolso');

    function cargarPagos(deudor_id = '') {
        fetch(`get_pending_payments.php?debtor_id=${deudor_id}`)
            .then(res => res.json())
            .then(data => {
                if (data.length === 0) {
                    tabla.innerHTML = '<div class="alert alert-info">No hay pagos pendientes para el deudor seleccionado.</div>';
                    return;
                }

                let html = `
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th></th>
                                <th>Fecha</th>
                                <th>Deudor</th>
                                <th>Monto</th>
                                <th>Notas</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                data.forEach(pago => {
                    html += `
                        <tr>
                            <td><input type="checkbox" name="pagos[]" value="${pago.id}" class="chk_pago" data-monto="${pago.amount}"></td>
                            <td>${pago.payment_date}</td>
                            <td>${pago.debtor_name}</td>
                            <td>$${parseFloat(pago.amount).toFixed(2)}</td>
                            <td>${pago.notes || ''}</td>
                        </tr>
                    `;
                });

                html += '</tbody></table>';
                tabla.innerHTML = html;
                actualizarTotal();

                document.querySelectorAll('.chk_pago').forEach(chk => {
                    chk.addEventListener('change', actualizarTotal);
                });
            });
    }

    function actualizarTotal() {
        let total = 0;
        document.querySelectorAll('.chk_pago:checked').forEach(chk => {
            total += parseFloat(chk.dataset.monto);
        });
        totalSpan.textContent = `$${total.toFixed(2)}`;
    }

    filtro.addEventListener('change', () => {
        cargarPagos(filtro.value);
    });

    cargarPagos();
});
</script>

<?php include 'footer.php'; ?>
