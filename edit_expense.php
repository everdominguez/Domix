<?php
// edit_expense.php
require_once 'auth.php';
require_once 'db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ===========================
   1) Validaciones iniciales
   =========================== */
if (!isset($_SESSION['company_id'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        include 'header.php';
        echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
        include 'footer.php';
    }
    exit();
}
$company_id = (int)$_SESSION['company_id'];

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if (!$id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        include 'header.php';
        echo "ID de gasto no especificado.";
        include 'footer.php';
    }
    exit();
}

/* ---------- Helper ---------- */
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

/* ---------- Cargar gasto ---------- */
$stmt = $pdo->prepare("
    SELECT e.*,
           p.name  AS project_name,
           sp.name AS subproject_name
    FROM expenses e
    JOIN projects p  ON e.project_id = p.id
    LEFT JOIN subprojects sp ON sp.id = e.subproject_id
    WHERE e.id = ? AND p.company_id = ?
");
$stmt->execute([$id, $company_id]);
$expense = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$expense) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        include 'header.php';
        echo "<div class='alert alert-warning'>Gasto no encontrado o no pertenece a esta empresa.</div>";
        include 'footer.php';
    }
    exit();
}

/* ---------- Compatibilidad: resolver IDs si solo hay texto ---------- */
if (empty($expense['category_id']) && !empty($expense['category'])) {
    $q = $pdo->prepare("SELECT id FROM expenses_category WHERE company_id=? AND name=?");
    $q->execute([$company_id, $expense['category']]);
    if ($cid = $q->fetchColumn()) $expense['category_id'] = (int)$cid;
}
if (empty($expense['subcategory_id']) && !empty($expense['subcategory']) && !empty($expense['category_id'])) {
    $q = $pdo->prepare("SELECT id FROM expenses_subcategory WHERE company_id=? AND category_id=? AND name=?");
    $q->execute([$company_id, (int)$expense['category_id'], $expense['subcategory']]);
    if ($sid = $q->fetchColumn()) $expense['subcategory_id'] = (int)$sid;
}

