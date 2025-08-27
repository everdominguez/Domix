<?php
// inventory_picker.php
require_once 'auth.php';
require_once 'db.php';


header('Content-Type: text/html; charset=utf-8');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
  http_response_code(401);
  echo '<div class="text-danger p-3">No autorizado.</div>';
  exit;
}
$company_id = (int)$_SESSION['company_id'];

// Lee filtros desde POST (AJAX) o GET como respaldo
$S = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;

$search     = trim($S['q'] ?? '');
$project_id = (isset($S['project_id']) && $S['project_id'] !== '') ? (int)$S['project_id'] : null;
$date_from  = trim($S['date_from'] ?? '');
$date_to    = trim($S['date_to']   ?? '');
$page       = max(1, (int)($S['page'] ?? 1));
$per_page   = (int)($S['per_page'] ?? 25);
if (!in_array($per_page, [10,25,50,100], true)) $per_page = 25;
$offset     = ($page - 1) * $per_page;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, 2); }

// Base de filtros
$where  = ["i.company_id = ?", "i.quantity > 0", "i.active = 1"];
$params = [$company_id];

// Fecha documental: invoice_date si existe, si no created_at (DATE)
$select_date = "COALESCE(i.invoice_date, DATE(i.created_at))";

if ($project_id) {
  $where[]  = "i.project_id = ?";
  $params[] = $project_id;
}

if ($search !== '') {
  // Busca en código, descripción, UUID, proveedor y folio/serie/invoice_number
  $where[] = "("
           . "i.product_code LIKE ? OR "
           . "i.description LIKE ? OR "
           . "i.cfdi_uuid LIKE ? OR "
           . "e.provider_name LIKE ? OR "
           . "e.provider_rfc LIKE ? OR "
           . "e.invoice_number LIKE ? OR "
           . "e.folio LIKE ? OR "
           . "e.serie LIKE ?"
           . ")";
  $like = "%{$search}%";
  array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}

if ($date_from !== '') {
  $where[]  = "$select_date >= ?";
  $params[] = $date_from;
}
if ($date_to !== '') {
  $where[]  = "$select_date <= ?";
  $params[] = $date_to;
}

$where_sql = 'WHERE '.implode(' AND ', $where);

// Total para paginación
$sql_count = "
  SELECT COUNT(*)
  FROM inventory i
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  $where_sql
";
try {
  $stmt = $pdo->prepare($sql_count);
  $stmt->execute($params);
  $total_rows  = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
  echo '<div class="text-danger p-3">Error al contar resultados: '.h($e->getMessage()).'</div>';
  exit;
}

$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) {
  $page = $total_pages;
  $offset = ($page - 1) * $per_page;
}

// Datos de la página
$sql_data = "
  SELECT
    i.id,
    $select_date AS doc_date,
    p.name AS project_name,
    i.product_code,
    i.description,
    i.quantity,
    i.unit_price,
    i.amount,
    i.vat,
    i.total,
    i.cfdi_uuid
  FROM inventory i
  LEFT JOIN projects p ON p.id = i.project_id
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  $where_sql
  ORDER BY doc_date DESC, i.id DESC
  LIMIT $per_page OFFSET $offset
";

try {
  $stmt = $pdo->prepare($sql_data);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo '<div class="text-danger p-3">Error al cargar inventario: '.h($e->getMessage()).'</div>';
  exit;
}
?>

<div class="table-responsive">
  <table class="table table-hover table-sm mb-0">
    <thead>
      <tr>
        <th class="text-center" style="width:38px">
          <input type="checkbox" id="invPickSelectAll">
        </th>
        <th class="nowrap">Fecha CFDI</th>
        <th>Proyecto</th>
        <th class="nowrap">Código</th>
        <th>Descripción</th>
        <th class="text-end nowrap">Cant.</th>
        <th class="text-end nowrap">P. Unit.</th>
        <th class="text-end nowrap">Subtotal</th>
        <th class="text-end nowrap">IVA</th>
        <th class="text-end nowrap">Total</th>
        <th class="nowrap">UUID</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="text-center py-4 text-muted">No hay partidas que coincidan.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="text-center">
              <input type="checkbox"
                     class="invPick"
                     value="<?= (int)$r['id'] ?>"
                     data-subtotal="<?= (float)($r['amount'] ?? 0) ?>"
                     data-iva="<?= (float)($r['vat'] ?? 0) ?>"
                     data-total="<?= (float)($r['total'] ?? 0) ?>">
            </td>
            <td class="nowrap"><?= h($r['doc_date'] ?? '') ?></td>
            <td><?= h($r['project_name'] ?? '') ?></td>
            <td class="text-mono"><?= h($r['product_code'] ?? '') ?></td>
            <td><?= h($r['description'] ?? '') ?></td>
            <td class="text-end"><?= nf($r['quantity'] ?? 0) ?></td>
            <td class="text-end">$<?= nf($r['unit_price'] ?? 0) ?></td>
            <td class="text-end">$<?= nf($r['amount'] ?? 0) ?></td>
            <td class="text-end">$<?= nf($r['vat'] ?? 0) ?></td>
            <td class="text-end">$<?= nf($r['total'] ?? 0) ?></td>
            <td class="uuid-col" title="<?= h($r['cfdi_uuid'] ?? '') ?>">
  <?= h($r['cfdi_uuid'] ?? '') ?>
</td>

          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="d-flex justify-content-between align-items-center gap-2 p-2 border-top">
  <div class="text-muted small">
    Mostrando
    <strong><?= ($total_rows === 0) ? 0 : ($offset + 1) ?></strong>–
    <strong><?= min($offset + $per_page, $total_rows) ?></strong>
    de <strong><?= $total_rows ?></strong>
  </div>

  <?php if ($total_pages > 1): ?>
    <nav aria-label="Paginación">
      <ul class="pagination pagination-sm mb-0">
        <?php
          $prev_disabled = ($page <= 1) ? ' disabled' : '';
          $next_disabled = ($page >= $total_pages) ? ' disabled' : '';
        ?>
        <li class="page-item<?= $prev_disabled ?>">
          <a href="#" class="page-link picker-page" data-page="<?= max(1, $page-1) ?>">«</a>
        </li>
        <?php
          $start = max(1, $page - 2);
          $end   = min($total_pages, $page + 2);
          if ($start > 1) {
            echo '<li class="page-item"><a href="#" class="page-link picker-page" data-page="1">1</a></li>';
            if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for ($p = $start; $p <= $end; $p++) {
            $active = $p == $page ? ' active' : '';
            echo '<li class="page-item'.$active.'"><a href="#" class="page-link picker-page" data-page="'.$p.'">'.$p.'</a></li>';
          }
          if ($end < $total_pages) {
            if ($end < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            echo '<li class="page-item"><a href="#" class="page-link picker-page" data-page="'.$total_pages.'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item<?= $next_disabled ?>">
          <a href="#" class="page-link picker-page" data-page="<?= min($total_pages, $page+1) ?>">»</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>


<style>
  /* Una sola línea con ellipsis */
  .uuid-col{
    max-width: 160px;        /* ajusta a tu gusto */
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: .82rem;
  }
</style>
