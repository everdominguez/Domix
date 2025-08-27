<?php
// cfdi_details.php
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['company_id'])) {
    http_response_code(401);
    echo '<div class="text-danger">Sesión no iniciada.</div>';
    exit;
}
$company_id = (int)$_SESSION['company_id'];

$uuid = trim($_GET['uuid'] ?? '');
if ($uuid === '') {
    echo '<div class="text-danger">UUID no proporcionado.</div>';
    exit;
}

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

// === 1) Encabezado del gasto (si existe) ===
$e = null;
try {
    $stmt = $pdo->prepare("
        SELECT e.*, p.name AS project_name
        FROM expenses e
        LEFT JOIN projects p ON p.id = e.project_id
        WHERE e.company_id = ? AND e.cfdi_uuid = ?
        LIMIT 1
    ");
    $stmt->execute([$company_id, $uuid]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $th) { /* noop */ }

// === 1b) Fallback: proveedor desde inventario ===
$fallbackProv = null;
if (!$e) {
    try {
        $q = $pdo->prepare("
            SELECT provider_name, provider_rfc
            FROM inventory
            WHERE company_id = ? AND cfdi_uuid = ? AND provider_name IS NOT NULL
            LIMIT 1
        ");
        $q->execute([$company_id, $uuid]);
        $fallbackProv = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $th) { /* noop */ }
}

// === 2) Partidas desde inventory (todas las que comparten el UUID) ===
$items = [];
try {
    $stmt2 = $pdo->prepare("
        SELECT i.*, pr.name AS project_name
        FROM inventory i
        LEFT JOIN projects pr ON pr.id = i.project_id
        WHERE i.company_id = ? AND i.cfdi_uuid = ?
        ORDER BY i.id ASC
    ");
    $stmt2->execute([$company_id, $uuid]);
    $items = $stmt2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $th) { /* noop */ }

// === 3) Calcular totales de items ===
$sum_sub = 0; $sum_vat = 0; $sum_total = 0;
foreach ($items as $it) {
    $sum_sub   += (float)$it['amount'];
    $sum_vat   += (float)$it['vat'];
    $sum_total += (float)$it['total'];
}
?>

<?php if ($e): ?>
  <div class="mb-3">
    <div class="d-flex justify-content-between">
      <div>
        <h6 class="mb-1">Encabezado</h6>
        <div class="text-muted small">
          Proyecto: <strong><?= e($e['project_name'] ?? '') ?></strong>
          <?php if (!empty($e['subproject_id'])): ?>
            · Subproyecto ID: <?= (int)$e['subproject_id'] ?>
          <?php endif; ?>
          · Fecha: <?= e($e['expense_date'] ?? '') ?>
          · Serie/Folio: <?= e(trim(($e['serie'] ?? '').($e['folio'] ?? ''))) ?>
        </div>
        <div class="text-muted small">
          Proveedor: <strong><?= e($e['provider_name'] ?? $e['provider'] ?? '') ?></strong>
          <?php if (!empty($e['provider_rfc'])): ?> · RFC: <?= e($e['provider_rfc']) ?><?php endif; ?>
          · Monto: $<?= n($e['amount'] ?? 0) ?>
        </div>
      </div>
      <div class="text-end small">
        <div><span class="text-muted">UUID:</span> <span class="text-monospace"><?= e($uuid) ?></span></div>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-warning py-2 mb-2">
    No se encontró encabezado en <code>expenses</code> para este UUID. Mostrando partidas desde inventario.
  </div>
  <div class="mb-2 small">
    <strong>UUID:</strong> <span class="text-monospace"><?= e($uuid) ?></span><br>
    <strong>Proveedor:</strong>
    <?php
      $pname = $fallbackProv['provider_name'] ?? null;
      $prfc  = $fallbackProv['provider_rfc']  ?? null;
      echo $pname ? e($pname) : '—';
      echo $prfc ? ' ('.e($prfc).')' : '';
    ?>
  </div>
<?php endif; ?>

<div class="table-responsive">
  <table class="table table-sm table-striped align-middle mb-0">
    <thead class="table-light">
      <tr>
        <th class="text-nowrap">Código</th>
        <th>Descripción</th>
        <th class="text-end text-nowrap">Cantidad</th>
        <th class="text-end text-nowrap">P. Unitario</th>
        <th class="text-end text-nowrap">Subtotal</th>
        <th class="text-end text-nowrap">IVA</th>
        <th class="text-end text-nowrap">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No hay partidas en inventario para este UUID.</td></tr>
      <?php else: ?>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="text-monospace"><?= e($it['product_code'] ?? '') ?></td>
            <td><?= e($it['description'] ?? '') ?></td>
            <td class="text-end"><?= n($it['quantity'] ?? 0) ?></td>
            <td class="text-end">$<?= n($it['unit_price'] ?? 0) ?></td>
            <td class="text-end">$<?= n($it['amount'] ?? 0) ?></td>
            <td class="text-end">$<?= n($it['vat'] ?? 0) ?></td>
            <td class="text-end">$<?= n($it['total'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <tfoot class="table-light">
      <tr>
        <th colspan="4" class="text-end">Totales</th>
        <th class="text-end">$<?= n($sum_sub) ?></th>
        <th class="text-end">$<?= n($sum_vat) ?></th>
        <th class="text-end">$<?= n($sum_total) ?></th>
      </tr>
    </tfoot>
  </table>
</div>