/* ===========================
   2) Guardar (POST)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id        = (int)($_POST['project_id'] ?? 0);
    $subproject_raw    = trim($_POST['subproject_id'] ?? '');
    $subproject_id     = ($subproject_raw === '') ? null : (int)$subproject_raw; // '' -> NULL
    $category_id       = (int)($_POST['category_id'] ?? 0);
    $subcategory_id    = (int)($_POST['subcategory_id'] ?? 0);
    $provider          = trim($_POST['provider'] ?? '');
    $invoice_number    = trim($_POST['invoice_number'] ?? '');
    $amount            = (float)($_POST['amount'] ?? 0);
    $expense_date      = $_POST['expense_date'] ?? null;
    $notes             = trim($_POST['notes'] ?? '');
    $import_type       = $_POST['import_type'] ?? 'expense'; // 'expense' | 'inventory'

    // Redirección de regreso (mantiene filtros si viniste de expenses_list)
    $redirectUrl = 'expenses_list.php?project_id=' . $project_id;
    if (!empty($_POST['back'])) {
        $path = parse_url($_POST['back'], PHP_URL_PATH);
        if ($path && basename($path) === 'expenses_list.php') {
            $redirectUrl = $_POST['back'];
        }
    }

    // Métodos de pago
    $payment_method_sel = $_POST['payment_method'] ?? '';
    $custom_payment     = trim($_POST['custom_payment'] ?? '');

    // Validar proyecto
    $chk = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND company_id = ?");
    $chk->execute([$project_id, $company_id]);

    // Validar subproyecto pertenece al proyecto (si viene)
    if ($subproject_id) {
        $chkSp = $pdo->prepare("SELECT id FROM subprojects WHERE id = ? AND project_id = ?");
        $chkSp->execute([$subproject_id, $project_id]);
        if (!$chkSp->fetchColumn()) $subproject_id = null;
    }

    // Validar categoría/subcategoría
    $catName = null; $subName = null;
    if ($category_id > 0) {
        $q = $pdo->prepare("SELECT name FROM expenses_category WHERE id=? AND company_id=?");
        $q->execute([$category_id, $company_id]);
        $catName = $q->fetchColumn();
    }
    if ($subcategory_id > 0 && $category_id > 0) {
        $q = $pdo->prepare("SELECT name FROM expenses_subcategory WHERE id=? AND company_id=? AND category_id=?");
        $q->execute([$subcategory_id, $company_id, $category_id]);
        $subName = $q->fetchColumn();
    }

    if ($chk->fetch() && $catName && ($subName || $subcategory_id === 0)) {
        // Flags de importación
        $prevImported    = (int)($expense['imported_as_expense'] ?? 1);
        $wantExpense     = ($import_type === 'expense') ? 1 : 0;
        $needsConversion = ($prevImported === 1 && $wantExpense === 0); // Gasto -> Inventario

        // Resolver payment_method_id / nombre / custom
        $payment_method_id     = null;
        $payment_method_name   = '';
        $custom_payment_method = null;

        if ($payment_method_sel === 'other') {
            $custom_payment_method = ($custom_payment !== '') ? $custom_payment : null;
        } else {
            $maybeId = (int)$payment_method_sel;
            if ($maybeId > 0) {
                $pmq = $pdo->prepare("SELECT id, name FROM payment_methods WHERE id = ? AND company_id = ?");
                $pmq->execute([$maybeId, $company_id]);
                if ($pmRow = $pmq->fetch(PDO::FETCH_ASSOC)) {
                    $payment_method_id   = (int)$pmRow['id'];
                    $payment_method_name = $pmRow['name'];
                } else {
                    $custom_payment_method = ($custom_payment !== '') ? $custom_payment : null;
                }
            }
        }

        try {
            // ============================
            // Conversión a inventario (si aplica)
            // ============================
            if ($needsConversion) {
                $pdo->beginTransaction();

                // Evitar doble conversión
                $chkInv = $pdo->prepare("SELECT 1 FROM inventory WHERE expense_id = ? LIMIT 1");
                $chkInv->execute([$id]);
                if ($chkInv->fetchColumn()) throw new RuntimeException("El gasto ya tiene partidas en inventario; no se puede convertir de nuevo.");

                // Tomar conceptos DIRECTO de expense_items
                $getItems = $pdo->prepare("
                    SELECT description, unit, quantity, unit_price, iva
                    FROM expense_items
                    WHERE expense_id = ?
                    ORDER BY id
                ");
                $getItems->execute([$id]);
                $concepts = $getItems->fetchAll(PDO::FETCH_ASSOC);
                if (empty($concepts)) throw new RuntimeException("No hay conceptos registrados en expense_items para este gasto.");

                // Columnas disponibles en inventory
                $colsStmt = $pdo->query("DESCRIBE inventory");
                $invCols  = array_map('strtolower', $colsStmt->fetchAll(PDO::FETCH_COLUMN));
                $has = function($col) use ($invCols){ return in_array(strtolower($col), $invCols, true); };

                // INSERT dinámico
                $uuid = $expense['cfdi_uuid'] ?? ($expense['uuid'] ?? null);
                $possibleKeys = [
                    'company_id','project_id','subproject_id',
                    'description','unit','quantity','unit_price',
                    'subtotal','iva','total',
                    'amount','price',
                    'expense_id','uuid','cfdi_uuid',
                    'profit_margin','sale_price','created_at',
                ];
                $insertCols = array_values(array_filter($possibleKeys, $has));
                if (empty($insertCols)) throw new RuntimeException("La tabla inventory no tiene columnas compatibles para insertar.");

                $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
                $columnsSql   = '`' . implode('`,`', $insertCols) . '`';
                $sqlIns = "INSERT INTO inventory ($columnsSql) VALUES ($placeholders)";
                $ins   = $pdo->prepare($sqlIns);

                $project_id_for_inv    = $project_id ?: (int)$expense['project_id'];
                $subproject_id_for_inv = ($subproject_id !== null) ? $subproject_id : ((isset($expense['subproject_id'])) ? (int)$expense['subproject_id'] : null);

                foreach ($concepts as $c) {
                    $desc = trim($c['description'] ?? '');
                    $unit = trim($c['unit'] ?? 'ACT');
                    $qty  = max(0, (float)($c['quantity'] ?? 0));
                    $pu   = (float)($c['unit_price'] ?? 0);
                    $ivaV = (float)($c['iva'] ?? 0);
                    if ($qty <= 0) continue;

                    $subtotal = round($qty * $pu, 2);
                    $total    = round($subtotal + $ivaV, 2);

                    $rowMap = [
                        'company_id'     => $company_id,
                        'project_id'     => $project_id_for_inv,
                        'subproject_id'  => $subproject_id_for_inv,
                        'description'    => $desc,
                        'unit'           => $unit,
                        'quantity'       => $qty,
                        'unit_price'     => $pu,
                        'subtotal'       => $subtotal,
                        'iva'            => $ivaV,
                        'total'          => $total,
                        'amount'         => $total,
                        'price'          => $pu,
                        'expense_id'     => $id,
                        'uuid'           => $uuid,
                        'cfdi_uuid'      => $uuid,
                        'profit_margin'  => null,
                        'sale_price'     => null,
                        'created_at'     => date('Y-m-d H:i:s'),
                    ];
                    $values = [];
                    foreach ($insertCols as $k) $values[] = $rowMap[$k] ?? null;
                    $ins->execute($values);
                }

                // Marcar como inventario y ANULAR el gasto
                $u = $pdo->prepare("UPDATE expenses SET imported_as_expense = 0, active = 0 WHERE id = ?");
                $u->execute([$id]);

                $pdo->commit();
            }

            // UPDATE del gasto
            $upd = $pdo->prepare("
                UPDATE expenses
                   SET project_id            = ?,
                       subproject_id         = ?,
                       category_id           = ?,
                       subcategory_id        = ?,
                       category              = ?,
                       subcategory           = ?,
                       provider              = ?,
                       invoice_number        = ?,
                       amount                = ?,
                       expense_date          = ?,
                       notes                 = ?,
                       imported_as_expense   = ?,
                       payment_method        = ?,
                       custom_payment_method = ?,
                       payment_method_id     = ?
                 WHERE id = ?
            ");
            $upd->execute([
                $project_id ?: null,
                $subproject_id,
                $category_id,
                $subcategory_id ?: null,
                $catName,
                $subName ?: null,
                $provider,
                $invoice_number ?: null,
                $amount,
                $expense_date,
                $notes,
                $wantExpense,
                $payment_method_name,
                $custom_payment_method,
                $payment_method_id,
                $id
            ]);

            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✔️ Gasto actualizado correctamente.'];
            header("Location: " . $redirectUrl);
            exit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Error al convertir/actualizar: ' . h($e->getMessage())];
            header("Location: " . $redirectUrl);
            exit();
        }

    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Proyecto / Categoría / Subcategoría inválidos.'];
        header("Location: " . $redirectUrl);
        exit();
    }
} else {
    include 'header.php';
}

/* ===========================
   3) Valores actuales para vista
   =========================== */
