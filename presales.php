<?php
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

/* ====== Filtros y paginación ====== */
$q        = trim($_GET['q'] ?? '');           // Título o cliente
$q_item   = trim($_GET['q_item'] ?? '');      // Concepto a nivel partida (description/reference)
$q_cfdi   = trim($_GET['q_cfdi'] ?? '');      // UUID/Serie-Folio (factura de venta) o UUID compra (inventario)
$status   = trim($_GET['status'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_in   = (int)($_GET['per_page'] ?? 25);
$per_page = in_array($per_in, [10,25,50,100], true) ? $per_in : 25;
$offset   = ($page - 1) * $per_page;

/* ====== Helper QS ====== */
function qs_keep(array $extra=[], array $drop=['page']){
  $q = $_GET;
  foreach ($drop as $d) unset($q[$d]);
  $q = array_merge($q, $extra);
  return http_build_query($q);
}

/* ====== Badges ====== */
function badgeStatus($st){
  switch ($st) {
    case 'draft':     return '<span class="badge bg-secondary">Borrador</span>';
    case 'sent':      return '<span class="badge bg-info">Enviada</span>';
    case 'won':       return '<span class="badge bg-success">Ganada</span>';
    case 'lost':      return '<span class="badge bg-danger">Perdida</span>';
    case 'cancelled': return '<span class="badge bg-dark">Cancelada</span>';
    case 'expired':   return '<span class="badge bg-warning text-dark">Vencida</span>';
    default:          return '<span class="badge bg-light text-dark">'.e($st ?: '—').'</span>';
  }
}

/* ===== Helpers de esquema seguros ===== */
function column_exists(PDO $pdo, $table, $column) {
  static $cache = [];
  $key = "$table.$column";
  if (isset($cache[$key])) return $cache[$key];
  $stmt = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = ? AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $stmt->execute([$table, $column]);
  $cache[$key] = (bool)$stmt->fetchColumn();
  return $cache[$key];
}
function invoices_cols(PDO $pdo) {
  // Detectar nombres reales en invoices
  $uuid  = column_exists($pdo,'invoices','uuid')      ? 'uuid'      : (column_exists($pdo,'invoices','cfdi_uuid') ? 'cfdi_uuid' : null);
  $serie = column_exists($pdo,'invoices','serie')     ? 'serie'     : null;
  $folio = column_exists($pdo,'invoices','folio')     ? 'folio'     : null;
  return [$uuid,$serie,$folio];
}
$inv_cfdi_col = column_exists($pdo,'inventory','cfdi_uuid') ? 'cfdi_uuid' : null;

/* ===========================================================
   RAMA ESPECIAL: Búsqueda por PARTIDAS (q_item lleno)
   + Soporta q_cfdi (UUID/folio de factura o UUID compra inventario)
   =========================================================== */
if ($q_item !== '') {
  $like_item = '%'.$q_item.'%';
  $cfdi_like = '%'.$q_cfdi.'%';

  // Columnas reales en invoices (pueden no existir)
  [$invc_uuid,$invc_serie,$invc_folio] = invoices_cols($pdo);

  // LEFT JOIN invoices solo si vamos a usarlo
  $join_invc = ($q_cfdi !== '' && ($invc_uuid || $invc_serie || $invc_folio))
    ? " LEFT JOIN invoices invc
           ON invc.company_id = p.company_id
          AND invc.id = p.sale_id "
    : "";

  // Armar condiciones dinámicas para q_cfdi
  $cfdi_conds = [];
  $cfdi_args  = [];
  if ($q_cfdi !== '') {
    if ($invc_uuid)  { $cfdi_conds[] = "invc.$invc_uuid LIKE ?";  $cfdi_args[] = $cfdi_like; }
    if ($invc_serie) { $cfdi_conds[] = "invc.$invc_serie LIKE ?"; $cfdi_args[] = $cfdi_like; }
    if ($invc_folio) { $cfdi_conds[] = "invc.$invc_folio LIKE ?"; $cfdi_args[] = $cfdi_like; }
    if ($invc_serie && $invc_folio) { $cfdi_conds[] = "CONCAT_WS('-', invc.$invc_serie, invc.$invc_folio) LIKE ?"; $cfdi_args[] = $cfdi_like; }
    if ($inv_cfdi_col) { $cfdi_conds[] = "inv.$inv_cfdi_col LIKE ?"; $cfdi_args[] = $cfdi_like; }
  }

  // Conteo
  $sql_count_items = "
    SELECT COUNT(*)
    FROM presale_items i
    JOIN presales p
      ON p.id = i.presale_id AND p.company_id = i.company_id
    JOIN inventory inv
      ON inv.id = i.inventory_id AND inv.company_id = i.company_id
    LEFT JOIN clients c
      ON c.id = p.client_id AND c.company_id = p.company_id
    $join_invc
    WHERE i.company_id = ?
      AND (COALESCE(NULLIF(i.reference,''), inv.description) LIKE ?)
      ".($status!=='' ? " AND p.status = ?" : "")."
      ".($q!=='' ? " AND (p.title LIKE ? OR c.name LIKE ?)" : "")."
      ".(($q_cfdi!=='' && $cfdi_conds) ? " AND (".implode(' OR ', $cfdi_conds).")" : "")."
  ";
  $argsCnt = [$company_id, $like_item];
  if ($status!=='') { $argsCnt[] = $status; }
  if ($q!=='') { $likeParent = '%'.$q.'%'; $argsCnt[] = $likeParent; $argsCnt[] = $likeParent; }
  if ($q_cfdi!=='' && $cfdi_conds) { $argsCnt = array_merge($argsCnt, $cfdi_args); }

  $stc = $pdo->prepare($sql_count_items);
  $stc->execute($argsCnt);
  $total_rows  = (int)$stc->fetchColumn();
  $total_pages = max(1, (int)ceil($total_rows / $per_page));
  if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

  // Datos
  $sql_items = "
    SELECT
      p.id               AS presale_id,
      p.title            AS presale_title,
      p.valid_until      AS valid_until,
      p.status           AS presale_status,
      c.name             AS client_name,

      i.id               AS item_id,
      i.quantity         AS item_qty,
      i.unit_price       AS item_unit_price,
      i.total            AS item_total,

      COALESCE(NULLIF(i.reference,''), inv.description) AS item_description,

      ".($inv_cfdi_col ? "inv.$inv_cfdi_col" : "NULL")." AS compra_uuid

    FROM presale_items i
    JOIN presales p
      ON p.id = i.presale_id AND p.company_id = i.company_id
    JOIN inventory inv
      ON inv.id = i.inventory_id AND inv.company_id = i.company_id
    LEFT JOIN clients c
      ON c.id = p.client_id AND c.company_id = p.company_id
    $join_invc
    WHERE i.company_id = ?
      AND (COALESCE(NULLIF(i.reference,''), inv.description) LIKE ?)
      ".($status!=='' ? " AND p.status = ?" : "")."
      ".($q!=='' ? " AND (p.title LIKE ? OR c.name LIKE ?)" : "")."
      ".(($q_cfdi!=='' && $cfdi_conds) ? " AND (".implode(' OR ', $cfdi_conds).")" : "")."
    ORDER BY p.id DESC, i.id ASC
    LIMIT $per_page OFFSET $offset
  ";
  $args = [$company_id, $like_item];
  if ($status!=='') { $args[] = $status; }
  if ($q!=='') { $args[] = $likeParent; $args[] = $likeParent; }
  if ($q_cfdi!=='' && $cfdi_conds) { $args = array_merge($args, $cfdi_args); }

  $stm = $pdo->prepare($sql_items);
  $stm->execute($args);
  $rows_items = $stm->fetchAll(PDO::FETCH_ASSOC);

  // Resaltador
  $hl = function($text, $needle) {
    if ($needle==='') return e($text);
    return preg_replace('/(' . preg_quote($needle, '/') . ')/i', '<mark>$1</mark>', e($text));
  };

  include 'header.php';
  ?>
  <style>
    .nowrap { white-space: nowrap; }
    .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
  </style>

  <div class="d-flex align-items-center mb-3">
    <h2 class="mb-0 me-2">📝 Pre-ventas</h2>
    <a href="presales_choose.php" class="btn btn-primary ms-auto">➕ Nueva pre-venta</a>
  </div>

  <!-- Formulario de filtros -->
  <form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg-3">
        <label class="form-label">Buscar (título o cliente)</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Título o cliente">
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">Concepto (partidas)</label>
        <input type="text" name="q_item" value="<?= e($q_item) ?>" class="form-control" placeholder="Ej. TMO TUBERIA UL/FM">
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">UUID / Serie-Folio</label>
        <input type="text" name="q_cfdi" value="<?= e($q_cfdi) ?>" class="form-control" placeholder="Ej. 72f1bb... o ABC-1234">
      </div>

      <div class="col-6 col-lg-2">
        <label class="form-label">Estado</label>
        <select name="status" class="form-select">
          <?php
            $opts = [''=>'Todos','draft'=>'Borrador','sent'=>'Enviada','won'=>'Ganada','lost'=>'Perdida','cancelled'=>'Cancelada','expired'=>'Vencida'];
            foreach ($opts as $k=>$v) {
              $sel = ($k===$status) ? 'selected' : '';
              echo "<option value='".e($k)."' $sel>".e($v)."</option>";
            }
          ?>
        </select>
      </div>

      <div class="col-6 col-lg-1">
        <label class="form-label">Por pág.</label>
        <select name="per_page" class="form-select">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-12 d-grid d-lg-flex gap-2 justify-content-lg-end">
        <button class="btn btn-outline-primary" type="submit">Filtrar</button>
        <a class="btn btn-outline-secondary" href="presales.php">Limpiar</a>
      </div>
    </div>
  </div>
</form>

  <!-- Resultados por partidas -->
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="nowrap">Pre-venta #</th>
            <th>Título</th>
            <th>Cliente</th>
            <th class="nowrap">Vigencia</th>
            <th class="nowrap">Estado</th>
            <th>Descripción (match)</th>
            <th class="text-end nowrap">Cant.</th>
            <th class="text-end nowrap">P.U.</th>
            <th class="text-end nowrap">Total partida</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows_items): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No se encontraron partidas.</td></tr>
          <?php else: ?>
            <?php foreach ($rows_items as $r): 
              $vigBadge = (!empty($r['valid_until']))
                ? '<span class="badge bg-light text-dark">'.e($r['valid_until']).'</span>' : '—';
            ?>
            <tr>
              <td class="text-mono">#<?= (int)$r['presale_id'] ?></td>
              <td><?= e($r['presale_title'] ?? '—') ?></td>
              <td><?= e($r['client_name'] ?? '—') ?></td>
              <td class="nowrap"><?= $vigBadge ?></td>
              <td><?= badgeStatus($r['presale_status'] ?? '') ?></td>
              <td>
                <?= $hl($r['item_description'] ?? '', $q_item) ?>
                <?php if (!empty($r['compra_uuid'])): ?>
                  <div class="text-muted small mt-1">UUID compra: <span class="text-mono"><?= e($r['compra_uuid']) ?></span></div>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= n($r['item_qty']) ?></td>
              <td class="text-end">$<?= n($r['item_unit_price']) ?></td>
              <td class="text-end fw-semibold">$<?= n($r['item_total']) ?></td>
              <td class="text-end">
                <a class="btn btn-outline-secondary btn-sm" href="presale_view.php?id=<?= (int)$r['presale_id'] ?>">Abrir</a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php include 'footer.php'; exit; } // FIN rama de búsqueda por partidas ?>


<?php
/* ===========================================================
   RAMA NORMAL: Listado de pre-ventas (igual que antes) + q_cfdi
   =========================================================== */
$w = ["p.company_id = ?"];
$args = [$company_id];

if ($q !== '') {
  $w[]   = "(p.title LIKE ? OR c.name LIKE ?)";
  $like  = "%$q%";
  $args[] = $like; $args[] = $like;
}
if ($status !== '') {
  $w[]   = "p.status = ?";
  $args[] = $status;
}

/* Si hay q_cfdi, detectar columnas reales y construir condiciones seguras */
$cfdi_like = "%$q_cfdi%";
$join_invc = "";
if ($q_cfdi !== '') {
  [$invc_uuid,$invc_serie,$invc_folio] = invoices_cols($pdo);

  if ($invc_uuid || $invc_serie || $invc_folio) {
    $join_invc = "
      LEFT JOIN invoices invc
             ON invc.company_id = p.company_id
            AND invc.id = p.sale_id
    ";
  }

  $conds = [];
  if ($invc_uuid)                 { $conds[] = "invc.$invc_uuid LIKE ?";                 $args[] = $cfdi_like; }
  if ($invc_serie)                { $conds[] = "invc.$invc_serie LIKE ?";                $args[] = $cfdi_like; }
  if ($invc_folio)                { $conds[] = "invc.$invc_folio LIKE ?";                $args[] = $cfdi_like; }
  if ($invc_serie && $invc_folio) { $conds[] = "CONCAT_WS('-', invc.$invc_serie, invc.$invc_folio) LIKE ?"; $args[] = $cfdi_like; }
  if ($inv_cfdi_col)              { $conds[] = "EXISTS (
                                        SELECT 1
                                          FROM presale_items pi
                                          LEFT JOIN inventory inv
                                                 ON inv.company_id = pi.company_id
                                                AND inv.id = pi.inventory_id
                                         WHERE pi.company_id = p.company_id
                                           AND pi.presale_id = p.id
                                           AND inv.$inv_cfdi_col LIKE ?
                                     )";
                                     $args[] = $cfdi_like; }

  if ($conds) {
    $w[] = '('.implode(' OR ', $conds).')';
  }
}

