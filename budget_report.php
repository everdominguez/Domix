<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Obtener todos los proyectos
$stmt = $pdo->query("SELECT id, name, start_date FROM projects ORDER BY name");
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$selectedProject = null;
$start_date = null;
$end_date = null;
$reportType = 'all';
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['project_id'])) {
    $project_id = $_GET['project_id'];
    $reportType = $_GET['report_type'] ?? 'all';
    $selectedProject = array_filter($projects, fn($p) => $p['id'] == $project_id);
    $selectedProject = reset($selectedProject);

    if ($reportType === 'range') {
        $start_date = $_GET['start_date'] ?? null;
        $end_date = $_GET['end_date'] ?? null;
    } else {
        $start_date = $selectedProject['start_date'];
        $end_date = date('Y-m-d');
    }

    // Obtener presupuesto
    $stmt = $pdo->prepare("SELECT category, subcategory, SUM(amount) AS total FROM budgets WHERE project_id = ? GROUP BY category, subcategory");
    $stmt->execute([$project_id]);
    $budgets = $stmt->fetchAll();

    // Obtener gastos en el rango definido
    $stmt = $pdo->prepare("SELECT category, subcategory, SUM(amount) AS total FROM expenses WHERE project_id = ? AND expense_date BETWEEN ? AND ? GROUP BY category, subcategory");
    $stmt->execute([$project_id, $start_date, $end_date]);
    $expenses = $stmt->fetchAll();

    // Reorganizar gastos por clave única
    $expensesMap = [];
    foreach ($expenses as $e) {
        $key = $e['category'] . '||' . $e['subcategory'];
        $expensesMap[$key] = $e['total'];
    }

    // Combinar datos
    foreach ($budgets as $b) {
        $key = $b['category'] . '||' . $b['subcategory'];
        $data[] = [
            'category' => $b['category'],
            'subcategory' => $b['subcategory'],
            'budget' => $b['total'],
            'expense' => $expensesMap[$key] ?? 0,
        ];
    }
}
?>

<h2 class="mb-4">📈 Reporte de Presupuesto vs Gastos</h2>

<div class="card shadow mb-4">
    <div class="card-header">Filtros del Reporte</div>
    <div class="card-body">
        <form method="GET" action="budget_report.php">
            <div class="row mb-3">
                <div class="col-md-4">
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
                <div class="col-md-4">
                    <label class="form-label">Tipo de Reporte</label>
                    <select name="report_type" id="report_type" class="form-select">
                        <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>Desde inicio del proyecto</option>
                        <option value="range" <?= $reportType === 'range' ? 'selected' : '' ?>>Rango de fechas</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date ?? '') ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Generar Reporte</button>
        </form>
    </div>
</div>

<?php if (!empty($data)): ?>
<div class="table-responsive">
    <table class="table table-bordered table-hover shadow-sm">
        <thead class="table-dark">
            <tr>
                <th>Categoría</th>
                <th>Subcategoría</th>
                <th>Presupuesto</th>
                <th>Gasto</th>
                <th>Diferencia</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['category']) ?></td>
                    <td><?= htmlspecialchars($row['subcategory']) ?></td>
                    <td>$<?= number_format($row['budget'], 2) ?></td>
                    <td>$<?= number_format($row['expense'], 2) ?></td>
                    <td class="fw-bold <?= $row['expense'] > $row['budget'] ? 'text-danger' : 'text-success' ?>">
                        $<?= number_format($row['budget'] - $row['expense'], 2) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<canvas id="barChart" height="100"></canvas>
<?php elseif ($selectedProject): ?>
    <p class="text-muted">No se encontraron datos de presupuesto o gastos para este proyecto en el periodo seleccionado.</p>
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
                        label: 'Presupuesto',
                        data: <?= json_encode(array_column($data, 'budget')) ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.7)'
                    },
                    {
                        label: 'Gasto',
                        data: <?= json_encode(array_column($data, 'expense')) ?>,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)'
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Presupuesto vs Gastos por Subcategoría' }
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
