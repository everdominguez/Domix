<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>No autorizado.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];
$project_id = $_GET['project_id'] ?? '';
$subproject_id = $_GET['subproject_id'] ?? '';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

$query = "
    SELECT po.id, po.code AS po_number, po.created_at, p.name AS project_name, s.name AS subproject_name,
           COUNT(i.id) AS item_count, COALESCE(SUM(i.total), 0) AS total_amount
    FROM purchase_orders po
    LEFT JOIN projects p ON po.project_id = p.id
    LEFT JOIN subprojects s ON po.subproject_id = s.id
    LEFT JOIN purchase_order_items i ON po.id = i.purchase_order_id
    WHERE po.company_id = :company_id
";
$params = [':company_id' => $company_id];

if ($project_id) {
    $query .= " AND po.project_id = :project_id";
    $params[':project_id'] = $project_id;
}
if ($subproject_id) {
    $query .= " AND po.subproject_id = :subproject_id";
    $params[':subproject_id'] = $subproject_id;
}
if ($desde && $hasta) {
    $query .= " AND DATE(po.created_at) BETWEEN :desde AND :hasta";
    $params[':desde'] = $desde;
    $params[':hasta'] = $hasta;
}

$query .= " GROUP BY po.id ORDER BY po.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$ordenes = $stmt->fetchAll();

$proyectos = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$proyectos->execute([$company_id]);
$proyectos = $proyectos->fetchAll();
?>

<div class="container py-4">
    <h2 class="mb-4">📋 Listado de Órdenes de Compra</h2>

    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-3">
            <label class="form-label">Proyecto</label>
            <select name="project_id" id="project_id" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($proyectos as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $project_id == $p['id'] ? 'selected' : '' ?>><?= $p['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label">Subproyecto</label>
            <select name="subproject_id" id="subproject_id" class="form-select">
                <option value="">Todos</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label">Desde</label>
            <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($desde) ?>">
        </div>

        <div class="col-md-2">
            <label class="form-label">Hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($hasta) ?>">
        </div>

        <div class="col-md-2 d-grid align-items-end">
            <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead class="table-light">
                <tr>
                    <th>Orden</th>
                    <th>Proyecto</th>
                    <th>Subproyecto</th>
                    <th>Fecha</th>
                    <th>Partidas</th>
                    <th>Total</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ordenes as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['po_number']) ?></td>
                        <td><?= htmlspecialchars($o['project_name']) ?></td>
                        <td><?= htmlspecialchars($o['subproject_name']) ?></td>
                        <td><?= date('d/m/Y', strtotime($o['created_at'])) ?></td>
                        <td><?= $o['item_count'] ?></td>
                        <td>$<?= number_format($o['total_amount'], 2) ?></td>
                        <td>
                            <a href="edit_purchase_order.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-warning">✏️</a>
                            <a href="delete_purchase_order.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar esta orden?')">🗑️</a>
                            <a href="view_purchase_order.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-info">🔍</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($ordenes)): ?>
                    <tr><td colspan="7" class="text-center">No se encontraron resultados.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.getElementById('project_id');
    const subprojectSelect = document.getElementById('subproject_id');

    function cargarSubproyectos(projectId) {
        subprojectSelect.innerHTML = '<option>Cargando...</option>';
        fetch(`get_subprojects.php?project_id=${projectId}`)
            .then(res => res.json())
            .then(data => {
                subprojectSelect.innerHTML = '<option value="">Todos</option>';
                data.forEach(sub => {
                    const selected = sub.id == <?= json_encode($subproject_id) ?> ? 'selected' : '';
                    subprojectSelect.innerHTML += `<option value="${sub.id}" ${selected}>${sub.name}</option>`;
                });
            });
    }

    if (projectSelect.value) {
        cargarSubproyectos(projectSelect.value);
    }

    projectSelect.addEventListener('change', () => {
        cargarSubproyectos(projectSelect.value);
    });
});
</script>

<?php include 'footer.php'; ?>