$currentCategoryId    = isset($expense['category_id']) ? (int)$expense['category_id'] : 0;
$currentSubcategoryId = isset($expense['subcategory_id']) ? (int)$expense['subcategory_id'] : 0;
$currentSubprojectId  = isset($expense['subproject_id']) ? (int)$expense['subproject_id'] : 0;

// Catálogo de métodos de pago (para el select)
$methodStmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
$methodStmt->execute([$company_id]);
$methods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);
$currentPaymentName = $expense['custom_payment_method'] ?: $expense['payment_method'];
$currentPaymentId   = (int)($expense['payment_method_id'] ?? 0);
$showAsOther = !empty($expense['custom_payment_method']);

?>
<?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !headers_sent()): ?>
<h2 class="mb-4">✏️ Editar Gasto</h2>

<div class="card shadow mb-4">
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="id" value="<?= (int)$expense['id'] ?>">
      <input type="hidden" name="back" value="<?= h($_GET['back'] ?? ($_SERVER['HTTP_REFERER'] ?? '')) ?>">

      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>Proyecto</span>
            <span class="d-flex gap-2">
              <button type="button"
                      class="btn btn-sm btn-outline-secondary x-pick-entity"
                      data-entity="project"
                      data-target-id="#project_id"
                      data-target-text="#project_name"
                      data-title="Selecciona un proyecto">Ver más</button>
              <button type="button"
                      class="btn btn-sm btn-outline-primary x-add-entity"
                      data-entity="project"
                      data-target-id="#project_id"
                      data-target-text="#project_name"
                      data-title="Nuevo Proyecto"
                      data-placeholder="Nombre del proyecto">➕ Nuevo</button>
            </span>
          </label>
          <input type="text" id="project_name" class="form-control" placeholder="Escribe para buscar proyecto"
                 value="<?= h($expense['project_name'] ?? '') ?>">
          <input type="hidden" id="project_id" name="project_id" value="<?= (int)$expense['project_id'] ?>">
        </div>

        <div class="col-md-6">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>Subproyecto</span>
            <span class="d-flex gap-2">
              <button type="button"
                      class="btn btn-sm btn-outline-secondary x-pick-entity"
                      data-entity="subproject"
                      data-parent="#project_id"
                      data-target-id="#subproject_id"
                      data-target-text="#subproject_name"
                      data-title="Selecciona un subproyecto">Ver más</button>
              <button type="button"
                      class="btn btn-sm btn-outline-primary x-add-entity"
                      data-entity="subproject"
                      data-parent="#project_id"
                      data-target-id="#subproject_id"
                      data-target-text="#subproject_name"
                      data-title="Nuevo Subproyecto"
                      data-placeholder="Nombre del subproyecto">➕ Nuevo</button>
            </span>
          </label>
          <input type="text" id="subproject_name" class="form-control" placeholder="Escribe para buscar subproyecto"
                 value="<?= h($expense['subproject_name'] ?? '') ?>">
          <input type="hidden" id="subproject_id" name="subproject_id" value="<?= $currentSubprojectId ?: '' ?>">
          <div class="form-text">Deja vacío para “Sin subproyecto”.</div>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>Categoría</span>
            <span class="d-flex gap-2">
              <button type="button"
                      class="btn btn-sm btn-outline-secondary x-pick-entity"
                      data-entity="category"
                      data-target-id="#category_id"
                      data-target-text="#category_name"
                      data-title="Selecciona una categoría">Ver más</button>
              <button type="button"
                      class="btn btn-sm btn-outline-primary x-add-entity"
                      data-entity="category"
                      data-target-id="#category_id"
                      data-target-text="#category_name"
                      data-title="Nueva Categoría"
                      data-placeholder="Nombre de la categoría">➕ Nuevo</button>
            </span>
          </label>
          <input type="text" id="category_name" class="form-control" placeholder="Escribe para buscar categoría"
                 value="<?= h($expense['category'] ?? '') ?>">
          <input type="hidden" id="category_id" name="category_id" value="<?= $currentCategoryId ?: '' ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>Subcategoría</span>
            <span class="d-flex gap-2">
              <button type="button"
                      class="btn btn-sm btn-outline-secondary x-pick-entity"
                      data-entity="subcategory"
                      data-parent="#category_id"
                      data-target-id="#subcategory_id"
                      data-target-text="#subcategory_name"
                      data-title="Selecciona una subcategoría">Ver más</button>
              <button type="button"
                      class="btn btn-sm btn-outline-primary x-add-entity"
                      data-entity="subcategory"
                      data-parent="#category_id"
                      data-target-id="#subcategory_id"
                      data-target-text="#subcategory_name"
                      data-title="Nueva Subcategoría"
                      data-placeholder="Nombre de la subcategoría">➕ Nuevo</button>
            </span>
          </label>
          <input type="text" id="subcategory_name" class="form-control" placeholder="Escribe para buscar subcategoría"
                 value="<?= h($expense['subcategory'] ?? '') ?>">
          <input type="hidden" id="subcategory_id" name="subcategory_id" value="<?= $currentSubcategoryId ?: '' ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label d-flex justify-content-between align-items-center">
            <span>Proveedor</span>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary x-pick-entity"
                    data-entity="provider"
                    data-target-text="#provider_name"
                    data-title="Selecciona un proveedor">Ver más</button>
          </label>
          <input type="text" id="provider_name" name="provider" class="form-control"
                 placeholder="Escribe para buscar proveedor"
                 value="<?= h($expense['provider_name'] ?: $expense['provider']) ?>">
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Folio Factura</label>
          <input type="text" name="invoice_number" class="form-control" value="<?= h($expense['invoice_number']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Monto</label>
          <input type="text" name="amount" class="form-control" value="<?= h(number_format((float)$expense['amount'], 2, '.', '')) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Fecha</label>
          <input type="date" name="expense_date" class="form-control" value="<?= h($expense['expense_date']) ?>" required>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Forma de pago</label>
          <select name="payment_method" id="payment_method" class="form-select">
            <?php foreach ($methods as $m): ?>
              <?php
                $selected = '';
                if (!$showAsOther) {
                  if ((int)$currentPaymentId === (int)$m['id']) $selected = 'selected';
                  else if ($currentPaymentId <= 0 && $currentPaymentName === $m['name']) $selected = 'selected';
                }
              ?>
              <option value="<?= (int)$m['id'] ?>" <?= $selected ?>><?= h($m['name']) ?></option>
            <?php endforeach; ?>
            <option value="other" <?= $showAsOther ? 'selected' : '' ?>>Otro</option>
          </select>

          <input
            type="text"
            name="custom_payment"
            id="custom_payment"
            class="form-control mt-2"
            placeholder="Especifica otra forma de pago"
            value="<?= $showAsOther ? h($currentPaymentName) : '' ?>"
            style="display: <?= $showAsOther ? 'block' : 'none' ?>;">
        </div>

        <div class="col-md-6">
          <label class="form-label">Tipo de importación</label>
          <?php $importType = ((int)($expense['imported_as_expense'] ?? 1) === 1) ? 'expense' : 'inventory'; ?>
          <select name="import_type" class="form-select" id="import_type">
            <option value="expense"   <?= $importType==='expense'   ? 'selected' : '' ?>>Importar como gasto</option>
            <option value="inventory" <?= $importType==='inventory' ? 'selected' : '' ?>>Importar conceptos al inventario</option>
          </select>
          <div class="form-text">
            Si cambias a “Inventario”, se crearán partidas con los conceptos del CFDI, se marcará el gasto como inventario y <strong>se anulará</strong> (active = 0).
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Notas</label>
        <textarea name="notes" class="form-control" rows="3"><?= h($expense['notes']) ?></textarea>
      </div>

      <button type="submit" class="btn btn-primary">Actualizar Gasto</button>
      <a href="expenses_list.php" class="btn btn-outline-secondary ms-2">Cancelar</a>
    </form>
  </div>
