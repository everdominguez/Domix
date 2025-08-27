<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
  header('Location: choose_company.php'); exit;
}
$company_id = (int)$_SESSION['company_id'];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($x){ return number_format((float)$x, 2); }

$presale_id = (int)($_GET['id'] ?? 0);

/* --------- Encabezado de la pre-venta + totales --------- */
$sqlHead = "
  SELECT
    p.*,
    c.name  AS client_name,
    pr.name AS project_name,
    (SELECT COALESCE(SUM(amount),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS subtotal,
    (SELECT COALESCE(SUM(vat),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS vat,
    (SELECT COALESCE(SUM(total),0) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS total,
    (SELECT COUNT(*) FROM presale_items pi
      WHERE pi.company_id=p.company_id AND pi.presale_id=p.id) AS items_count
  FROM presales p
  LEFT JOIN clients  c  ON c.id=p.client_id  AND c.company_id=p.company_id
  LEFT JOIN projects pr ON pr.id=p.project_id AND pr.company_id=p.company_id
  WHERE p.company_id=? AND p.id=?
";
$st = $pdo->prepare($sqlHead);
$st->execute([$company_id, $presale_id]);
$presale = $st->fetch(PDO::FETCH_ASSOC);
if (!$presale){
  include 'header.php';
  echo '<div class="container py-4"><div class="alert alert-danger">Pre-venta no encontrada.</div></div>';
  include 'footer.php'; exit;
}

/* --------- Acciones (quitar partida) --------- */
$err = null; $okmsg = null;

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['remove_item'])) {
  $item_id = (int)$_POST['item_id'];

  // Traer item para conocer inventory_id
  $st = $pdo->prepare("SELECT id, inventory_id FROM presale_items WHERE id=? AND presale_id=? AND company_id=?");
  $st->execute([$item_id, $presale_id, $company_id]);
  $item = $st->fetch(PDO::FETCH_ASSOC);

  if ($item){
    try{
      $pdo->beginTransaction();

      // Borrar la partida
      $pdo->prepare("DELETE FROM presale_items WHERE id=? AND presale_id=? AND company_id=?")
          ->execute([$item_id, $presale_id, $company_id]);

      // Si la partida provenía de inventario, puedes reactivar el inventario (opcional)
      if (!empty($item['inventory_id'])) {
        $pdo->prepare("UPDATE inventory SET active=1 WHERE id=? AND company_id=?")
            ->execute([(int)$item['inventory_id'], $company_id]);
      }

      $pdo->commit();
      $okmsg = "Partida eliminada.";
    } catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = "No se pudo eliminar: ".$e->getMessage();
    }
  } else {
    $err = "Partida no encontrada.";
  }
}

/* --------- Listado de partidas --------- */
/*
  ⚠️ OJO: aquí estaba el problema. La columna description se toma de INVENTORY,
  por eso la calificamos como i.description y le damos alias inv_description.
*/
$sqlItems = "
  SELECT
    pi.id,
    pi.inventory_id,
    pi.quantity,
    pi.unit_price,
    pi.amount,
    pi.vat,
    pi.total,
    i.product_code,
    i.description AS inv_description
  FROM presale_items pi
  LEFT JOIN inventory i
         ON i.id = pi.inventory_id
        AND i.company_id = pi.company_id
  WHERE pi.company_id=? AND pi.presale_id=?
  ORDER BY pi.id
";
$st = $pdo->prepare($sqlItems);
$st->execute([$company_id, $presale_id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<style>
  .pill{ background:#f1f3f5; border-radius:999px; padding:.25rem .6rem; }
  .nowrap{ white-space:nowrap; }
</style>

<div class="container py-4">
  <div class="d-flex align-items-center gap-2 mb-2">
    <h2 class="mb-0">✏️ Editar partidas · Pre-venta #<?= (int)$presale['id'] ?></h2>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="presale_edit.php?id=<?= (int)$presale['id'] ?>">← Datos</a>
      <a class="btn btn-outline-secondary btn-sm" href="presale_view.php?id=<?= (int)$presale['id'] ?>">👁️ Ver</a>
      <a class="btn btn-outline-secondary btn-sm" href="presales.php">Lista</a>
    </div>
  </div>

  <div class="mb-3 d-flex gap-2 flex-wrap">
    <span class="pill">Partidas: <strong><?= (int)$presale['items_count'] ?></strong></span>
    <span class="pill">Subtotal: $<strong><?= n($presale['subtotal']) ?></strong></span>
    <span class="pill">IVA: $<strong><?= n($presale['vat']) ?></strong></span>
    <span class="pill">Total: $<strong><?= n($presale['total']) ?></strong></span>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>
  <?php if ($okmsg): ?>
    <div class="alert alert-success"><?= e($okmsg) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead>
            <tr>
              <th style="width:70px" class="text-center">#</th>
              <th class="nowrap">Código</th>
              <th>Descripción</th>
              <th class="text-end nowrap">Cant.</th>
              <th class="text-end nowrap">P. Unit.</th>
              <th class="text-end nowrap">Subtotal</th>
              <th class="text-end nowrap">IVA</th>
              <th class="text-end nowrap">Total</th>
              <th class="text-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$items): ?>
              <tr><td colspan="9" class="text-center py-4 text-muted">Sin partidas todavía.</td></tr>
            <?php else: ?>
              <?php foreach ($items as $k=>$it): ?>
                <tr>
                  <td class="text-center"><?= (int)$it['id'] ?></td>
                  <td class="text-mono"><?= e($it['product_code'] ?? '') ?></td>
                  <td><?= e($it['inv_description'] ?? '') ?></td>
                  <td class="text-end"><?= n($it['quantity']) ?></td>
                  <td class="text-end">$<?= n($it['unit_price']) ?></td>
                  <td class="text-end">$<?= n($it['amount']) ?></td>
                  <td class="text-end">$<?= n($it['vat']) ?></td>
                  <td class="text-end"><strong>$<?= n($it['total']) ?></strong></td>
                  <td class="text-center">
                    <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar esta partida?');">
                      <input type="hidden" name="remove_item" value="1">
                      <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                      <button class="btn btn-outline-danger btn-sm">Quitar</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card-footer d-flex justify-content-between">
      <div class="text-muted small">
        Cliente: <strong><?= e($presale['client_name'] ?? '—') ?></strong>
        <?php if (!empty($presale['project_name'])): ?>
          · Proyecto: <strong><?= e($presale['project_name']) ?></strong>
        <?php endif; ?>
      </div>

      <div class="d-flex gap-2">
        <button type="button" id="btnAddFromInventory" class="btn btn-success btn-sm">
  ✚ Agregar desde inventario
</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Picker de inventario -->
<div class="modal fade" id="invPickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Seleccionar partidas desde inventario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">

        <!-- Filtros -->
        <form id="invPickFilters" class="row g-2 mb-3">
          <div class="col-md-4">
            <input type="text" name="q" class="form-control" placeholder="Buscar código, descripción, UUID…">
          </div>
          <div class="col-md-3">
            <select name="project_id" class="form-select">
              <option value="">Todos los proyectos</option>
              <?php
                $prj = $pdo->prepare("SELECT id,name FROM projects WHERE company_id=? ORDER BY name");
                $prj->execute([$company_id]);
                foreach ($prj->fetchAll(PDO::FETCH_ASSOC) as $op) {
                  echo '<option value="'.(int)$op['id'].'">'.htmlspecialchars($op['name']).'</option>';
                }
              ?>
            </select>
          </div>
          <div class="col-md-2">
            <input type="date" name="date_from" class="form-control" placeholder="Desde">
          </div>
          <div class="col-md-2">
            <input type="date" name="date_to" class="form-control" placeholder="Hasta">
          </div>
          <div class="col-md-1">
            <select name="per_page" class="form-select">
              <option>10</option><option selected>25</option><option>50</option><option>100</option>
            </select>
          </div>
        </form>

        <!-- Resultados -->
        <div id="invPickerResults" class="border rounded">
          <div class="text-center text-muted py-5">Cargando…</div>
        </div>

      </div>
      <div class="modal-footer">
        <div class="me-auto small text-muted">
          Seleccionados: <strong id="invPickCount">0</strong>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" id="invPickAdd" class="btn btn-primary">Agregar seleccionados</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const PRESALE_ID = <?= (int)$presale_id ?>;
  const modalEl = document.getElementById('invPickerModal');
  const results = document.getElementById('invPickerResults');
  const filters = document.getElementById('invPickFilters');
  const countEl = document.getElementById('invPickCount');
  const btnOpen = document.getElementById('btnAddFromInventory');
  const btnAdd  = document.getElementById('invPickAdd');

  function selectedIds(){
    return Array.from(results.querySelectorAll('.invPick:checked')).map(cb=>cb.value);
  }
  function updateCount(){ countEl.textContent = selectedIds().length; }

  function loadPicker(page=1){
    const fd = new FormData(filters);
    fd.set('page', page);
    fd.set('presale_id', PRESALE_ID);

    results.innerHTML = '<div class="text-center text-muted py-5">Cargando…</div>';

    fetch('inventory_picker.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(html => {
      results.innerHTML = html;
      results.querySelector('#invPickSelectAll')?.addEventListener('change', function(){
        results.querySelectorAll('.invPick').forEach(cb => { cb.checked = this.checked; });
        updateCount();
      });
      results.querySelectorAll('.invPick').forEach(cb => cb.addEventListener('change', updateCount));
      results.querySelectorAll('.picker-page').forEach(a=>{
        a.addEventListener('click', (e)=>{ e.preventDefault(); loadPicker(parseInt(a.dataset.page)); });
      });
      updateCount();
    })
    .catch(()=>{
      results.innerHTML = '<div class="text-danger p-3">No se pudieron cargar los datos.</div>';
    });
  }

  // abrir modal
  btnOpen?.addEventListener('click', ()=>{
    new bootstrap.Modal(modalEl).show();
    loadPicker(1);
  });

  // filtros con debounce
  let t;
  filters.addEventListener('input', ()=>{
    clearTimeout(t); t = setTimeout(()=> loadPicker(1), 300);
  });
  filters.addEventListener('change', ()=> loadPicker(1));

  // agregar seleccionados (esto ya lo tenías con POST a presale_add_from_inventory.php)
})();
</script>


<?php include 'footer.php'; ?>
