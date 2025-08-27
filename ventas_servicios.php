<?php
session_start();
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No has seleccionado una empresa.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
?>

<div class="container py-4">
    <h2 class="mb-4">🧾 Registrar Orden de Servicio</h2>

    <form method="POST" action="save_service_order.php">
        <div class="mb-3">
  <label for="client_name" class="form-label">Cliente</label>
  <input type="text" name="client_name" id="client_name" class="form-control" placeholder="Escribe para buscar..." autocomplete="off" required>
  <input type="hidden" name="client_id" id="client_id">
</div>


        <div class="mb-3">
            <label for="uuid" class="form-label">Folio Fiscal de la Factura</label>
            <input type="text" name="uuid" id="uuid" class="form-control" placeholder="Ej. ABC123456XYZ789...">
        </div>

        <div class="mb-3">
            <label for="sale_date" class="form-label">Fecha</label>
            <input type="date" name="sale_date" id="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Servicios</label>
            <table class="table table-bordered" id="service_table">
                <thead class="table-light">
                    <tr>
                        <th>Descripción</th>
                        <th>Unidad</th>
                        <th>Cantidad</th>
                        <th>Precio Unitario</th>
                        <th>Importe</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="description[]" class="form-control" required></td>
                        <td><input type="text" name="unit[]" class="form-control" value="servicio"></td>
                        <td><input type="number" step="any" name="quantity[]" class="form-control quantity" required></td>
                        <td><input type="number" step="any" name="unit_price[]" class="form-control unit_price" required></td>
                        <td><input type="text" class="form-control amount" readonly></td>
                        <td><button type="button" class="btn btn-danger btn-sm remove-row">🗑️</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" id="add_row" class="btn btn-secondary">➕ Agregar servicio</button>
        </div>

        <div class="mb-3">
            <label for="notes" class="form-label">Notas</label>
            <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">💾 Guardar Orden de Servicio</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    function updateAmounts() {
        document.querySelectorAll('#service_table tbody tr').forEach(row => {
            const qty = parseFloat(row.querySelector('.quantity')?.value) || 0;
            const price = parseFloat(row.querySelector('.unit_price')?.value) || 0;
            row.querySelector('.amount').value = (qty * price).toFixed(2);
        });
    }

    document.getElementById('add_row').addEventListener('click', () => {
        const newRow = document.querySelector('#service_table tbody tr').cloneNode(true);
        newRow.querySelectorAll('input').forEach(input => input.value = '');
        document.querySelector('#service_table tbody').appendChild(newRow);
    });

    document.querySelector('#service_table').addEventListener('input', (e) => {
        if (e.target.classList.contains('quantity') || e.target.classList.contains('unit_price')) {
            updateAmounts();
        }
    });

    document.querySelector('#service_table').addEventListener('click', (e) => {
        if (e.target.classList.contains('remove-row')) {
            const row = e.target.closest('tr');
            if (document.querySelectorAll('#service_table tbody tr').length > 1) {
                row.remove();
            }
        }
    });

    updateAmounts();
});
</script>

<!-- jQuery y jQuery UI para autocomplete -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<script>
$(function () {
    $("#client_name").autocomplete({
        source: "buscar_clientes.php",
        minLength: 2,
        select: function (event, ui) {
            $("#client_id").val(ui.item.id);
        }
    });
});
</script>


<?php include 'footer.php'; ?>
