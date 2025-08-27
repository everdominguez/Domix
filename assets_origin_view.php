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

/* Helpers detección columnas en invoices */
function column_exists(PDO $pdo, $table, $column) {
  static $cache = [];
  $key = "$table.$column";
  if (isset($cache[$key])) return $cache[$key];
  $stmt = $pdo->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
  ");
  $stmt->execute([$table, $column]);
  return $cache[$key] = (bool)$stmt->fetchColumn();
}
function invoices_cols(PDO $pdo) {
  $uuid = column_exists($pdo,'invoices','uuid') ? 'uuid' :
          (column_exists($pdo,'invoices','cfdi_uuid') ? 'cfdi_uuid' : null);
  $serie = column_exists($pdo,'invoices','serie') ? 'serie' : null;
  $folio = column_exists($pdo,'invoices','folio') ? 'folio' : null;
  return [$uuid,$serie,$folio];
}

$uuid = trim($_GET['uuid'] ?? '');
if ($uuid === '') {
  echo "<div class='alert alert-danger'>Falta el UUID de compra.</div>";
  include 'footer.php'; exit;
}

/* ====== Resumen de inventario para este UUID ====== */
$stmtInvSum = $pdo->prepare("
  SELECT
    COUNT(*) AS items_total,
    SUM(CASE WHEN inv.active = 0 THEN 1 ELSE 0 END) AS items_inactivos
  FROM inventory inv
  WHERE inv.company_id = ? AND inv.cfdi_uuid = ?
");
$stmtInvSum->execute([$company_id, $uuid]);
$invSum = $stmtInvSum->fetch(PDO::FETCH_ASSOC) ?: ['items_total'=>0,'items_inactivos'=>0];

$items_total   = (int)$invSum['items_total'];
$items_inact   = (int)$invSum['items_inactivos'];
$items_activos = $items_total - $items_inact;

/* ====== Activos (todas las filas de inventory con ese UUID) ====== */
$stmtInv = $pdo->prepare("
  SELECT inv.id, inv.product_code, inv.description, inv.active
  FROM inventory inv
  WHERE inv.company_id = ? AND inv.cfdi_uuid = ?
  ORDER BY inv.id ASC
");
$stmtInv->execute([$company_id, $uuid]);
$invRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

/* ====== Movimientos (presale_items que usan esos inventory) ====== */
list($inv_uuid_col,$inv_serie_col,$inv_folio_col) = invoices_cols($pdo);

$join_invc = ($inv_uuid_col || $inv_serie_col || $inv_folio_col) ? "
  LEFT JOIN invoices invc
         ON invc.company_id = p.company_id
        AND invc.id = p.sale_id
" : "";

$select_invc = [];
if ($inv_uuid_col)  $select_invc[] = "invc.$inv_uuid_col AS sale_uuid";
if ($inv_serie_col) $select_invc[] = "invc.$inv_serie_col AS sale_serie";
if ($inv_folio_col) $select_invc[] = "invc.$inv_folio_col AS sale_folio";
if (!$select_invc)  $select_invc[] = "NULL AS sale_uuid";

$sqlMovs = "
  SELECT
    pi.id           AS presale_item_id,
    pi.presale_id   AS presale_id,
    pi.quantity     AS qty,
    pi.unit_price   AS unit_price,
    pi.total        AS total,
    pi.reference    AS item_ref,

    p.title         AS presale_title,
    p.status        AS presale_status,
    p.sale_id       AS sale_id,

    inv.id          AS inventory_id,
    inv.product_code,
    inv.description AS inv_desc,
    inv.active      AS inv_active,

    ".implode(",\n    ", $select_invc)."

  FROM presale_items pi
  JOIN inventory inv
    ON inv.company_id = pi.company_id
   AND inv.id = pi.inventory_id
  JOIN presales p
    ON p.company_id = pi.company_id
   AND p.id = pi.presale_id
  $join_invc
  WHERE pi.company_id = ?
    AND inv.cfdi_uuid = ?
  ORDER BY p.id DESC, pi.id ASC
";
$stm = $pdo->prepare($sqlMovs);
$stm->execute([$company_id, $uuid]);
$movs = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ====== Resumen de vínculos ====== */
$presales_ids = [];
$sales_ids    = [];
foreach ($movs as $m) {
  if (!empty($m['presale_id'])) $presales_ids[$m['presale_id']] = true;
  if (!empty($m['sale_id']))    $sales_ids[$m['sale_id']] = true;
}
$presales_cnt = count($presales_ids);
$sales_cnt    = count($sales_ids);

?>
<style>
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }
  .nowrap { white-space: nowrap; }
  .badge-light { background:#f8f9fa; color:#212529; }
</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📄 CFDI de compra</h2>
  <span class="text-mono badge badge-light border"><?= e($uuid) ?></span>
  <a href="assets_origin.php" class="btn btn-outline-secondary btn-sm ms-auto">← Volver al listado</a>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <h5 class="card-title">Activos (sin descontar)</h5>
        <div class="mb-3">
          <span class="me-3"><strong>Total:</strong> <?= (int)$items_total ?></span>
          <span class="me-3"><strong>Activos:</strong> <?= (int)$items_activos ?></span>
          <span class="me-3"><strong>Entregados/Baja:</strong> <?= (int)$items_inact ?></span>
        </div>
        <div class="table-responsive" style="max-height: 360px;">
          <table class="table table-sm table-striped mb-0">
            <thead class="table-light">
              <tr>
                <th class="nowrap">Inv #</th>
                <th>Código</th>
                <th>Descripción</th>
                <th class="nowrap">Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$invRows): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">Sin activos ligados a este UUID.</td></tr>
              <?php else: foreach ($invRows as $r): ?>
                <tr>
                  <td class="text-mono"><?= (int)$r['id'] ?></td>
                  <td class="text-mono"><?= e($r['product_code'] ?? '') ?></td>
                  <td><?= e($r['description'] ?? '') ?></td>
                  <td>
                    <?php if ((int)$r['active'] === 0): ?>
                      <span class="badge bg-light text-dark">entregado/baja</span>
                    <?php else: ?>
                      <span class="badge bg-success">activo</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2">
          <h5 class="card-title mb-0">Movimientos (pre-ventas / ventas)</h5>
          <span class="ms-auto small text-muted">Pre-ventas: <strong><?= $presales_cnt ?></strong> &nbsp;|&nbsp; Ventas: <strong><?= $sales_cnt ?></strong></span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th class="nowrap">Pre-venta</th>
                <th>Venta</th>
                <th class="text-end nowrap">Cant.</th>
                <th class="text-end nowrap">P.U.</th>
                <th class="text-end nowrap">Total</th>
                <th>Inv #/Código</th>
                <th>Ref.</th>
                <th class="nowrap" style="width:120px">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$movs): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Este CFDI aún no tiene movimientos asociados.</td></tr>
              <?php else: foreach ($movs as $m):
                $ventaLabel = '—';
                if (!empty($m['sale_id'])) {
                  $uu = $m['sale_uuid'] ?? '';
                  $sf = '';
                  if (isset($m['sale_serie']) || isset($m['sale_folio'])) {
                    $serie = trim((string)($m['sale_serie'] ?? ''));
                    $folio = trim((string)($m['sale_folio'] ?? ''));
                    $sf = trim($serie . (($serie!=='' && $folio!=='') ? '-' : '') . $folio);
                  }
                  if ($uu!=='' || $sf!=='') {
                    $ventaLabel = '<span class="text-mono">'.e($uu ?: $sf).'</span>';
                  } else {
                    $ventaLabel = '#'.(int)$m['sale_id'];
                  }
                }
              ?>
                <tr>
                  <td>
                    <span class="text-mono">#<?= (int)$m['presale_id'] ?></span>
                    <div class="small text-muted"><?= e($m['presale_title'] ?? '') ?></div>
                    <div><?= e($m['presale_status'] ?? '') ?></div>
                  </td>
                  <td><?= $ventaLabel ?></td>
                  <td class="text-end"><?= n($m['qty']) ?></td>
                  <td class="text-end"><?= n($m['unit_price']) ?></td>
                  <td class="text-end"><?= n($m['total']) ?></td>
                  <td>
                    <div class="text-mono">#<?= (int)$m['inventory_id'] ?></div>
                    <div class="text-mono small"><?= e($m['product_code'] ?? '') ?></div>
                  </td>
                  <td><?= e($m['item_ref'] ?? '') ?></td>
                  <td class="nowrap">
                    <a class="btn btn-outline-secondary btn-sm"
                       href="presale_view.php?id=<?= (int)$m['presale_id'] ?>">Ver pre-venta</a>
                  </td>
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
