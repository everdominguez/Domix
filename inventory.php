<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = (int)$_SESSION['company_id'];

include 'header.php';

/* ========= Filtros y paginación ========= */
$search      = trim($_GET['search'] ?? '');
$project_id  = isset($_GET['project_id']) && $_GET['project_id'] !== '' ? (int)$_GET['project_id'] : null;
$date_from   = $_GET['date_from'] ?? '';
$date_to     = $_GET['date_to']   ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page_in = (int)($_GET['per_page'] ?? 25);
$per_page    = in_array($per_page_in, [10,25,50,100], true) ? $per_page_in : 25;
$offset      = ($page - 1) * $per_page;

$where  = ["i.company_id = ?", "i.quantity > 0", "i.active = 1"];
$params = [$company_id];

// Usaremos invoice_date si existe; si no, created_at (DATE)
$select_date = "COALESCE(i.invoice_date, DATE(i.created_at))";

if ($project_id) {
    $where[]  = "i.project_id = ?";
    $params[] = $project_id;
}

if ($search !== '') {
    // Buscamos en código, descripción, UUID, proveedor (nombre/RFC) y folio/serie/invoice_number
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
    // mismo comodín para todos los campos
    $like = "%$search%";
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
$where_sql = 'WHERE ' . implode(' AND ', $where);

/* ========= Total para paginación ========= */
$sql_count = "
  SELECT COUNT(*)
  FROM inventory i
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  $where_sql
";
$stmtCnt = $pdo->prepare($sql_count);
$stmtCnt->execute($params);
$total_rows  = (int)$stmtCnt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page-1)*$per_page; }

/* ========= Datos página ========= */
$sql_data = "
  SELECT 
    i.*,
    p.name AS project_name,
    $select_date AS doc_date,
    e.provider_name,
    e.provider_rfc,
    e.invoice_number,
    e.folio,
    e.serie
  FROM inventory i
  LEFT JOIN projects p ON p.id = i.project_id
  LEFT JOIN expenses e ON e.id = i.expense_id AND e.company_id = i.company_id
  $where_sql
  ORDER BY doc_date DESC, i.id DESC
  LIMIT $per_page OFFSET $offset
";
$stmt = $pdo->prepare($sql_data);
$stmt->execute($params);
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ========= Catálogo de proyectos ========= */
$stmtProjects = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmtProjects->execute([$company_id]);
$projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

/* ========= Helper para links de paginación ========= */
function qs_keep(array $extra = [], array $drop = ['page']) {
  $q = $_GET;
  foreach ($drop as $d) unset($q[$d]);
  $q = array_merge($q, $extra);
  return http_build_query($q);
}
?>