</div>

<!-- Modal: Crear entidad -->
<div class="modal fade" id="addEntityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="addEntityForm">
        <div class="modal-header">
          <h5 class="modal-title" id="addEntityTitle">Nuevo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="ae_entity">
          <input type="hidden" id="ae_target_id">
          <input type="hidden" id="ae_target_text">
          <input type="hidden" id="ae_parent">
          <div class="mb-3">
            <label class="form-label" id="ae_label">Nombre</label>
            <input type="text" id="ae_name" class="form-control" required maxlength="150">
          </div>
          <div id="ae_error" class="text-danger small" style="display:none;"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Crear</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Selector (Ver más) -->
<div class="modal fade" id="pickEntityModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="pickEntityTitle">Seleccionar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3">
          <input type="text" id="pickSearch" class="form-control" placeholder="Buscar...">
          <button class="btn btn-outline-secondary" id="pickSearchBtn">Buscar</button>
        </div>
        <div class="list-group" id="pickList" style="max-height: 420px; overflow: auto;"></div>
        <nav class="mt-3 d-flex justify-content-between align-items-center">
          <button class="btn btn-sm btn-outline-secondary" id="pickPrev">« Anterior</button>
          <small class="text-muted" id="pickMeta"></small>
          <button class="btn btn-sm btn-outline-secondary" id="pickNext">Siguiente »</button>
        </nav>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- jQuery + jQuery UI (autocomplete) -->
