<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php'; exit();
}
$company_id = (int)$_SESSION['company_id'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ==== Eliminar partida presupuestal ==== */
if (isset($_GET['delete_budget'])) {
    $budget_id = (int) $_GET['delete_budget'];
    $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ? AND company_id = ?");
    $stmt->execute([$budget_id, $company_id]);

    $redir = "budgets.php";
    if (!empty($_GET['project_id'])) $redir .= "?project_id=".(int)$_GET['project_id'];
    header("Location: $redir");
    exit();
}

/* ==== Proyectos de la empresa ==== */
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ==== Categorías para combo ==== */
$stmt = $pdo->prepare("SELECT id, name FROM expenses_category WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ==== Guardar presupuesto ==== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id      = (int)($_POST['project_id'] ?? 0);
    $category_id     = (int)($_POST['category_id'] ?? 0);
    $subcategory_id  = (int)($_POST['subcategory_id'] ?? 0);
    $amount          = (float)($_POST['amount'] ?? 0);
    $notes           = trim($_POST['notes'] ?? '');

    // Validaciones básicas
    $okProj = $pdo->prepare("SELECT 1 FROM projects WHERE id=? AND company_id=?");
    $okProj->execute([$project_id, $company_id]);

    $okCat = $pdo->prepare("SELECT name FROM expenses_category WHERE id=? AND company_id=?");
    $okCat->execute([$category_id, $company_id]);
    $catName = $okCat->fetchColumn();

    $okSub = $pdo->prepare("SELECT name FROM expenses_subcategory WHERE id=? AND company_id=? AND category_id=?");
    $okSub->execute([$subcategory_id, $company_id, $category_id]);
    $subName = $okSub->fetchColumn();

    if (!$okProj->fetchColumn()) {
        echo "<div class='alert alert-danger'>Proyecto inválido.</div>";
    } elseif (!$catName) {
        echo "<div class='alert alert-danger'>Categoría inválida.</div>";
    } elseif (!$subName) {
        echo "<div class='alert alert-danger'>Subcategoría inválida.</div>";
    } else {
        // Opcional: seguir manteniendo columnas de texto por compatibilidad
        $stmt = $pdo->prepare("
            INSERT INTO budgets
                (project_id, category_id, subcategory_id, amount, notes, company_id, category, subcategory)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $project_id, $category_id, $subcategory_id, $amount, $notes, $company_id,
            $catName, $subName // copias de texto (si no las quieres, quita las columnas del INSERT)
        ]);

        header("Location: budgets.php?project_id=".$project_id);
        exit();
    }
}

/* ==== Filtro por proyecto ==== */
$selectedProject = null;
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$budgets = [];

if ($project_id) {
    $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ? AND company_id = ?");
    $stmt->execute([$project_id, $company_id]);
    $selectedProject = $stmt->fetchColumn();

    if ($selectedProject) {
        // Mostrar con JOIN para obtener nombres desde tablas maestras
        $stmt = $pdo->prepare("
            SELECT b.id, b.amount, b.notes,
                   ec.name  AS category_name,
                   esc.name AS subcategory_name
            FROM budgets b
            LEFT JOIN expenses_category ec
                   ON ec.id = b.category_id AND ec.company_id = b.company_id
            LEFT JOIN expenses_subcategory esc
                   ON esc.id = b.subcategory_id AND esc.company_id = b.company_id
            WHERE b.project_id = ? AND b.company_id = ?
            ORDER BY ec.name, esc.name
        ");
        $stmt->execute([$project_id, $company_id]);
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<h2 class="mb-4">📅 Presupuestos por Proyecto</h2>

<!-- Formulario de presupuesto -->
<div class="card shadow mb-4">
  <div class="card-header">Agregar Partida Presupuestal</div>
  <div class="card-body">
    <form method="POST" action="budgets.php">
      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="project_id" class="form-select" required>
            <option value="">Selecciona un proyecto</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= (int)$proj['id'] ?>"
                <?= $project_id && $project_id===(int)$proj['id'] ? 'selected' : '' ?>>
                <?= h($proj['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Categoría</label>
          <select name="category_id" id="category_id" class="form-select" required>
            <option value="">-- Seleccionar --</option>
            <?php foreach ($allCategories as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
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
          <input type="number" step="0.01" name="amount" class="form-control" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Notas</label>
          <input type="text" name="notes" class="form-control">
        </div>
      </div>

      <button type="submit" class="btn btn-success">Guardar Presupuesto</button>
    </form>
  </div>
</div>

<!-- Encabezado del proyecto -->
<?php if ($selectedProject): ?>
  <div class="card shadow mb-4">
    <div class="card-header bg-dark text-white">
      <h5 class="mb-0">📊 Presupuesto para: <strong><?= h($selectedProject) ?></strong></h5>
    </div>
  </div>
<?php endif; ?>

<!-- Tabla de presupuesto -->
<?php if ($selectedProject && !empty($budgets)): ?>
  <div class="table-responsive">
    <table class="table table-bordered table-hover shadow-sm">
      <thead>
        <tr>
          <th>Categoría</th>
          <th>Subcategoría</th>
          <th>Monto</th>
          <th>Notas</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php $total = 0; foreach ($budgets as $b): $total += (float)$b['amount']; ?>
          <tr>
            <td><?= h($b['category_name'] ?? '—') ?></td>
            <td><?= h($b['subcategory_name'] ?? '—') ?></td>
            <td>$<?= number_format((float)$b['amount'], 2) ?></td>
            <td><?= h($b['notes']) ?></td>
            <td>
              <a href="edit_budget.php?id=<?= (int)$b['id'] ?>"
                 class="btn btn-sm btn-outline-primary">Editar</a>
              <a href="budgets.php?delete_budget=<?= (int)$b['id'] ?>&project_id=<?= (int)$project_id ?>"
                 class="btn btn-sm btn-outline-danger"
                 onclick="return confirm('¿Eliminar esta partida?')">Eliminar</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr class="table-dark">
          <td colspan="2" class="text-end"><strong>Total</strong></td>
          <td><strong>$<?= number_format($total, 2) ?></strong></td>
          <td colspan="2"></td>
        </tr>
      </tbody>
    </table>
  </div>
<?php elseif ($selectedProject): ?>
  <p class="text-muted">No hay partidas presupuestales aún para este proyecto.</p>
<?php endif; ?>

<script>
/* Redirección al elegir proyecto */
document.querySelector("select[name='project_id']").addEventListener("change", function() {
  const projectId = this.value;
  if (projectId) window.location.href = "budgets.php?project_id=" + projectId;
});

/* Cargar subcategorías dependientes */
async function loadSubcategories(catId){
  const subSel = document.getElementById('subcategory_id');
  subSel.innerHTML = '<option value="">Cargando...</option>';
  if(!catId){ subSel.innerHTML = '<option value="">-- Selecciona categoría primero --</option>'; return; }
  try{
    const r = await fetch('get_subcategories.php?category_id=' + encodeURIComponent(catId), {credentials:'same-origin'});
    const data = await r.json();
    let opts = '<option value="">-- Seleccionar --</option>';
    for (const row of data) opts += `<option value="${row.id}">${row.name}</option>`;
    subSel.innerHTML = opts;
  }catch(e){
    subSel.innerHTML = '<option value="">Error al cargar</option>';
  }
}
document.getElementById('category_id').addEventListener('change', (e)=> loadSubcategories(e.target.value));
</script>

<?php include 'footer.php'; ?>
