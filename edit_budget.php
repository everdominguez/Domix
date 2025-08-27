<?php
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    include 'header.php';
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit();
}
$company_id = (int)$_SESSION['company_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ==========================
// 1) Cargar presupuesto
// ==========================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    include 'header.php';
    echo "<div class='alert alert-warning'>ID de presupuesto inválido.</div>";
    include 'footer.php';
    exit();
}

// Trae el budget y valida empresa
$stmt = $pdo->prepare("
    SELECT b.*, p.name AS project_name
    FROM budgets b
    JOIN projects p ON p.id = b.project_id AND p.company_id = b.company_id
    WHERE b.id = ? AND b.company_id = ?
    LIMIT 1
");
$stmt->execute([$id, $company_id]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$budget) {
    include 'header.php';
    echo "<div class='alert alert-warning'>Presupuesto no encontrado.</div>";
    include 'footer.php';
    exit();
}

// ==========================
// 2) Guardar cambios (POST)
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id     = (int)($_POST['project_id'] ?? 0);
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $subcategory_id = (int)($_POST['subcategory_id'] ?? 0);
    $amount         = (float)($_POST['amount'] ?? 0);
    $notes          = trim($_POST['notes'] ?? '');

    // Validaciones
    $okProj = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND company_id=?");
    $okProj->execute([$project_id, $company_id]);

    $okCat = $pdo->prepare("SELECT name FROM expenses_category WHERE id=? AND company_id=?");
    $okCat->execute([$category_id, $company_id]);
    $catName = $okCat->fetchColumn();

    $okSub = $pdo->prepare("SELECT name FROM expenses_subcategory WHERE id=? AND company_id=? AND category_id=?");
    $okSub->execute([$subcategory_id, $company_id, $category_id]);
    $subName = $okSub->fetchColumn();

    if (!$okProj->fetchColumn()) {
        $error = "Proyecto inválido.";
    } elseif (!$catName) {
        $error = "Categoría inválida.";
    } elseif (!$subName) {
        $error = "Subcategoría inválida.";
    } elseif ($amount < 0) {
        $error = "El monto no puede ser negativo.";
    }

    if (empty($error)) {
        // Actualiza. Mantenemos también las columnas de texto por compatibilidad.
        $upd = $pdo->prepare("
            UPDATE budgets
               SET project_id     = ?,
                   category_id    = ?,
                   subcategory_id = ?,
                   amount         = ?,
                   notes          = ?,
                   category       = ?,   -- copia textual
                   subcategory    = ?    -- copia textual
             WHERE id = ? AND company_id = ?
        ");
        $upd->execute([
            $project_id,
            $category_id,
            $subcategory_id,
            $amount,
            $notes,
            $catName,
            $subName,
            $id,
            $company_id
        ]);

        header("Location: budgets.php?project_id={$project_id}");
        exit();
    } else {
        // Mantén lo capturado para re-renderizar el form con errores
        $budget['project_id']     = $project_id;
        $budget['category_id']    = $category_id;
        $budget['subcategory_id'] = $subcategory_id;
        $budget['amount']         = $amount;
        $budget['notes']          = $notes;
    }
}

// ==========================
// 3) Catálogos para selects
// ==========================
$projectsStmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$projectsStmt->execute([$company_id]);
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $pdo->prepare("SELECT id, name FROM expenses_category WHERE company_id = ? ORDER BY name");
$catStmt->execute([$company_id]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Valores actuales
$currentProjectId     = (int)$budget['project_id'];
$currentCategoryId    = isset($budget['category_id']) ? (int)$budget['category_id'] : 0;
$currentSubcategoryId = isset($budget['subcategory_id']) ? (int)$budget['subcategory_id'] : 0;

include 'header.php';
?>

<h2 class="mb-4">✏️ Editar Partida Presupuestal</h2>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card shadow mb-4">
  <div class="card-body">
    <form method="POST">
      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="project_id" class="form-select" required>
            <?php foreach ($projects as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= $currentProjectId===(int)$p['id'] ? 'selected' : '' ?>>
                <?= h($p['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Categoría</label>
          <select name="category_id" id="category_id" class="form-select" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $currentCategoryId===(int)$c['id'] ? 'selected' : '' ?>>
                <?= h($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Subcategoría</label>
          <select name="subcategory_id" id="subcategory_id" class="form-select" required>
            <option value="">-- Selecciona categoría primero --</option>
          </select>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-6">
          <label class="form-label">Monto</label>
          <input type="number" step="0.01" min="0" name="amount" class="form-control"
                 value="<?= h(number_format((float)$budget['amount'], 2, '.', '')) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Notas</label>
          <input type="text" name="notes" class="form-control" value="<?= h($budget['notes']) ?>">
        </div>
      </div>

      <button type="submit" class="btn btn-primary">Guardar cambios</button>
      <a href="budgets.php?project_id=<?= (int)$currentProjectId ?>" class="btn btn-outline-secondary ms-2">Cancelar</a>
    </form>
  </div>
</div>

<!-- JS: solo jQuery para la carga dependiente -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
async function loadSubcategories(catId, preselectId = null){
  const subSel = document.getElementById('subcategory_id');
  subSel.innerHTML = '<option value="">Cargando...</option>';
  if(!catId){ subSel.innerHTML = '<option value="">-- Selecciona categoría primero --</option>'; return; }
  try{
    const r = await fetch('get_subcategories.php?category_id=' + encodeURIComponent(catId), {credentials:'same-origin'});
    const data = await r.json();
    let opts = '<option value="">-- Seleccionar --</option>';
    for (const row of data){
      const sel = (preselectId && String(preselectId)===String(row.id)) ? 'selected' : '';
      opts += `<option value="${row.id}" ${sel}>${row.name}</option>`;
    }
    subSel.innerHTML = opts;
  }catch(e){
    subSel.innerHTML = '<option value="">Error al cargar</option>';
  }
}

document.getElementById('category_id').addEventListener('change', (e)=>{
  loadSubcategories(e.target.value, null);
});

// Precarga en edición
<?php if ($currentCategoryId): ?>
  loadSubcategories(<?= (int)$currentCategoryId ?>, <?= (int)$currentSubcategoryId ?>);
<?php endif; ?>
</script>

<?php include 'footer.php'; ?>
