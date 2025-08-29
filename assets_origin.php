<?php
// assets_origin.php — Reporte agrupado por CFDI de compra (inventory.cfdi_uuid)
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header('Location: choose_company.php');
    exit;
}
$company_id = (int)$_SESSION['company_id'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

/* Filtros */
$q_cfdi   = trim($_GET['q_cfdi'] ?? '');
$q_text   = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_in   = (int)($_GET['per_page'] ?? 25);
$per_page = in_array($per_in, [10,25,50,100], true) ? $per_in : 25;
$offset   = ($page - 1) * $per_page;

function qs_keep(array $extra=[], array $drop=['page']){
  $q = $_GET;
  foreach ($drop as $d) unset($q[$d]);
  $q = array_merge($q, $extra);
  return http_build_query($q);
}

/* ====== Conteo de grupos (DISTINCT cfdi_uuid) ====== */
$w = ["inv.company_id = ?", "inv.cfdi_uuid IS NOT NULL", "inv.cfdi_uuid <> ''"];
$args = [$company_id];

if ($q_cfdi !== '') { $w[] = "inv.cfdi_uuid LIKE ?"; $args[] = "%$q_cfdi%"; }
if ($q_text !== '') { 
    $w[] = "(inv.description LIKE ? OR inv.product_code LIKE ?)";
    $args[] = "%$q_text%"; 
    $args[] = "%$q_text%"; 
}
$where = 'WHERE '.implode(' AND ', $w);

$sql_count = "
  SELECT COUNT(DISTINCT inv.cfdi_uuid)
  FROM inventory inv
  $where
";
$stmtCnt = $pdo->prepare($sql_count);
$stmtCnt->execute($args);
$total_rows  = (int)$stmtCnt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

/* ====== Datos agrupados por cfdi_uuid ====== */
$sql = "
  SELECT
    inv.cfdi_uuid AS cfdi_uuid,
    COUNT(*) AS items_total,
    SUM(CASE WHEN inv.active = 0 THEN 1 ELSE 0 END) AS items_inactivos,
    SUM(CASE WHEN pi.id IS NOT NULL THEN 1 ELSE 0 END) AS items_presale,
    SUM(CASE WHEN si.id IS NOT NULL THEN 1 ELSE 0 END) AS items_sale,
    SUM(CASE WHEN inv.active = 1 AND si.id IS NULL THEN 1 ELSE 0 END) AS items_activos,
    MAX(inv.id) AS last_inv_id
  FROM inventory inv
  LEFT JOIN presale_items pi
         ON pi.inventory_id = inv.id
  LEFT JOIN sale_items si
         ON si.inventory_id = inv.id
  $where
  GROUP BY inv.cfdi_uuid
  ORDER BY last_inv_id DESC
  LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<style>
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Courier New", monospace; }
  .nowrap { white-space: nowrap; }
</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📦 Reporte de compras (CFDI) y movimientos</h2>
  <a href="inventory.php" class="btn btn-outline-secondary btn-sm ms-auto">Ir a Inventario</a>
</div>

<form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg-4">
        <label class="form-label">UUID de compra</label>
        <input type="text" name="q_cfdi" value="<?= e($q_cfdi) ?>" class="form-control" placeholder="cfdi_uuid">
      </div>
      <div class="col-12 col-lg-4">
        <label class="form-label">Buscar en descripción / código</label>
        <input type="text" name="q" value="<?= e($q_text) ?>" class="form-control" placeholder="Texto libre">
      </div>
      <div class="col-6 col-lg-2">
        <label class="form-label">Por pág.</label>
        <select name="per_page" class="form-select">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-lg-2 d-grid d-lg-flex gap-2 justify-content-lg-end">
        <button class="btn btn-outline-primary" type="submit">Filtrar</button>
        <a class="btn btn-outline-secondary" href="assets_origin.php">Limpiar</a>
      </div>
    </div>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>CFDI (UUID compra)</th>
          <th class="text-end">Activos</th>
          <th class="text-end">Entregados/Baja</th>
          <th class="text-end">Pre-ventas</th>
          <th class="text-end">Ventas</th>
          <th class="nowrap" style="width:140px">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No se encontraron CFDI en inventario.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="text-mono"><?= e($r['cfdi_uuid']) ?></td>
            <td class="text-end"><?= (int)$r['items_activos'] ?></td>
            <td class="text-end"><?= (int)$r['items_inactivos'] ?></td>
            <td class="text-end"><?= (int)$r['items_presale'] ?></td>
            <td class="text-end"><?= (int)$r['items_sale'] ?></td>
            <td>
              <a class="btn btn-outline-secondary btn-sm"
                 href="assets_origin_view.php?uuid=<?= urlencode($r['cfdi_uuid']) ?>">
                 Ver detalle
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center p-3 border-top">
    <div class="text-muted small">
      Mostrando
      <strong><?= ($total_rows===0)?0:($offset+1) ?></strong>–
      <strong><?= min($offset+$per_page, $total_rows) ?></strong>
      de <strong><?= $total_rows ?></strong>
    </div>
    <nav>
      <ul class="pagination pagination-sm mb-0">
        <?php
          $prev_disabled = ($page<=1) ? ' disabled' : '';
          $next_disabled = ($page>=$total_pages) ? ' disabled' : '';
        ?>
        <li class="page-item<?= $prev_disabled ?>">
          <a class="page-link" href="?<?= qs_keep(['page'=>max(1,$page-1)]) ?>">«</a>
        </li>
        <?php
          $start = max(1, $page-2);
          $end   = min($total_pages, $page+2);
          if ($start > 1) {
            echo '<li class="page-item"><a class="page-link" href="?'.qs_keep(['page'=>1]).'">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for ($p=$start; $p<=$end; $p++) {
            $active = ($p==$page) ? ' active' : '';
            echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.qs_keep(['page'=>$p]).'">'.$p.'</a></li>';
          }
          if ($end < $total_pages) {
            if ($end < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a class="page-link" href="?'.qs_keep(['page'=>$total_pages]).'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item<?= $next_disabled ?>">
          <a class="page-link" href="?<?= qs_keep(['page'=>min($total_pages,$page+1)]) ?>">»</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<?php include 'footer.php'; ?>