<link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>

<script>
// Toggle custom payment
const method = document.getElementById("payment_method");
const custom = document.getElementById("custom_payment");
function toggleCustom() {
  if (!method || !custom) return;
  custom.style.display = (method.value === "other") ? "block" : "none";
  if (method.value !== "other") custom.value = "";
}
method?.addEventListener("change", toggleCustom);
toggleCustom();

/* ---- Autocomplete helper ---- */
function initAutoText({input, hidden, type, parentHidden=null}) {
  const $input  = $(input);
  const $hidden = $(hidden);
  $input.on('input', () => $hidden.val('')); // limpiar id si se edita

  $input.autocomplete({
    minLength: 1,
    source: (req, res) => {
      const qs = { type, term: req.term };
      if (parentHidden && $(parentHidden).val()) qs.parent_id = $(parentHidden).val();
      $.getJSON('search_autocomplete.php', qs, (data)=>{
        const items = (data.items || []).map(x=>({label:x.label, value:x.value, id:x.id}));
        res(items);
      });
    },
    select: (e, ui) => {
      $input.val(ui.item.value);
      $hidden.val(ui.item.id || '');
      if (input === '#project_name') { $('#subproject_name').val(''); $('#subproject_id').val(''); }
      if (input === '#category_name') { $('#subcategory_name').val(''); $('#subcategory_id').val(''); }
      return false;
    }
  });
}

