<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$company_id = $_SESSION['company_id'] ?? null;
if (!$company_id) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit;
}

// Filtros
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_account = $_GET['selected_account'] ?? ''; // Nuevo filtro para cuenta seleccionada

// Obtener las cuentas bancarias de la empresa usando 'account' en expenses
$stmt = $pdo->prepare("SELECT DISTINCT account FROM expenses WHERE company_id = ? ORDER BY account ASC");
$stmt->execute([$company_id]);
$bank_accounts = $stmt->fetchAll();

// Ingresos por cuenta bancaria usando 'bank_account' en payments
$query_ingresos = "SELECT bank_account, amount, payment_date, description FROM payments WHERE company_id = ? AND payment_date BETWEEN ? AND ?";
$params_ingresos = [$company_id, $start_date, $end_date];
if ($selected_account) {
    $query_ingresos .= " AND bank_account = ?";
    $params_ingresos[] = $selected_account;
}
$stmt = $pdo->prepare($query_ingresos);
$stmt->execute($params_ingresos);
$ingresos = $stmt->fetchAll();

// Egresos por cuenta bancaria usando 'account' en expenses
$query_egresos = "SELECT account, amount, expense_date, notes FROM expenses WHERE company_id = ? AND expense_date BETWEEN ? AND ?";
$params_egresos = [$company_id, $start_date, $end_date];
if ($selected_account) {
    $query_egresos .= " AND account = ?";
    $params_egresos[] = $selected_account;
}
$stmt = $pdo->prepare($query_egresos);
$stmt->execute($params_egresos);
$egresos = $stmt->fetchAll();

// Organizar movimientos por cuenta bancaria
$movimientos = [];
foreach ($ingresos as $ing) {
    $movimientos[] = [
        'fecha' => $ing['payment_date'],
        'concepto' => $ing['description'],
        'deposito' => $ing['amount'],
        'retiro' => 0,
        'cuenta' => $ing['bank_account']
    ];
}

foreach ($egresos as $eg) {
    $movimientos[] = [
        'fecha' => $eg['expense_date'],
        'concepto' => $eg['notes'],
        'deposito' => 0,
        'retiro' => $eg['amount'],
        'cuenta' => $eg['account']
    ];
}

// Ordenar por fecha
usort($movimientos, function($a, $b) {
    return strtotime($a['fecha']) <=> strtotime($b['fecha']);
});

// Calcular saldo acumulado por cuenta bancaria
$saldo_por_cuenta = [];
foreach ($movimientos as $m) {
    if (!isset($saldo_por_cuenta[$m['cuenta']])) {
        $saldo_por_cuenta[$m['cuenta']] = 0;
    }
    $saldo_por_cuenta[$m['cuenta']] += $m['deposito'] - $m['retiro'];
}

?>

<div class="container py-4">
    <h2 class="fw-bold mb-4">💰 Balance Bancario</h2>

    <form method="GET" class="row g-3 mb-4">
        <div class="col-md-3">
            <label for="start_date" class="form-label">Desde</label>
            <input type="date" name="start_date" value="<?= $start_date ?>" class="form-control">
        </div>
        <div class="col-md-3">
            <label for="end_date" class="form-label">Hasta</label>
            <input type="date" name="end_date" value="<?= $end_date ?>" class="form-control">
        </div>
        <div class="col-md-3">
            <label for="selected_account" class="form-label">Cuenta Bancaria</label>
            <select name="selected_account" id="selected_account" class="form-select">
                <option value="">-- Todas las cuentas --</option>
                <?php foreach ($bank_accounts as $account): ?>
                    <option value="<?= htmlspecialchars($account['account']) ?>" <?= $account['account'] == $selected_account ? 'selected' : '' ?>>
                        <?= htmlspecialchars($account['account']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>

    <h4 class="mt-4">📑 Movimientos por Cuenta Bancaria</h4>
    <div class="table-responsive mb-5">
        <table class="table table-striped table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>Cuenta Bancaria</th>
                    <th>Fecha</th>
                    <th>Concepto</th>
                    <th class="text-end">Depósitos</th>
                    <th class="text-end">Retiros</th>
                    <th class="text-end">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movimientos as $m): ?>
                    <tr>
                        <td><?= htmlspecialchars($m['cuenta']) ?></td>
                        <td><?= $m['fecha'] ?></td>
                        <td><?= htmlspecialchars($m['concepto']) ?></td>
                        <td class="text-end"><?= $m['deposito'] ? '$' . number_format($m['deposito'], 2) : '' ?></td>
                        <td class="text-end"><?= $m['retiro'] ? '$' . number_format($m['retiro'], 2) : '' ?></td>
                        <td class="text-end fw-bold"><?= '$' . number_format($saldo_por_cuenta[$m['cuenta']], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($movimientos)): ?>
                    <tr><td colspan="6" class="text-center">No hay movimientos en el periodo seleccionado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'footer.php'; ?>
