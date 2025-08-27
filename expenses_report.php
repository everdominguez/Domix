<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Obtener todos los proyectos para filtro
$stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedProject = null;
$start_date = null;
$end_date = null;
$reportType = 'all';
$data = [];
$totalCategoria = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['project_id'])) {
    $project_id = $_GET['project_id'];
    $reportType = $_GET['report_type'] ?? 'all';
    $selectedProject = array_filter($projects, fn($p) => $p['id'] == $project_id);
    $selectedProject = reset($selectedProject);

    $category = $_GET['category'] ?? '';
    $subcategory = $_GET['subcategory'] ?? '';

    if ($reportType === 'range') {
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
    } else {
        $stmt = $pdo->prepare("SELECT start_date FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $start_date = $stmt->fetchColumn();
        $end_date = date('Y-m-d');
    }

    // Consulta principal: agrupado por categoría y subcategoría
    $query = "SELECT category, subcategory, SUM(amount) AS total
              FROM expenses
              WHERE project_id = :project_id
                AND expense_date BETWEEN :start_date AND :end_date";

    $params = [
        ':project_id' => $project_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ];

    if ($category !== '') {
        $query .= " AND category = :category";
        $params[':category'] = $category;
    }
    if ($subcategory !== '') {
        $query .= " AND subcategory = :subcategory";
        $params[':subcategory'] = $subcategory;
    }

    $query .= " GROUP BY category, subcategory ORDER BY category, subcategory";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Consulta: total de la categoría seleccionada
    if ($category !== '') {
        $queryTotalCat = "SELECT SUM(amount) AS total_categoria
                          FROM expenses
                          WHERE project_id = :project_id
                            AND expense_date BETWEEN :start_date AND :end_date
                            AND category = :category";
        $stmtTotalCat = $pdo->prepare($queryTotalCat);
        $stmtTotalCat->execute([
            ':project_id' => $project_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date,
            ':category' => $category
        ]);
        $totalCategoria = $stmtTotalCat->fetchColumn();
    }
}
?>

<h2 class="mb-4">💸 Reporte de Gastos</h2>

<div class="card shadow mb-4">
    <div class="card-header">Filtros del Reporte</div>
    <div class="card-body">
        <form method="GET" action="expenses_report.php">
            <div class="row mb-3">
                <div class="col-md-3">
                    <label class="form-label">Proyecto</label>
                    <select name="project_id" class="form-select" required>
                        <option value="">Selecciona un proyecto</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= isset($_GET['project_id']) && $_GET['project_id'] == $p['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tipo de Reporte</label>
                    <select name="report_type" id="report_type" class="form-select">
                        <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>Desde inicio del proyecto</option>
                        <option value="range" <?= $reportType === 'range' ? 'selected' : '' ?>>Rango de fechas</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Desde</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date ?? '') ?>">
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Categoría</label>
                    <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($_GET['category'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Subcategoría</label>
                    <input type="text" name="subcategory" class="form-control" value="<?= htmlspecialchars($_GET['subcategory'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Generar Reporte</button>
        </form>
    </div>
</div>

<?php if ($category !== '' && $totalCategoria): ?>
<div class="mb-3">
    <h5 class="text-primary">Total de la categoría <strong><?= htmlspecialchars($category) ?></strong>: 
        $<?= number_format($totalCategoria, 2) ?></h5>
</div>
<?php endif; ?>

<?php if (!empty($data)): ?>
<div class="table-responsive">
    <table class="table table-bordered table-hover shadow-sm">
        <thead class="table-dark">
            <tr>
                <th>Categoría</th>
                <th>Subcategoría</th>
                <th>Total Gasto</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['category']) ?></td>
                    <td><?= htmlspecialchars($row['subcategory']) ?></td>
                    <td class="fw-bold">$<?= number_format($row['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<canvas id="barChart" height="100"></canvas>
<?php elseif ($selectedProject): ?>
    <p class="text-muted">No se encontraron gastos para este proyecto con los filtros seleccionados.</p>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.getElementById('report_type').addEventListener('change', function () {
        const isRange = this.value === 'range';
        document.querySelector("input[name='start_date']").disabled = !isRange;
        document.querySelector("input[name='end_date']").disabled = !isRange;
    });
    window.addEventListener('DOMContentLoaded', () => {
        document.getElementById('report_type').dispatchEvent(new Event('change'));

        <?php if (!empty($data)): ?>
        const ctx = document.getElementById('barChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($d) => $d['category'] . ' - ' . $d['subcategory'], $data)) ?>,
                datasets: [
                    {
                        label: 'Total Gastos',
                        data: <?= json_encode(array_column($data, 'total')) ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Gastos por Subcategoría' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function(value) { return '$' + value; } }
                    }
                }
            }
        });
        <?php endif; ?>
    });
</script>

<?php include 'footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<script>
$(function() {
    function configurarAutocomplete(selector, tipo) {
        $(selector).autocomplete({
            source: function(request, response) {
                if (request.term.length < 3) return;
                $.ajax({
                    url: 'autocomplete.php',
                    dataType: 'json',
                    data: { term: request.term, tipo: tipo },
                    success: function(data) {
                        response(data);
                    }
                });
            },
            minLength: 3
        });
    }

    configurarAutocomplete("input[name='category']", 'category');
    configurarAutocomplete("input[name='subcategory']", 'subcategory');
});
</script>