<style>
.table-inventory thead th {
  position: sticky; top: 0; z-index: 2; background: #f8f9fa;
}
.table-inventory td, .table-inventory th { vertical-align: middle; }
.text-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
.nowrap { white-space: nowrap; }
.badge-proj { background: #e9ecef; color:#495057; }
.uuid-col { 
  font-family: inherit;
  font-size: 0.875rem;
  color: #0d6efd;
  word-break: break-all;
  max-width: 220px;
}
.uuid-col a { text-decoration: none; }
.uuid-col a:hover { text-decoration: underline; }
#sumBox .pill { background:#f1f3f5; border-radius:999px; padding:.25rem .6rem; }
#sumBox strong { font-variant-numeric: tabular-nums; }
</style>

<div class="d-flex align-items-center mb-3">
  <h2 class="mb-0 me-2">📦 Inventario Cargado desde CFDI</h2>
</div>

<form class="card shadow-sm mb-4" method="get">
  <div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Buscar</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Código o descripción..." class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label">Proyecto</label>
        <select name="project_id" class="form-select">
          <option value="">Todos los proyectos</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ($project_id == $p['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Desde (Fecha CFDI)</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta (Fecha CFDI)</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control">
      </div>
      <div class="col-md-1">
        <label class="form-label">Por pág.</label>
        <select name="per_page" class="form-select">
          <?php foreach ([10,25,50,100] as $n): ?>
            <option value="<?= $n ?>" <?= $per_page == $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 d-flex align-items-center flex-wrap gap-2">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="inventory.php" class="btn btn-outline-secondary">Limpiar</a>
        <button type="button" id="btn-clear-selection" class="btn btn-outline-danger btn-sm ms-2">Limpiar selección</button>

        <!-- Acciones + Sumas -->
        <div id="row-actions" class="d-none w-100 d-flex align-items-center gap-3 justify-content-start mt-2">
          <div class="btn-group">
            <button type="button" id="btn-associate-sale" class="btn btn-success btn-sm">🧾 Asociar a venta</button>
              <button type="button" id="btn-convert-expense" class="btn btn-warning btn-sm">💸 Convertir a gasto</button>
                <button type="button" id="btn-associate-presale" class="btn btn-info btn-sm">📝 Asociar a pre-venta</button>
          </div>
          <span class="text-muted small">Seleccionados: <strong id="selectedCount">0</strong></span>
          <div id="sumBox" class="small d-flex align-items-center gap-2">
            <span class="pill">Subtotal: $<strong id="sumSubtotal">0.00</strong></span>
            <span class="pill">IVA: $<strong id="sumIva">0.00</strong></span>
            <span class="pill">Total: $<strong id="sumTotal">0.00</strong></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-striped table-hover table-sm table-inventory mb-0">
      <thead>
        <tr>
          <th class="text-center" style="width:38px"><input type="checkbox" id="select-all"></th>
          <th class="nowrap">Fecha CFDI</th>
          <th>Proyecto</th>
          <th class="nowrap">Código</th>
          <th>Descripción</th>
          <th class="text-end nowrap">Cantidad</th>
          <th class="text-end nowrap">Precio Unitario</th>
          <th class="text-end nowrap">Subtotal</th>
          <th class="text-end nowrap">IVA</th>
          <th class="text-end nowrap">Total</th>
          <th class="nowrap">UUID</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($inventory)): ?>
          <tr><td colspan="11" class="text-center py-4 text-muted">No hay productos en inventario con los criterios seleccionados.</td></tr>
        <?php else: ?>
          <?php foreach ($inventory as $row): ?>
            <tr class="inventory-row" 
                data-id="<?= (int)$row['id'] ?>"
                data-subtotal="<?= (float)($row['amount'] ?? 0) ?>"
                data-iva="<?= (float)($row['vat'] ?? 0) ?>"
                data-total="<?= (float)($row['total'] ?? 0) ?>">
              <td class="text-center">
                <input type="checkbox" class="select-row" value="<?= (int)$row['id'] ?>">
              </td>
              <td class="nowrap"><?= htmlspecialchars($row['doc_date'] ?? '') ?></td>
              <td>
                <?php if (!empty($row['project_name'])): ?>
                  <span class="badge badge-proj rounded-pill"><?= htmlspecialchars($row['project_name']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-mono"><?= htmlspecialchars($row['product_code'] ?? '') ?></td>
              <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
              <td class="text-end"><?= number_format((float)($row['quantity'] ?? 0), 2) ?></td>
              <td class="text-end">$<?= number_format((float)($row['unit_price'] ?? 0), 2) ?></td>
              <td class="text-end">$<?= number_format((float)($row['amount'] ?? 0), 2) ?></td>
              <td class="text-end">$<?= number_format((float)($row['vat'] ?? 0), 2) ?></td>
              <td class="text-end">$<?= number_format((float)($row['total'] ?? 0), 2) ?></td>
              <td class="uuid-col">
                <?php if (!empty($row['cfdi_uuid'])): ?>
                  <a href="#" class="uuid-link" data-uuid="<?= htmlspecialchars($row['cfdi_uuid']) ?>">
                    <?= htmlspecialchars($row['cfdi_uuid']) ?>
                  </a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 border-top">
    <div class="text-muted small">
      Mostrando
      <strong><?= ($total_rows === 0) ? 0 : ($offset + 1) ?></strong>–
      <strong><?= min($offset + $per_page, $total_rows) ?></strong>
      de <strong><?= $total_rows ?></strong> registros
    </div>

    <nav aria-label="Paginación">
      <ul class="pagination pagination-sm mb-0">
        <?php
          $prev_disabled = ($page <= 1) ? ' disabled' : '';
          $next_disabled = ($page >= $total_pages) ? ' disabled' : '';
        ?>
        <li class="page-item<?= $prev_disabled ?>">
          <a class="page-link" href="?<?= qs_keep(['page' => max(1, $page-1)]) ?>" tabindex="-1">«</a>
        </li>
        <?php
          $start = max(1, $page - 2);
          $end   = min($total_pages, $page + 2);
          if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="?'.qs_keep(['page'=>1]).'">1</a></li>';
              if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          }
          for ($p = $start; $p <= $end; $p++) {
              $active = $p == $page ? ' active' : '';
              echo '<li class="page-item'.$active.'"><a class="page-link" href="?'.qs_keep(['page'=>$p]).'">'.$p.'</a></li>';
          }
          if ($end < $total_pages) {
              if ($end < $total_pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              echo '<li class="page-item"><a class="page-link" href="?'.qs_keep(['page'=>$total_pages]).'">'.$total_pages.'</a></li>';
          }
        ?>
        <li class="page-item<?= $next_disabled ?>">
          <a class="page-link" href="?<?= qs_keep(['page' => min($total_pages, $page+1)]) ?>">»</a>
        </li>
      </ul>
    </nav>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  const COMPANY_ID = <?= (int)$company_id ?>;
  const LS_KEY = `invSel_company_${COMPANY_ID}`;

  function loadSel() {
    try { const v = JSON.parse(localStorage.getItem(LS_KEY) || "[]"); return Array.isArray(v) ? v.map(String) : []; }
    catch { return []; }
  }
  function saveSel(ids) {
    const uniq = [...new Set(ids.map(String))];
    localStorage.setItem(LS_KEY, JSON.stringify(uniq));
    return uniq;
  }
  function addSel(ids){ const s=new Set(loadSel()); ids.forEach(id=>s.add(String(id))); return saveSel([...s]); }
  function delSel(ids){ const s=new Set(loadSel()); ids.forEach(id=>s.delete(String(id))); return saveSel([...s]); }
  function clearSel(){ localStorage.removeItem(LS_KEY); }

  const table        = document.querySelector(".table-inventory");
  const selectAll    = document.getElementById("select-all");
  const actionsBar   = document.getElementById("row-actions");
  const associateBtn = document.getElementById("btn-associate-sale");
  const clearBtn     = document.getElementById("btn-clear-selection");
  const countBarEl   = document.getElementById("selectedCount");
  const countModalEl = document.getElementById("modalSelectedCount");

  const form       = document.querySelector('form.card');
  const searchInp  = form?.querySelector('input[name="search"]');
  const projectSel = form?.querySelector('select[name="project_id"]');
  const dateFrom   = form?.querySelector('input[name="date_from"]');
  const dateTo     = form?.querySelector('input[name="date_to"]');
  const perPageSel = form?.querySelector('select[name="per_page"]');

  const sumSubtotalEl = document.getElementById('sumSubtotal');
  const sumIvaEl      = document.getElementById('sumIva');
  const sumTotalEl    = document.getElementById('sumTotal');

  function fmt(n){ return (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }

  function rowCbs(){ return table ? Array.from(table.querySelectorAll(".select-row")) : []; }
  function pageIds(){ return rowCbs().map(cb => String(cb.value)); }

  function updateActionsBar(){
    const n = loadSel().length;
    actionsBar?.classList.toggle("d-none", n === 0);
    if (countBarEl) countBarEl.textContent = n;
    refreshSums();
  }

  function setMasterState(){
    if (!selectAll) return;
    const sel = new Set(loadSel());
    const ids = pageIds();
    const marked = ids.filter(id => sel.has(id)).length;
    selectAll.indeterminate = marked > 0 && marked < ids.length;
    selectAll.checked = ids.length > 0 && marked === ids.length;
  }

  function paintFromStorage(){
    const sel = new Set(loadSel());
    rowCbs().forEach(cb => {
      const id = String(cb.value);
      cb.checked = sel.has(id);
      cb.closest("tr")?.classList.toggle("table-primary", cb.checked);
    });
    updateActionsBar();
    setMasterState();
  }

  async function refreshSums(){
    const ids = loadSel();
    if (!ids.length) {
      sumSubtotalEl.textContent = fmt(0);
      sumIvaEl.textContent      = fmt(0);
      sumTotalEl.textContent    = fmt(0);
      if (countModalEl) countModalEl.textContent = 0;
      return;
    }
    try {
      const res = await fetch('sum_inventory_ids.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ ids })
      });
      if (!res.ok) throw new Error('bad status');
      const { subtotal=0, iva=0, total=0, count=ids.length } = await res.json();
      sumSubtotalEl.textContent = fmt(subtotal);
      sumIvaEl.textContent      = fmt(iva);
      sumTotalEl.textContent    = fmt(total);
      if (countModalEl) countModalEl.textContent = count;
    } catch (e) {
      let s=0,i=0,t=0;
      rowCbs().forEach(cb=>{
        if (cb.checked){
          const tr = cb.closest('tr');
          s += Number(tr?.dataset.subtotal || 0);
          i += Number(tr?.dataset.iva || 0);
          t += Number(tr?.dataset.total || 0);
        }
      });
      sumSubtotalEl.textContent = fmt(s);
      sumIvaEl.textContent      = fmt(i);
      sumTotalEl.textContent    = fmt(t);
      if (countModalEl) countModalEl.textContent = loadSel().length;
    }
  }

  function wireRows(){
    rowCbs().forEach(cb => {
      cb.addEventListener("change", function(){
        const id = String(this.value);
        if (this.checked) addSel([id]); else delSel([id]);
        this.closest("tr")?.classList.toggle("table-primary", this.checked);
        updateActionsBar();
        setMasterState();
      });
    });
  }

  if (selectAll){
    selectAll.addEventListener("change", function(){
      const ids = pageIds();
      rowCbs().forEach(cb => {
        cb.checked = selectAll.checked;
        cb.closest("tr")?.classList.toggle("table-primary", selectAll.checked);
      });
      if (selectAll.checked) addSel(ids); else delSel(ids);
      updateActionsBar();
      setMasterState();
    });
  }

  document.getElementById("btn-clear-selection")?.addEventListener("click", function(){
    clearSel();
    rowCbs().forEach(cb => { cb.checked = false; cb.closest("tr")?.classList.remove("table-primary"); });
    if (selectAll){ selectAll.checked = false; selectAll.indeterminate = false; }
    updateActionsBar();
  });

  // ===== Asociar a venta =====
  document.getElementById("btn-associate-sale")?.addEventListener("click", function(){
    const all = loadSel();
    if (countModalEl) countModalEl.textContent = all.length;
    if (!all.length) return;
    new bootstrap.Modal(document.getElementById("confirmModal")).show();
  });

  document.getElementById("confirmAssociate")?.addEventListener("click", function(){
    const all = loadSel();
    if (all.length > 0) window.location.href = "associate_sale.php?ids=" + all.join(",");
  });

  // ===== Convertir a gasto =====
  const convertBtn     = document.getElementById("btn-convert-expense");
  const convertForm    = document.getElementById("convertForm");
  const convertCountEl = document.getElementById("convertSelectedCount");

  convertBtn?.addEventListener("click", function(){
    const all = loadSel();
    if (!all.length) return;
    if (convertCountEl) convertCountEl.textContent = all.length;
    new bootstrap.Modal(document.getElementById("convertModal")).show();
  });

  convertForm?.addEventListener("submit", async function(e){
    e.preventDefault();
    const ids = loadSel();
    if (!ids.length) return;

    const formData = new FormData(convertForm);
    const payload = {
      ids,
      expense_date: formData.get('expense_date') || '',
      project_id: formData.get('project_id') || '',
      payment_method_id: formData.get('payment_method_id') || '',
      comment: formData.get('comment') || ''
    };

    try {
      const res = await fetch('convert_inventory_to_expense.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok || !data.ok) {
        alert('Error: ' + (data.error || 'No se pudo completar la conversión.'));
        return;
      }
      clearSel();
      bootstrap.Modal.getInstance(document.getElementById("convertModal"))?.hide();
      alert(`Convertidos: ${data.converted} | Errores: ${data.errors?.length || 0}`);
      location.reload();
    } catch (err) {
      alert('Error de red/proceso: ' + err.message);
    }
  });

  // ===== Asociar a pre-venta =====
const preBtn = document.getElementById("btn-associate-presale");
const preCountEl = document.getElementById("preModalSelectedCount");

preBtn?.addEventListener("click", function(){
  const all = loadSel();
  if (!all.length) return;
  if (preCountEl) preCountEl.textContent = all.length;
  new bootstrap.Modal(document.getElementById("confirmPreModal")).show();
});

document.getElementById("confirmAssociatePre")?.addEventListener("click", function(){
  const all = loadSel();
  if (all.length > 0) {
    // flujo simple: pasa ids por querystring
    window.location.href = "associate_presale.php?ids=" + all.join(",");
  }
});


  // ===== Autofiltro =====
  function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }
  const autoSubmit = debounce(() => { form?.requestSubmit(); }, 350);

  searchInp?.addEventListener('input', autoSubmit);
  [projectSel, dateFrom, dateTo, perPageSel].forEach(el => el?.addEventListener('change', ()=>form.requestSubmit()));

  // ===== Visor UUID =====
  document.querySelectorAll('.uuid-link').forEach(a=>{
    a.addEventListener('click', e=>{
      e.preventDefault();
      const uuid = a.dataset.uuid;
      const modalEl = document.getElementById('cfdiModal');
      const bodyEl  = modalEl.querySelector('.modal-body');
      bodyEl.innerHTML = '<div class="text-center text-muted py-4">Cargando…</div>';
      fetch('cfdi_details.php?uuid=' + encodeURIComponent(uuid))
        .then(r=>r.text()).then(html=>{ bodyEl.innerHTML = html; })
        .catch(()=>{ bodyEl.innerHTML = '<div class="text-danger">Error al cargar el detalle.</div>'; });
      new bootstrap.Modal(modalEl).show();
    });
  });

  // ===== Depurar selección y pintar =====
  async function purgeSelection() {
    const current = loadSel();
    if (!current.length) return;
    try {
      const res = await fetch('validate_inventory_ids.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ ids: current })
      });
      if (!res.ok) throw new Error('bad status');
      const data = await res.json();
      const existing = Array.isArray(data.ids) ? data.ids.map(String) : [];
      saveSel(existing);
    } catch (e) {
      console.warn('No se pudo depurar la selección:', e);
    }
  }

  purgeSelection().then(() => {
    wireRows();
    paintFromStorage();
  });
});
</script>


