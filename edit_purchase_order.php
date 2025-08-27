<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No has seleccionado empresa.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
$order_id = $_GET['id'] ?? null;

if (!$order_id) {
    echo "<div class='alert alert-danger'>ID no proporcionado.</div>";
    include 'footer.php';
    exit;
}

// Obtener orden
$stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND company_id = ?");
$stmt->execute([$order_id, $company_id]);
$order = $stmt->fetch();

if (!$order) {
    echo "<div class='alert alert-danger'>Orden no encontrada.</div>";
    include 'footer.php';
    exit;
}

// Obtener partidas
$stmtItems = $pdo->prepare("SELECT * FROM purchase_order_items WHERE purchase_order_id = ?");
$stmtItems->execute([$order_id]);
$items = $stmtItems->fetchAll();

// Actualizar datos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = $_POST['project_id'];
    $subproject_id = $_POST['subproject_id'];
    $status = $_POST['status'];
    $codes = $_POST['code'];
    $descriptions = $_POST['description'];
    $quantities = $_POST['quantity'];
    $units = $_POST['unit'];
    $unit_prices = $_POST['unit_price'];
    $totals = $_POST['total'];

    // Actualizar cabecera
    $stmtUpdate = $pdo->prepare("UPDATE purchase_orders SET project_id = ?, subproject_id = ?, status = ? WHERE id = ?");
    $stmtUpdate->execute([$project_id, $subproject_id, $status, $order_id]);

    // Eliminar partidas anteriores
    $pdo->prepare("DELETE FROM purchase_order_items WHERE purchase_order_id = ?")->execute([$order_id]);

    // Insertar nuevas partidas
    $stmtItem = $pdo->prepare("INSERT INTO purchase_order_items (purchase_order_id, code, description, quantity, unit, unit_price, total) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($codes as $i => $code) {
        $stmtItem->execute([
            $order_id,
            $code,
            $descriptions[$i],
            $quantities[$i],
            $units[$i],
            $unit_prices[$i],
            $totals[$i]
        ]);
    }

    echo "<div class='alert alert-success'>Orden actualizada correctamente.</div>";
}
?>

<div class="container py-4">
    <h2 class="mb-4">✏️ Editar Orden de Compra</h2>

    <form method="POST" class="card p-4 shadow-sm mb-4">
        <div class="mb-3">
            <label for="project_id" class="form-label">Proyecto</label>
            <select name="project_id" id="project_id" class="form-select" required>
                <option value="">Selecciona...</option>
                <?php
                $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ?");
                $stmt->execute([$company_id]);
                foreach ($stmt as $row) {
                    $selected = $row['id'] == $order['project_id'] ? 'selected' : '';
                    echo "<option value='{$row['id']}' $selected>{$row['name']}</option>";
                }
                ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="subproject_id" class="form-label">Subproyecto</label>
            <select name="subproject_id" id="subproject_id" class="form-select" required>
                <option value="">Selecciona...</option>
                <!-- Cargado por JS -->
            </select>
        </div>

        <div class="mb-3">
            <label for="status" class="form-label">Estatus</label>
            <select name="status" id="status" class="form-select" required>
                <?php
                $statuses = ['Por realizar', 'Realizada', 'Recibida'];
                foreach ($statuses as $statusOption) {
                    $selected = $order['status'] === $statusOption ? 'selected' : '';
                    echo "<option value=\"$statusOption\" $selected>$statusOption</option>";
                }
                ?>
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th>Código</th>
                        <th>Descripción</th>
                        <th>Cantidad</th>
                        <th>Unidad</th>
                        <th>Precio Unitario</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><input type="text" name="code[]" class="form-control" value="<?= htmlspecialchars($item['code']) ?>"></td>
                            <td><input type="text" name="description[]" class="form-control" value="<?= htmlspecialchars($item['description']) ?>"></td>
                            <td><input type="number" step="any" name="quantity[]" class="form-control" value="<?= number_format((float)$item['quantity'], 2, '.', '') ?>"></td>
                            <td><input type="text" name="unit[]" class="form-control" value="<?= htmlspecialchars($item['unit']) ?>"></td>
                            <td><input type="number" step="any" name="unit_price[]" class="form-control" value="<?= number_format((float)$item['unit_price'], 2, '.', '') ?>"></td>
                            <td><input type="number" step="any" name="total[]" class="form-control" value="<?= number_format((float)$item['total'], 2, '.', '') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-success btn-lg">💾 Guardar Cambios</button>
        <a href="view_purchase_order.php" class="btn btn-secondary btn-lg">Cancelar</a>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.getElementById('project_id');
    const subprojectSelect = document.getElementById('subproject_id');
    const selectedSubproject = <?= json_encode($order['subproject_id']) ?>;

    function loadSubprojects(projectId) {
        fetch('get_subprojects.php?project_id=' + projectId)
            .then(res => res.json())
            .then(data => {
                subprojectSelect.innerHTML = '<option value="">Selecciona...</option>';
                data.forEach(sp => {
                    const selected = sp.id == selectedSubproject ? 'selected' : '';
                    subprojectSelect.innerHTML += `<option value="${sp.id}" ${selected}>${sp.name}</option>`;
                });
            });
    }

    if (projectSelect.value) {
        loadSubprojects(projectSelect.value);
    }

    projectSelect.addEventListener('change', function () {
        loadSubprojects(this.value);
    });
});
</script>

<?php include 'footer.php'; ?>