/* ---- Instancias de autocompletado ---- */
initAutoText({input:'#project_name',    hidden:'#project_id',    type:'project'});
initAutoText({input:'#subproject_name', hidden:'#subproject_id', type:'subproject', parentHidden:'#project_id'});
initAutoText({input:'#category_name',   hidden:'#category_id',   type:'category'});
initAutoText({input:'#subcategory_name',hidden:'#subcategory_id',type:'subcategory', parentHidden:'#category_id'});
// Proveedor: solo texto
$('#provider_name').autocomplete({
  minLength: 1,
  source: (req, res)=> $.getJSON('search_autocomplete.php',{type:'provider', term:req.term}, (d)=>{
    res((d.items||[]).map(x=>x.label));
  })
});

/* ---------- Modal "nuevo" reutilizable (para inputs) ---------- */
(function(){
  document.querySelectorAll('.x-add-entity').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      document.getElementById('ae_entity').value      = btn.dataset.entity;
      document.getElementById('ae_target_id').value   = btn.dataset.targetId;
      document.getElementById('ae_target_text').value = btn.dataset.targetText;
      document.getElementById('ae_parent').value      = btn.dataset.parent || '';
      document.getElementById('addEntityTitle').textContent = btn.dataset.title || 'Nuevo';
      document.getElementById('ae_label').textContent       = btn.dataset.title || 'Nombre';
      const nameInput = document.getElementById('ae_name');
      nameInput.value = '';
      nameInput.placeholder = btn.dataset.placeholder || 'Nombre';
      document.getElementById('ae_error').style.display = 'none';
      new bootstrap.Modal(document.getElementById('addEntityModal')).show();
      setTimeout(()=>nameInput.focus(), 150);
    });
  });

  document.getElementById('addEntityForm').addEventListener('submit', async (e)=>{
    e.preventDefault();
    const entity   = document.getElementById('ae_entity').value;
    const targetId = document.getElementById('ae_target_id').value;
    const targetTx = document.getElementById('ae_target_text').value;
    const parentSel= document.getElementById('ae_parent').value;
    const name     = (document.getElementById('ae_name').value || '').trim();
    const err      = document.getElementById('ae_error');

    err.style.display = 'none';
    if (!name) { err.textContent = 'Escribe un nombre.'; err.style.display = 'block'; return; }

    let parent_id = null;
    if (parentSel) {
      const parentEl = document.querySelector(parentSel);
      parent_id = parentEl ? parentEl.value : null;
      if (!parent_id) { err.textContent = 'Selecciona primero el elemento padre.'; err.style.display = 'block'; return; }
    }

    try{
      const r = await fetch('create_entity.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({ entity, name, parent_id })
      });
      const data = await r.json();
      if (!data.ok) { err.textContent = data.error || 'No se pudo crear.'; err.style.display = 'block'; return; }

      const hid = document.querySelector(targetId);
      const txt = document.querySelector(targetTx);
      if (hid) hid.value = String(data.id);
      if (txt) txt.value = data.name;

      if (entity === 'project')  { $('#subproject_name').val(''); $('#subproject_id').val(''); }
      if (entity === 'category') { $('#subcategory_name').val(''); $('#subcategory_id').val(''); }

      bootstrap.Modal.getInstance(document.getElementById('addEntityModal'))?.hide();
    }catch{
      err.textContent = 'Error de red. Intenta de nuevo.'; err.style.display = 'block';
    }
  });
})();

