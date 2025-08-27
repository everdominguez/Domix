<?php
// expenses_list.php
session_start();
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
  echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
  include 'footer.php'; exit;
}
$company_id = (int)$_SESSION['company_id'];

/* ===== FLASH (una sola vez) ===== */
if (!empty($_SESSION['flash'])) {
  $f = $_SESSION['flash'];
  unset($_SESSION['flash']);
  echo '<div class="alert alert-'.($f['type']==='success'?'success':'danger').' alert-dismissible fade show" role="alert">'
        . htmlspecialchars($f['msg'], ENT_QUOTES, 'UTF-8') .
       '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
}

/* ===========================
   Catálogos para filtros
   =========================== */
$projects = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$projects->execute([$company_id]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);

$paymentMethods = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
$paymentMethods->execute([$company_id]);
$paymentMethods = $paymentMethods->fetchAll(PDO::FETCH_ASSOC);

/* Categorías / Subcategorías desde tablas nuevas */
$cats = $pdo->prepare("
  SELECT name
  FROM expenses_category
  WHERE company_id = ?
  ORDER BY name
");
$cats->execute([$company_id]);
$categories = $cats->fetchAll(PDO::FETCH_COLUMN);

$subs = $pdo->prepare("
  SELECT name
  FROM expenses_subcategory
  WHERE company_id = ?
  ORDER BY name
");
$subs->execute([$company_id]);
$subcategories = $subs->fetchAll(PDO::FETCH_COLUMN);

/* ===========================
   Parámetros (filtros + orden + paginación)
   =========================== */
$q              = trim($_GET['q'] ?? '');
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');
$project_id     = (int)($_GET['project_id'] ?? 0);
$subproject_id  = (int)($_GET['subproject_id'] ?? 0);
$category       = trim($_GET['category'] ?? '');
$subcategory    = trim($_GET['subcategory'] ?? '');
$payment_id     = (int)($_GET['payment_method_id'] ?? 0);
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

/* ===========================
   Ordenamiento seguro
   =========================== */
$sort  = $_GET['sort']  ?? 'fecha';
$order = strtolower($_GET['order'] ?? 'desc');
if (!in_array($order, ['asc','desc'])) $order = 'desc';

$sortMapSingle = [
  'fecha'        => 'e.expense_date',
  'categoria'    => 'e.category',
  'subcategoria' => 'e.subcategory',
  'forma_pago'   => "COALESCE(NULLIF(e.custom_payment_method,''), NULLIF(e.payment_method,''))",
  'proveedor'    => "COALESCE(NULLIF(e.provider_name,''), NULLIF(e.provider,''))",
  'monto'        => 'e.amount',
  'uuid'         => 'e.cfdi_uuid',
];
$sortIsProjectCombo = ($sort === 'proyecto');
if (!array_key_exists($sort, $sortMapSingle) && !$sortIsProjectCombo) {
  $sort = 'fecha';
}

/* ===========================
   WHERE dinámico
   =========================== */
$where  = ["e.company_id = ?"];
$params = [$company_id];

if ($date_from !== '') { $where[] = "e.expense_date >= ?"; $params[] = $date_from; }
if ($date_to   !== '') { $where[] = "e.expense_date <= ?"; $params[] = $date_to; }
if ($project_id> 0)    { $where[] = "e.project_id = ?";    $params[] = $project_id; }
if ($subproject_id>0)  { $where[] = "e.subproject_id = ?"; $params[] = $subproject_id; }
if ($category !== '')  { $where[] = "e.category = ?";      $params[] = $category; }
if ($subcategory!==''){ $where[] = "e.subcategory = ?";    $params[] = $subcategory; }

/* Filtro por forma de pago (usa nombre) */
if ($payment_id > 0) {
  $pmNameStmt = $pdo->prepare("SELECT name FROM payment_methods WHERE id = ? AND company_id = ?");
  $pmNameStmt->execute([$payment_id, $company_id]);
  $pmName = $pmNameStmt->fetchColumn();
  if ($pmName) {
    $where[] = "(e.payment_method = ? OR e.custom_payment_method = ?)";
    $params[] = $pmName;
    $params[] = $pmName;
  } else {
    $where[] = "1=0";
  }
}

/* Búsqueda libre */
if ($q !== '') {
  $where[] = "(e.notes LIKE ? OR e.cfdi_uuid LIKE ? OR pr.name LIKE ? OR e.provider_name LIKE ? OR e.provider LIKE ?)";
  $needle  = "%$q%";
  $params[] = $needle; // notes
  $params[] = $needle; // cfdi_uuid
  $params[] = $needle; // project name
  $params[] = $needle; // provider_name
  $params[] = $needle; // provider
}
$whereSql = implode(' AND ', $where);

/* ===========================
   ORDER BY dinámico
   =========================== */
if ($sortIsProjectCombo) {
  $orderBy = "pr.name $order, sp.name $order, e.expense_date DESC, e.id DESC";
} else {
  $expr = $sortMapSingle[$sort];
  $orderBy = "$expr $order, e.id DESC";
}

/* ===========================
   Conteo y suma
   =========================== */
$sqlCount = "
  SELECT 
    COUNT(*) AS total_rows,
    COALESCE(SUM(e.total),0) AS total_amount,
    COALESCE(SUM(CASE WHEN COALESCE(e.is_credit_note,0)=1 THEN 0 ELSE e.total END),0) AS total_amount_no_nc
  FROM expenses e
  LEFT JOIN projects pr     ON pr.id = e.project_id
  LEFT JOIN subprojects sp  ON sp.id = e.subproject_id
  WHERE $whereSql
";
$st = $pdo->prepare($sqlCount);
$st->execute($params);
$meta = $st->fetch(PDO::FETCH_ASSOC);

$totalRows       = (int)$meta['total_rows'];
$totalAmount     = (float)$meta['total_amount'];        // incluye todo
$totalAmountNoNC = (float)$meta['total_amount_no_nc'];  // excluye notas de crédito
$totalPages      = max(1, (int)ceil($totalRows / $perPage));

/* ===========================
   Datos página
   =========================== */
$sql = "
  SELECT e.id, e.expense_date, e.amount, e.total, e.category, e.subcategory, e.notes,
         e.cfdi_uuid AS uuid, e.active, e.is_credit_note,
         pr.name AS project_name,
         sp.name AS subproject_name,
         COALESCE(NULLIF(e.custom_payment_method,''), NULLIF(e.payment_method,'')) AS payment_name,
         COALESCE(NULLIF(e.provider_name,''), NULLIF(e.provider,'')) AS provider_name
  FROM expenses e
  LEFT JOIN projects pr     ON pr.id = e.project_id
  LEFT JOIN subprojects sp  ON sp.id = e.subproject_id
  WHERE $whereSql
  ORDER BY $orderBy
  LIMIT $perPage OFFSET $offset
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* ===========================
   Helper link sort
   =========================== */
function sort_link($colKey, $label, $currentSort, $currentOrder, $extraQuery = []) {
  $newOrder = ($currentSort === $colKey && strtolower($currentOrder) === 'asc') ? 'desc' : 'asc';
  $icon = '';
  if ($currentSort === $colKey) $icon = (strtolower($currentOrder) === 'asc') ? ' ▲' : ' ▼';
  $qs = array_merge($_GET, ['sort' => $colKey, 'order' => $newOrder, 'page' => 1]);
  $href = 'expenses_list.php?' . http_build_query($qs);
  return "<a class=\"text-decoration-none\" href=\"$href\">$label$icon</a>";
}
?>

<h2 class="mb-3">📋 Listado de Gastos</h2>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2">
      <div class="col-md-3">
        <label class="form-label">Rango de fechas</label>
        <div class="d-flex gap-2">
          <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control">
          <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control">
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label">Proyecto</label>
        <select name="project_id" id="f_project" class="form-select">
          <option value="0">Todos</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $project_id==$p['id']?'selected':''; ?>>
              <?= htmlspecialchars($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Subproyecto</label>
        <select name="subproject_id" id="f_subproject" class="form-select">
          <option value="0">Todos</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Forma de pago</label>
        <select name="payment_method_id" class="form-select">
          <option value="0">Todas</option>
          <?php foreach ($paymentMethods as $pm): ?>
            <option value="<?= $pm['id'] ?>" <?= $payment_id==$pm['id']?'selected':''; ?>>
              <?= htmlspecialchars($pm['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Categoría</label>
        <select name="category" id="f_category" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $category===$c?'selected':''; ?>>
              <?= htmlspecialchars($c) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="form-label">Subcategoría</label>
        <select name="subcategory" id="f_subcategory" class="form-select">
          <option value="">Todas</option>
          <?php foreach ($subcategories as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>" <?= $subcategory===$s?'selected':''; ?>>
              <?= htmlspecialchars($s) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Buscar (nota, UUID, proyecto, proveedor)</label>
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Ej. gasolina, 7E5D...">
      </div>

      <div class="col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-primary w-100">Filtrar</button>
        <a href="expenses_list.php" class="btn btn-outline-secondary w-100">Limpiar</a>
      </div>
    </form>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div class="text-muted">
  Resultados: <strong><?= number_format($totalRows) ?></strong> &middot;
  Total mostrado: <strong>$<?= number_format($totalAmount, 2) ?></strong> &middot;
  Total sin notas de crédito: <strong>$<?= number_format($totalAmountNoNC, 2) ?></strong>
</div>

  <div>
    <a href="expenses.php" class="btn btn-sm btn-outline-primary">➕ Nuevo gasto</a>
    <a href="upload_xml.php" class="btn btn-sm btn-outline-secondary">⬆️ Subir XML</a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th style="width:90px"><?= sort_link('fecha', 'Fecha', $sort, $order) ?></th>
          <th><?= sort_link('proyecto', 'Proyecto / Subproyecto', $sort, $order) ?></th>
          <th><?= sort_link('categoria', 'Categoría', $sort, $order) ?></th>
          <th><?= sort_link('subcategoria', 'Subcategoría', $sort, $order) ?></th>
          <th><?= sort_link('forma_pago', 'Forma de pago', $sort, $order) ?></th>
          <th><?= sort_link('proveedor', 'Proveedor', $sort, $order) ?></th>
          <th class="text-end" style="width:140px"><?= sort_link('monto', 'Monto', $sort, $order) ?></th>
          <th style="width:90px" class="text-end">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="text-center text-muted p-4">Sin resultados.</td></tr>
        <?php else: foreach ($rows as $r): ?>
  <tr class="<?= $r['is_credit_note'] ? 'table-danger' : '' ?>">
            <td><?= htmlspecialchars($r['expense_date']) ?></td>
            <td>
              <?php $sp = trim((string)($r['subproject_name'] ?? '')); ?>
              <div class="fw-semibold"><?= htmlspecialchars($r['project_name'] ?? '—') ?></div>
              <?php if ($sp !== ''): ?>
                <div class="text-muted small"><?= htmlspecialchars($sp) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['category'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['subcategory'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['payment_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['provider_name'] ?? '—') ?></td>
            <td class="text-end">$<?= number_format((float)$r['total'], 2) ?></td>
            <td class="text-end text-nowrap">
              <div class="btn-group btn-group-sm" role="group" aria-label="Acciones">
                <button
                  class="btn btn-outline-secondary btn-compact"
                  data-bs-toggle="modal"
                  data-bs-target="#viewModal"
                  data-json='<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>'>
                  Ver
                </button>
                <a class="btn btn-primary btn-compact"
                   href="edit_expense.php?id=<?= (int)$r['id'] ?>&back=<?= urlencode($_SERVER['REQUEST_URI']) ?>">
                  Editar
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination">
    <?php
      $paramsGet = $_GET; unset($paramsGet['page']);
      $base = 'expenses_list.php?' . http_build_query($paramsGet);
      $prev = max(1, $page - 1);
      $next = min($totalPages, $page + 1);
    ?>
    <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>&page=<?= $prev ?>">«</a></li>
    <?php for ($p=1; $p<=$totalPages; $p++): ?>
      <li class="page-item <?= $p==$page?'active':'' ?>"><a class="page-link" href="<?= $base ?>&page=<?= $p ?>"><?= $p ?></a></li>
    <?php endfor; ?>
    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= $base ?>&page=<?= $next ?>">»</a></li>
  </ul>
</nav>
<?php endif; ?>

<!-- Modal Ver (con conceptos) -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalle del gasto</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">Fecha</dt><dd class="col-sm-9" id="v-date">—</dd>
          <dt class="col-sm-3">Proyecto</dt><dd class="col-sm-9" id="v-project">—</dd>
          <dt class="col-sm-3">Subproyecto</dt><dd class="col-sm-9" id="v-subproject">—</dd>
          <dt class="col-sm-3">Categoría</dt><dd class="col-sm-9" id="v-cat">—</dd>
          <dt class="col-sm-3">Subcategoría</dt><dd class="col-sm-9" id="v-subcat">—</dd>
          <dt class="col-sm-3">Forma de pago</dt><dd class="col-sm-9" id="v-pay">—</dd>
          <dt class="col-sm-3">Proveedor</dt><dd class="col-sm-9" id="v-provider">—</dd>
          <dt class="col-sm-3">UUID</dt><dd class="col-sm-9" id="v-uuid">—</dd>
          <dt class="col-sm-3">Monto</dt><dd class="col-sm-9" id="v-amount">—</dd>
          <dt class="col-sm-3">Notas</dt><dd class="col-sm-9" id="v-notes">—</dd>
        </dl>

        <hr class="my-3">

        <div class="d-flex align-items-center mb-2">
          <h6 class="mb-0">Conceptos</h6>
          <small class="ms-2 text-muted" id="conceptsSource"></small>
        </div>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th style="min-width: 90px;">Unidad</th>
                <th>Descripción</th>
                <th class="text-end">Cantidad</th>
                <th class="text-end">P. Unitario</th>
                <th class="text-end">Subtotal</th>
                <th class="text-end">IVA</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody id="conceptsTable"></tbody>
            <tfoot>
              <tr class="table-light">
                <th colspan="2" class="text-end">Totales</th>
                <th class="text-end" id="t_qty">0</th>
                <th></th>
                <th class="text-end" id="t_sub">0</th>
                <th class="text-end" id="t_iva">0</th>
                <th class="text-end" id="t_tot">0</th>
              </tr>
            </tfoot>
          </table>
        </div>

        <div id="conceptsEmpty" class="text-muted small" style="display:none;">
          Este gasto no tiene conceptos asociados.
        </div>
      </div>
      <div class="modal-footer">
        <a id="v-edit" href="#" class="btn btn-primary">Editar</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Cargar subproyectos en filtros
  const fProject = document.getElementById('f_project');
  const fSub     = document.getElementById('f_subproject');
  if (fProject) {
    function loadSubprojects(pid, selected=<?= $subproject_id ?: 0 ?>) {
      fSub.innerHTML = '<option value="0">Cargando...</option>';
      if (!pid || pid === '0') { fSub.innerHTML = '<option value="0">Todos</option>'; return; }
      fetch('get_subprojects.php?project_id='+encodeURIComponent(pid))
        .then(r => r.json())
        .then(data => {
          fSub.innerHTML = '<option value="0">Todos</option>';
          data.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id; opt.textContent = s.name;
            if (String(selected) === String(s.id)) opt.selected = true;
            fSub.appendChild(opt);
          });
        })
        .catch(() => { fSub.innerHTML = '<option value="0">Todos</option>'; });
    }
    loadSubprojects(fProject.value);
    fProject.addEventListener('change', e => loadSubprojects(e.target.value, 0));
  }

  function money(n){
    if (n === null || n === undefined || isNaN(n)) return '—';
    return new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
  }
  function number(n){
    if (n === null || n === undefined || isNaN(n)) return '0';
    return new Intl.NumberFormat('es-MX',{maximumFractionDigits:2}).format(Number(n));
  }

  // Modal ver: rellenar y cargar conceptos
  const viewModal = document.getElementById('viewModal');
  viewModal?.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    const row = JSON.parse(btn.getAttribute('data-json'));

    viewModal.querySelector('#v-date').textContent       = row.expense_date || '—';
    viewModal.querySelector('#v-project').textContent    = row.project_name || '—';
    viewModal.querySelector('#v-subproject').textContent = row.subproject_name || '—';
    viewModal.querySelector('#v-cat').textContent        = row.category || '—';
    viewModal.querySelector('#v-subcat').textContent     = row.subcategory || '—';
    viewModal.querySelector('#v-pay').textContent        = row.payment_name || '—';
    viewModal.querySelector('#v-provider').textContent   = row.provider_name || '—';
    viewModal.querySelector('#v-uuid').textContent       = row.uuid || '—';
    viewModal.querySelector('#v-amount').textContent     = money(parseFloat(row.amount||0));
    viewModal.querySelector('#v-notes').textContent      = row.notes || '—';
    viewModal.querySelector('#v-edit').href              = 'edit_expense.php?id=' + row.id + '&back=' + encodeURIComponent(window.location.search ? ('expenses_list.php' + window.location.search) : '<?= basename($_SERVER["SCRIPT_NAME"]) ?>');

    document.getElementById('conceptsTable').innerHTML = '';
    document.getElementById('conceptsSource').textContent = '';
    document.getElementById('t_qty').textContent = '0';
    document.getElementById('t_sub').textContent = '0';
    document.getElementById('t_iva').textContent = '0';
    document.getElementById('t_tot').textContent = '0';
    document.getElementById('conceptsEmpty').style.display = 'none';

    fetch('get_expense_details.php?id=' + encodeURIComponent(row.id), {credentials:'same-origin'})
      .then(r => r.json())
      .then(data => {
        if (!data.ok) { document.getElementById('conceptsEmpty').style.display = 'block'; return; }

        const src = data.concepts_source;
        document.getElementById('conceptsSource').textContent =
          src === 'inventory' ? '(desde inventario)' :
          src === 'xml_concepts' ? '(desde CFDI)' : '';

        const tbody = document.getElementById('conceptsTable');
        let tQty = 0, tSub = 0, tIva = 0, tTot = 0;

        if (data.concepts && data.concepts.length) {
          data.concepts.forEach(c => {
            const qty = Number(c.quantity || 0);
            const pu  = Number(c.unit_price || 0);
            const sub = (c.subtotal !== null && c.subtotal !== undefined) ? Number(c.subtotal) : (qty * pu);
            const iva = Number(c.iva || 0);
            const tot = (c.total !== null && c.total !== undefined) ? Number(c.total) : (sub + iva);

            tQty += qty; tSub += sub; tIva += iva; tTot += tot;

            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td>${c.unit ? c.unit : ''}</td>
              <td>${c.description ? c.description : ''}</td>
              <td class="text-end">${number(qty)}</td>
              <td class="text-end">${money(pu)}</td>
              <td class="text-end">${money(sub)}</td>
              <td class="text-end">${money(iva)}</td>
              <td class="text-end">${money(tot)}</td>
            `;
            tbody.appendChild(tr);
          });
        } else {
          document.getElementById('conceptsEmpty').style.display = 'block';
        }

        document.getElementById('t_qty').textContent = number(tQty);
        document.getElementById('t_sub').textContent = money(tSub);
        document.getElementById('t_iva').textContent = money(tIva);
        document.getElementById('t_tot').textContent = money(tTot);
      })
      .catch(() => {
        document.getElementById('conceptsEmpty').style.display = 'block';
      });
  });
})();
</script>

<?php include 'footer.php'; ?>