<!-- Modal de confirmación (asociar a venta) -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmLabel">Confirmar Asociación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        ¿Estás seguro de que deseas asociar <strong id="modalSelectedCount">0</strong> producto(s) a una venta?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="confirmAssociate" class="btn btn-success">Sí, continuar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal visor CFDI -->
<div class="modal fade" id="cfdiModal" tabindex="-1" aria-labelledby="cfdiLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cfdiLabel">Detalle CFDI</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <!-- contenido via AJAX -->
      </div>
    </div>
  </div>
</div>

<!-- Modal de confirmación (asociar a pre-venta) -->
<div class="modal fade" id="confirmPreModal" tabindex="-1" aria-labelledby="confirmPreLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmPreLabel">Confirmar Asociación a Pre-Venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        ¿Deseas asociar <strong id="preModalSelectedCount">0</strong> partida(s) de inventario a una pre-venta?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="confirmAssociatePre" class="btn btn-info">Sí, continuar</button>
      </div>
    </div>
  </div>
</div>


<!-- Modal Convertir a Gasto -->
<div class="modal fade" id="convertModal" tabindex="-1" aria-labelledby="convertLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form id="convertForm" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="convertLabel">Convertir a gasto y dar de baja</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Fecha del gasto</label>
          <input type="date" name="expense_date" class="form-control" required
                 value="<?= htmlspecialchars(date('Y-m-d')) ?>">
        </div>

        <div class="mb-3">
          <label class="form-label">Proyecto (opcional)</label>
          <select name="project_id" class="form-select">
            <option value="">— Sin proyecto —</option>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Forma de pago (opcional)</label>
          <select name="payment_method_id" class="form-select">
            <option value="">— Ninguna —</option>
            <?php
              // Si tienes tabla payment_methods por empresa
              $pm = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
              $pm->execute([$company_id]);
              foreach ($pm->fetchAll(PDO::FETCH_ASSOC) as $m):
            ?>
              <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Comentario (se guardará en inventario y en el gasto)</label>
          <textarea name="comment" class="form-control" rows="3"
            placeholder="Ej. Transferido a gasto el <?= date('Y-m-d') ?> por ajuste/uso interno"></textarea>
        </div>

        <div class="alert alert-info small mb-0">
          Se creará 1 gasto por cada partida seleccionada y la partida de inventario quedará inactiva (baja).
        </div>
      </div>
      <div class="modal-footer">
        <span class="me-auto text-muted">Seleccionados: <strong id="convertSelectedCount">0</strong></span>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-warning">Convertir</button>
      </div>
    </form>
  </div>
</div>


<?php include 'footer.php'; ?>