/* ---------- Picker (Ver más) ---------- */
(function(){
  let cfg = {
    entity: null,
    parentSel: null,
    targetId: null,
    targetText: null,
    page: 1,
    limit: 20,
    term: ''
  };

  const $list = $('#pickList');
  const $meta = $('#pickMeta');

  function loadPage(page){
    cfg.page = page;
    const params = new URLSearchParams({
      type: cfg.entity,
      term: cfg.term,
      page: cfg.page,
      limit: cfg.limit
    });
    if (cfg.parentSel) {
      const pid = $(cfg.parentSel).val();
      if (!pid) { $list.html('<div class="text-muted p-3">Selecciona primero el elemento padre.</div>'); return; }
      params.set('parent_id', pid);
    }

    fetch('search_autocomplete.php?' + params.toString(), {credentials:'same-origin'})
      .then(r => r.json())
      .then(data => {
        const items = data.items || [];
        const total = data.total || 0;
        const from = total ? ((cfg.page-1)*cfg.limit + 1) : 0;
        const to   = Math.min(cfg.page*cfg.limit, total);

        $list.empty();
        if (!items.length) {
          $list.html('<div class="text-muted p-3">Sin resultados.</div>');
        } else {
          items.forEach(it => {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = 'list-group-item list-group-item-action';
            row.textContent = it.label;
            row.addEventListener('click', ()=>{
              if (cfg.targetId)   $(cfg.targetId).val(it.id || '');
              if (cfg.targetText) $(cfg.targetText).val(it.label || '');
              if (cfg.entity === 'project'){ $('#subproject_name').val(''); $('#subproject_id').val(''); }
              if (cfg.entity === 'category'){ $('#subcategory_name').val(''); $('#subcategory_id').val(''); }
              bootstrap.Modal.getInstance(document.getElementById('pickEntityModal'))?.hide();
            });
            $list.append(row);
          });
        }
        $meta.text(total ? `Mostrando ${from}–${to} de ${total}` : '0 resultados');
        $('#pickPrev').prop('disabled', cfg.page<=1);
        $('#pickNext').prop('disabled', cfg.page*cfg.limit>=total);
      })
      .catch(()=>{ $list.html('<div class="text-danger p-3">Error al cargar.</div>'); });
  }

  // Abrir modal
  document.querySelectorAll('.x-pick-entity').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      cfg.entity     = btn.dataset.entity;
      cfg.parentSel  = btn.dataset.parent || null;
      cfg.targetId   = btn.dataset.targetId || null;
      cfg.targetText = btn.dataset.targetText || null;
      cfg.page = 1; cfg.term = '';
      $('#pickSearch').val('');
      $('#pickEntityTitle').text(btn.dataset.title || 'Seleccionar');
      new bootstrap.Modal(document.getElementById('pickEntityModal')).show();
      loadPage(1);
    });
  });

  // Controles
  $('#pickSearchBtn').on('click', ()=>{ cfg.term = $('#pickSearch').val().trim(); loadPage(1); });
  $('#pickSearch').on('keydown', (e)=>{ if (e.key === 'Enter'){ e.preventDefault(); $('#pickSearchBtn').click(); }});
  $('#pickPrev').on('click', ()=> loadPage(Math.max(1, cfg.page-1)));
  $('#pickNext').on('click', ()=> loadPage(cfg.page+1));
})();
</script>

<?php include 'footer.php'; ?>
<?php endif; ?>
