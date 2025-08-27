<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = $_SESSION['company_id'];

// Obtener proyectos de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll();

$selectedProject = null;
$payments = [];
$expenses = [];
$fromDate = '';
$toDate = '';
$project_id = $_GET['project_id'] ?? '';

$fromDate = $_GET['from_date'] ?? '';
$toDate = $_GET['to_date'] ?? '';

$paymentsWhere = "WHERE 1";
$expensesWhere = "WHERE 1";
$paymentsParams = [];
$expensesParams = [];

if ($project_id) {
    $paymentsWhere .= " AND project_id = ?";
    $expensesWhere .= " AND project_id = ?";
    $paymentsParams[] = $project_id;
    $expensesParams[] = $project_id;

    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $selectedProject = $stmt->fetchColumn();
} else {
    $selectedProject = 'Todos los proyectos';
    $paymentsWhere .= " AND project_id IN (SELECT id FROM projects WHERE company_id = ?)";
    $expensesWhere .= " AND project_id IN (SELECT id FROM projects WHERE company_id = ?)";
    $paymentsParams[] = $company_id;
    $expensesParams[] = $company_id;
}

if ($fromDate && $toDate) {
    $paymentsWhere .= " AND payment_date BETWEEN ? AND ?";
    $expensesWhere .= " AND expense_date BETWEEN ? AND ?";
    $paymentsParams[] = $fromDate;
    $paymentsParams[] = $toDate;
    $expensesParams[] = $fromDate;
    $expensesParams[] = $toDate;
}

$stmt = $pdo->prepare("SELECT amount, payment_date, client, bank_account FROM payments $paymentsWhere ORDER BY payment_date");
$stmt->execute($paymentsParams);
$payments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT amount, expense_date, category, subcategory FROM expenses $expensesWhere ORDER BY expense_date");
$stmt->execute($expensesParams);
$expenses = $stmt->fetchAll();
?>

<h2 class="mb-4">📈 Flujo de Efectivo</h2>

<form method="GET" class="row g-3 mb-4">
    <div class="col-md-4">
        <label class="form-label">Proyecto</label>
        <select name="project_id" class="form-select">
            <option value="">Todos los proyectos</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?= $proj['id'] ?>" <?= $project_id == $proj['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($proj['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Desde</label>
        <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Hasta</label>
        <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
    </div>
    <div class="col-md-2 align-self-end">
        <button type="submit" class="btn btn-primary w-100">Filtrar</button>
    </div>
</form>

<?php if ($payments || $expenses): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">💼 Flujo de efectivo para: <strong><?= htmlspecialchars($selectedProject) ?></strong></h5>
        </div>
        <div class="card-body">
            <h6><strong>Ingresos</strong></h6>
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Cliente</th>
                        <th>Monto</th>
                        <th>Cuenta</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $totalIngresos = 0; ?>
                    <?php foreach ($payments as $p): ?>
                        <?php $totalIngresos += $p['amount']; ?>
                        <tr>
                            <td><?= $p['payment_date'] ?></td>
                            <td><?= htmlspecialchars($p['client']) ?></td>
                            <td>$<?= number_format($p['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($p['bank_account']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-success">
                        <td colspan="2" class="text-end"><strong>Total ingresos</strong></td>
                        <td colspan="2"><strong>$<?= number_format($totalIngresos, 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <h6 class="mt-4"><strong>Egresos</strong></h6>
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Categoría</th>
                        <th>Subcategoría</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $totalEgresos = 0; ?>
                    <?php foreach ($expenses as $e): ?>
                        <?php $totalEgresos += $e['amount']; ?>
                        <tr>
                            <td><?= $e['expense_date'] ?></td>
                            <td><?= htmlspecialchars($e['category']) ?></td>
                            <td><?= htmlspecialchars($e['subcategory']) ?></td>
                            <td>$<?= number_format($e['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-danger">
                        <td colspan="3" class="text-end"><strong>Total egresos</strong></td>
                        <td><strong>$<?= number_format($totalEgresos, 2) ?></strong></td>
                    </tr>
                    <tr class="table-dark">
                        <td colspan="3" class="text-end"><strong>Flujo Neto</strong></td>
                        <td><strong>$<?= number_format($totalIngresos - $totalEgresos, 2) ?></strong></td>
                    </tr>
                </tbody>
            </table>

            <canvas id="cashFlowChart" height="100"></canvas>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($payments || $expenses): ?>
const ctx = document.getElementById('cashFlowChart').getContext('2d');
const labels = [];
const ingresos = [];
const egresos = [];
<?php
    $fechas = [];
    foreach ($payments as $p) {
        $fecha = $p['payment_date'];
        $fechas[$fecha]['ingresos'] = ($fechas[$fecha]['ingresos'] ?? 0) + $p['amount'];
    }
    foreach ($expenses as $e) {
        $fecha = $e['expense_date'];
        $fechas[$fecha]['egresos'] = ($fechas[$fecha]['egresos'] ?? 0) + $e['amount'];
    }
    ksort($fechas);
    foreach ($fechas as $fecha => $valores): ?>
        labels.push("<?= $fecha ?>");
        ingresos.push(<?= $valores['ingresos'] ?? 0 ?>);
        egresos.push(<?= $valores['egresos'] ?? 0 ?>);
<?php endforeach; ?>

new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Ingresos',
                data: ingresos,
                borderColor: 'green',
                backgroundColor: 'rgba(0,128,0,0.1)',
                fill: true,
                tension: 0.3
            },
            {
                label: 'Egresos',
                data: egresos,
                borderColor: 'red',
                backgroundColor: 'rgba(255,0,0,0.1)',
                fill: true,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: 'Flujo de Efectivo (Ingresos vs Egresos)'
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
