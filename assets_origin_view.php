<?php
// assets_origin_view.php — Detalle de un CFDI de compra (inventory.cfdi_uuid)
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header('Location: login.php');
    exit();
}
$company_id = (int)$_SESSION['company_id'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

include 'header.php';

$uuid = trim($_GET['uuid'] ?? '');
if ($uuid === '') {
  echo "<div class='alert alert-danger'>Falta el UUID de compra.</div>";
  include 'footer.php'; exit;
}

/* ====== Proveedor del CFDI ====== */
$stmtProv = $pdo->prepare("
  SELECT provider_name, provider_rfc, invoice_date
  FROM inventory
  WHERE company_id = ? AND cfdi_uuid = ?
  LIMIT 1
");
$stmtProv->execute([$company_id, $uuid]);
$prov = $stmtProv->fetch(PDO::FETCH_ASSOC);
$prov_name = $prov['provider_name'] ?? '—';
$prov_rfc  = $prov['provider_rfc'] ?? '—';
$prov_date = $prov['invoice_date'] ?? null;

/* ====== Resumen de inventario ====== */
$stmtInvSum = $pdo->prepare("
  SELECT
    COUNT(*) AS items_total,
    SUM(CASE WHEN inv.active = 0 THEN 1 ELSE 0 END) AS items_inactivos,
    SUM(CASE WHEN inv.active = 1 AND si.id IS NULL THEN 1 ELSE 0 END) AS items_activos,
    SUM(CASE WHEN si.id IS NOT NULL THEN 1 ELSE 0 END) AS items_vendidos
  FROM inventory inv
  LEFT JOIN sale_items si ON si.inventory_id = inv.id
  WHERE inv.company_id = ? AND inv.cfdi_uuid = ?
");
$stmtInvSum->execute([$company_id, $uuid]);
$invSum = $stmtInvSum->fetch(PDO::FETCH_ASSOC) ?: [
  'items_total'=>0,'items_inactivos'=>0,'items_activos'=>0,'items_vendidos'=>0
];

$items_total    = (int)$invSum['items_total'];
$items_inact    = (int)$invSum['items_inactivos'];
$items_activos  = (int)$invSum['items_activos'];
$items_vendidos = (int)$invSum['items_vendidos'];

/* ====== Listado de partidas de inventario ====== */
$stmtInv = $pdo->prepare("
  SELECT inv.id, inv.product_code, inv.description, inv.active, inv.quantity
  FROM inventory inv
  WHERE inv.company_id = ? AND inv.cfdi_uuid = ?
  ORDER BY inv.id ASC
");
$stmtInv->execute([$company_id, $uuid]);
$invRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

/* ====== Movimientos: Pre-ventas ====== */
$sqlPresales = "
  SELECT 
    pi.id AS presale_item_id,
    pi.presale_id,
    pi.quantity,
    pi.unit_price,
    pi.total,
    pi.reference,
    p.title AS presale_title,
    p.status AS presale_status,
    inv.product_code,
    inv.description
  FROM presale_items pi
  JOIN presales p   ON p.id = pi.presale_id
  JOIN inventory inv ON inv.id = pi.inventory_id
  WHERE pi.company_id = ? AND inv.cfdi_uuid = ?
  ORDER BY pi.id ASC
";
$stm = $pdo->prepare($sqlPresales);
$stm->execute([$company_id, $uuid]);
$presales = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ====== Movimientos: Ventas ====== */
$sqlSales = "
  SELECT 
    si.id AS sale_item_id,
    s.id AS sale_id,
    s.sale_date,
    s.folio_fiscal,
    si.quantity,
    si.unit_price,
    (si.quantity*si.unit_price) AS total,
    inv.product_code,
    inv.description
  FROM sale_items si
  JOIN sales s     ON s.id = si.sale_id
  JOIN inventory inv ON inv.id = si.inventory_id
  WHERE inv.cfdi_uuid = ?
  ORDER BY si.id ASC
";
$stm2 = $pdo->prepare($sqlSales);
$stm2->execute([$uuid]);
$sales = $stm2->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }
  .nowrap { white-space: nowrap; }
  .badge-light { background:#f8f9fa; color:#212529; }
</style>

<div class="d-flex align-items-center mb-3">
  <div>
    <h2 class="mb-0">📄 CFDI de compra</h2>
    <div class="small text-muted">
      <strong>Proveedor:</strong> <?= e($prov_name) ?> (<?= e($prov_rfc) ?>)
    </div>
    <?php if ($prov_date): ?>
      <div class="small text-muted">
        <strong>Fecha CFDI:</strong> <?= e($prov_date) ?>
      </div>
    <?php endif; ?>
    <div class="text-mono small"><?= e($uuid) ?></div>
  </div>
  <a href="assets_origin.php" class="btn btn-outline-secondary btn-sm ms-auto">← Volver al listado</a>
</div>

<div class="row g-3">
  <!-- Columna izquierda: Inventario -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Inventario</h5>
        <div class="mb-3">
          <span class="me-3"><strong>Total:</strong> <?= $items_total ?></span>
          <span class="me-3"><strong>Activos:</strong> <?= $items_activos ?></span>
          <span class="me-3"><strong>Vendidos:</strong> <?= $items_vendidos ?></span>
          <span class="me-3"><strong>Entregados/Baja:</strong> <?= $items_inact ?></span>
        </div>
        <div class="table-responsive" style="max-height: 360px;">
          <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th>Inv #</th><th>Código</th><th>Descripción</th><th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$invRows): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Sin partidas para este UUID.</td></tr>
              <?php else: foreach ($invRows as $r): ?>
                <tr>
                  <td class="text-mono"><?= (int)$r['id'] ?></td>
                  <td class="text-mono"><?= e($r['product_code'] ?? '') ?></td>
                  <td><?= e($r['description'] ?? '') ?></td>
                  <td>
                    <?php
                      $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE inventory_id = ?");
                      $stmtChk->execute([$r['id']]);
                      $vendido = (int)$stmtChk->fetchColumn() > 0;

                      if ((int)$r['active'] === 0) {
                          echo '<span class="badge bg-secondary">baja</span>';
                      } elseif ($vendido) {
                          echo '<span class="badge bg-warning text-dark">vendido</span>';
                      } else {
                          echo '<span class="badge bg-success">activo</span>';
                      }
                    ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Columna derecha: Movimientos -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Movimientos</h5>

        <!-- Pre-ventas -->
        <h6 class="mt-3">Pre-ventas (<?= count($presales) ?>)</h6>
        <div class="table-responsive mb-3">
          <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th>Pre-venta</th><th>Código</th><th>Descripción</th>
                <th class="text-end">Cant.</th><th class="text-end">P.U.</th><th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$presales): ?>
                <tr><td colspan="6" class="text-center text-muted py-2">Sin movimientos en pre-ventas</td></tr>
              <?php else: foreach ($presales as $p): ?>
                <tr>
                  <td>#<?= (int)$p['presale_id'] ?> <?= e($p['presale_title']) ?></td>
                  <td class="text-mono"><?= e($p['product_code']) ?></td>
                  <td><?= e($p['description']) ?></td>
                  <td class="text-end"><?= n($p['quantity']) ?></td>
                  <td class="text-end"><?= n($p['unit_price']) ?></td>
                  <td class="text-end"><?= n($p['total']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Ventas -->
        <h6 class="mt-3">Ventas (<?= count($sales) ?>)</h6>
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th>Venta</th><th>Fecha</th><th>Código</th><th>Descripción</th>
                <th class="text-end">Cant.</th><th class="text-end">P.U.</th><th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$sales): ?>
                <tr><td colspan="7" class="text-center text-muted py-2">Sin movimientos en ventas</td></tr>
              <?php else: foreach ($sales as $s): ?>
                <tr>
                  <td>
                    <a href="sales_view.php?sale_id=<?= (int)$s['sale_id'] ?>" class="text-mono">
                      #<?= (int)$s['sale_id'] ?> (<?= e($s['folio_fiscal']) ?>)
                    </a>
                  </td>
                  <td><?= e($s['sale_date']) ?></td>
                  <td class="text-mono"><?= e($s['product_code']) ?></td>
                  <td><?= e($s['description']) ?></td>
                  <td class="text-end"><?= n($s['quantity']) ?></td>
                  <td class="text-end"><?= n($s['unit_price']) ?></td>
                  <td class="text-end"><?= n($s['total']) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
