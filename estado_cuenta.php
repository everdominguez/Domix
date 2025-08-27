<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

$company_id = $_SESSION['company_id'] ?? null;
if (!$company_id) {
    echo "<div class='alert alert-danger'>No has seleccionado empresa.</div>";
    include 'footer.php';
    exit;
}

// Obtener formas de pago de la empresa
$stmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ?");
$stmt->execute([$company_id]);
$formas_pago = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tipo_movimiento = $_GET['tipo'] ?? 'todos';
$forma_pago = $_GET['forma_pago'] ?? '';
$periodo = $_GET['periodo'] ?? 'actual';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

$fecha_inicio = '';
$fecha_fin = '';
$fecha_saldo_inicial = '';

if ($periodo === 'actual') {
    $fecha_inicio = date('Y-m-01');
    $fecha_fin = date('Y-m-t');
} elseif ($periodo === 'anterior') {
    $fecha_inicio = date('Y-m-01', strtotime('first day of last month'));
    $fecha_fin = date('Y-m-t', strtotime('last day of last month'));
} elseif ($periodo === 'rango' && $desde && $hasta) {
    $fecha_inicio = $desde;
    $fecha_fin = $hasta;
}

if ($fecha_inicio) {
    $fecha_saldo_inicial = date('Y-m-d', strtotime($fecha_inicio . ' -1 day'));
}

$saldo_inicial = 0;

if (!empty($forma_pago) && $fecha_saldo_inicial) {
    $stmtSaldo = $pdo->prepare("
        SELECT SUM(CASE WHEN source_type = 2 THEN amount ELSE -amount END) AS saldo 
        FROM payments 
        WHERE company_id = ? AND payment_method_id = ? AND payment_date <= ?
    ");
    $stmtSaldo->execute([$company_id, $forma_pago, $fecha_saldo_inicial]);
    $saldo_inicial = $stmtSaldo->fetchColumn() ?: 0;
}


$resultados = [];

if (!empty($forma_pago)) {
    $query = "SELECT * FROM payments WHERE company_id = ? AND payment_method_id = ?";
    $params = [$company_id, $forma_pago];

    if ($fecha_inicio && $fecha_fin) {
        $query .= " AND payment_date BETWEEN ? AND ?";
        $params[] = $fecha_inicio;
        $params[] = $fecha_fin;
    }

    if ($tipo_movimiento === 'gasto') {
        $query .= " AND source_type = 1";
    } elseif ($tipo_movimiento === 'ingreso') {
        $query .= " AND source_type = 2";
    }

    $query .= " ORDER BY payment_date ASC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container mt-4">
  <h4>📒 Estado de Cuenta - Filtros</h4>
  <form method="GET" class="row g-3">
    <div class="col-md-2">
      <label class="form-label">Tipo de Movimiento</label>
      <select name="tipo" class="form-select">
        <option value="todos" <?= $tipo_movimiento === 'todos' ? 'selected' : '' ?>>Todos</option>
        <option value="gasto" <?= $tipo_movimiento === 'gasto' ? 'selected' : '' ?>>Gastos</option>
        <option value="ingreso" <?= $tipo_movimiento === 'ingreso' ? 'selected' : '' ?>>Ingresos</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Forma de Pago</label>
      <select name="forma_pago" class="form-select" required>
        <option value="">Todas</option>
        <?php foreach ($formas_pago as $fp): ?>
          <option value="<?= $fp['id'] ?>" <?= $forma_pago == $fp['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($fp['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Período</label>
      <select name="periodo" id="periodo" class="form-select" onchange="toggleFechas()">
        <option value="actual" <?= $periodo === 'actual' ? 'selected' : '' ?>>Mes actual</option>
        <option value="anterior" <?= $periodo === 'anterior' ? 'selected' : '' ?>>Mes anterior</option>
        <option value="rango" <?= $periodo === 'rango' ? 'selected' : '' ?>>Rangos de fechas</option>
      </select>
    </div>
    <div class="col-md-2" id="rango-desde" style="display: none;">
      <label class="form-label">Desde</label>
      <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>" class="form-control">
    </div>
    <div class="col-md-2" id="rango-hasta" style="display: none;">
      <label class="form-label">Hasta</label>
      <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>" class="form-control">
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <button class="btn btn-primary">🔍 Filtrar</button>
    </div>
  </form>

  <?php if ($forma_pago): ?>
    <hr>
    <h5>📊 Resultados</h5>
    <table class="table table-sm table-bordered table-striped">
      <thead class="table-light">
        <tr>
          <th>📅 Fecha</th>
          <th>🔁 Tipo</th>
          <th>🧾 Folio</th>
          <th>🆔 UUID</th>
          <th>📝 Notas</th>
          <th>🏦 Forma de Pago</th>
          <th>💰 Monto</th>
          <th>📈 Saldo Acumulado</th>
        </tr>
      </thead>
      <tbody>
        <?php
          $acumulado = $saldo_inicial;
          foreach ($resultados as $r):
              $tipo = ($r['source_type'] == 1) ? 'Gasto' : 'Ingreso';
              $monto = $r['amount']; // usar el valor tal cual viene
              $acumulado += $monto;
              $forma = array_filter($formas_pago, fn($f) => $f['id'] == $r['payment_method_id']);
              $forma_nombre = $forma ? reset($forma)['name'] : 'N/A';
        ?>
        <tr>
          <td><?= htmlspecialchars($r['payment_date']) ?></td>
          <td><?= $tipo ?></td>
          <td><?= htmlspecialchars($r['id']) ?></td>
          <td><?= htmlspecialchars($r['related_cfdi_uuid'] ?? '') ?></td>
          <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
          <td><?= htmlspecialchars($forma_nombre) ?></td>
          <td style="color: <?= $monto < 0 ? 'red' : 'inherit' ?>;">
  $<?= number_format($monto, 2) ?>
</td>

          <td><?= number_format($acumulado, 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="alert alert-info mt-4">Por favor selecciona una forma de pago para ver resultados.</div>
  <?php endif; ?>
</div>

<script>
function toggleFechas() {
  const periodo = document.getElementById("periodo").value;
  document.getElementById("rango-desde").style.display = periodo === "rango" ? "block" : "none";
  document.getElementById("rango-hasta").style.display = periodo === "rango" ? "block" : "none";
}
window.onload = toggleFechas;
</script>

<?php include 'footer.php'; ?>