$where = 'WHERE '.implode(' AND ', $w);

/* ====== Total ====== */
$sql_count = "
  SELECT COUNT(*)
  FROM presales p
  LEFT JOIN clients c
    ON c.id = p.client_id AND c.company_id = p.company_id
  $join_invc
  $where
";
$stmtCnt = $pdo->prepare($sql_count);
$stmtCnt->execute($args);
$total_rows  = (int)$stmtCnt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

/* ====== Datos ====== */
$sql = "
  SELECT
    p.id, p.title, p.status, p.valid_until, p.sale_id, p.client_id,
    c.name AS client_name,
    (SELECT COALESCE(SUM(amount),0)
       FROM presale_items pi
      WHERE pi.company_id = p.company_id AND pi.presale_id = p.id) AS subtotal,
    (SELECT COALESCE(SUM(vat),0)
       FROM presale_items pi
      WHERE pi.company_id = p.company_id AND pi.presale_id = p.id) AS vat,
    (SELECT COALESCE(SUM(total),0)
       FROM presale_items pi
      WHERE pi.company_id = p.company_id AND pi.presale_id = p.id) AS total,
    (SELECT ar.status
       FROM accounts_receivable ar
      WHERE ar.company_id = p.company_id AND ar.presale_id = p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_status,
    (SELECT ar.due_date
       FROM accounts_receivable ar
      WHERE ar.company_id = p.company_id AND ar.presale_id = p.id
      ORDER BY ar.id DESC LIMIT 1) AS ar_due_date
  FROM presales p
  LEFT JOIN clients c
    ON c.id = p.client_id AND c.company_id = p.company_id
  $join_invc
  $where
  ORDER BY p.id DESC
  LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<style>
  .nowrap { white-space: nowrap; }
  .text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📝 Pre-ventas</h2>
  <a href="presales_choose.php" class="btn btn-primary ms-auto">➕ Nueva pre-venta</a>
</div>

<form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-lg-3">
        <label class="form-label">Buscar (título o cliente)</label>
        <input type="text" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Título o cliente">
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">Concepto (partidas)</label>
        <input type="text" name="q_item" value="<?= e($q_item) ?>" class="form-control" placeholder="Ej. TMO TUBERIA UL/FM">
      </div>

      <div class="col-12 col-lg-3">
        <label class="form-label">UUID / Serie-Folio</label>
        <input type="text" name="q_cfdi" value="<?= e($q_cfdi) ?>" class="form-control" placeholder="Ej. 72f1bb... o ABC-1234">
      </div>

      <div class="col-6 col-lg-2">
        <label class="form-label">Estado</label>
        <select name="status" class="form-select">
          <?php
            $opts = [''=>'Todos','draft'=>'Borrador','sent'=>'Enviada','won'=>'Ganada','lost'=>'Perdida','cancelled'=>'Cancelada','expired'=>'Vencida'];
            foreach ($opts as $k=>$v) {
              $sel = ($k===$status) ? 'selected' : '';
              echo "<option value='".e($k)."' $sel>".e($v)."</option>";
            }
          ?>
        </select>
      </div>

      <div class="col-6 col-lg-1">
        <label class="form-label">Por pág.</label>
        <select name="per_page" class="form-select">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $per_page==$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 d-grid d-lg-flex gap-2 justify-content-lg-end">
        <button class="btn btn-outline-primary" type="submit">Filtrar</button>
        <a class="btn btn-outline-secondary" href="presales.php">Limpiar</a>
      </div>
    </div>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th class="nowrap">#</th>
          <th>Título</th>
          <th>Cliente</th>
          <th class="nowrap">Vigencia</th>
          <th class="nowrap">Estado</th>
          <th class="text-end nowrap">Subtotal</th>
          <th class="text-end nowrap">IVA</th>
          <th class="text-end nowrap">Total</th>
          <th class="nowrap">CxC</th>
          <th class="nowrap" style="width:170px">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="text-center text-muted py-4">No hay pre-ventas.</td></tr>
        <?php else: ?>
          <?php
            $today = strtotime(date('Y-m-d'));
            foreach ($rows as $r):
              $expired = (!empty($r['valid_until']) && strtotime($r['valid_until']) < $today);
              $vigBadge = '';
              if (!empty($r['valid_until'])) {
                $vigBadge = $expired
                  ? '<span class="badge bg-warning text-dark">'.e($r['valid_until']).'</span>'
                  : '<span class="badge bg-light text-dark">'.e($r['valid_until']).'</span>';
              }
              $cxc = '—';
              if (!empty($r['ar_status'])) {
                $cxc = ($r['ar_status']==='pending' ? '<span class="badge bg-warning text-dark">Pendiente</span>' :
                       ($r['ar_status']==='paid'    ? '<span class="badge bg-success">Pagada</span>' :
                                                      '<span class="badge bg-light text-dark">'.e($r['ar_status']).'</span>'));
                if (!empty($r['ar_due_date'])) {
                  $cxc .= ' <span class="text-muted small">(' . e($r['ar_due_date']) . ')</span>';
                }
              }
          ?>
          <tr>
            <td class="text-mono"><?= (int)$r['id'] ?></td>
            <td><?= e($r['title'] ?? '—') ?></td>
            <td><?= e($r['client_name'] ?? '—') ?></td>
            <td class="nowrap"><?= $vigBadge ?: '—' ?></td>
            <td><?= badgeStatus($r['status'] ?? '') ?></td>
            <td class="text-end">$<?= n($r['subtotal']) ?></td>
            <td class="text-end">$<?= n($r['vat']) ?></td>
            <td class="text-end fw-semibold">$<?= n($r['total']) ?></td>
            <td class="nowrap"><?= $cxc ?></td>
            <td class="nowrap">
              <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary" href="presale_view.php?id=<?= (int)$r['id'] ?>" title="Ver">👁 Ver</a>
                <a class="btn btn-outline-primary" href="presale_edit.php?id=<?= (int)$r['id'] ?>" title="Modificar">✏️ Modificar</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
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
